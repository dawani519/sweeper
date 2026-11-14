<?php

namespace App\Services\Sweeper;

use App\Jobs\DeliverWebhook;
use App\Models\DepositAddress;
use App\Models\Sweep;
use App\Models\SystemKey;
use App\Services\Keys\KeyVault;
use App\Support\Evm\Crypto;
use App\Support\Evm\Eip1559Transaction;
use App\Support\Evm\RpcClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Consolidates funds from system-generated deposit addresses into the configured
 * treasury. State machine per sweep: funding -> ready -> broadcast -> confirmed | failed.
 */
class SweepService
{
    private const TRANSFER = 'a9059cbb';

    public function __construct(private KeyVault $vault) {}

    /** Check one address and open/advance sweeps. Returns number of sweeps touched. */
    public function scan(DepositAddress $addr): int
    {
        $lock = Cache::lock("sweep-addr:{$addr->id}", 120);
        if (! $lock->get()) {
            return 0;
        }
        try {
            $chain = $this->chain($addr->chain);
            $rpc = RpcClient::forChain($addr->chain);
            $touched = 0;

            // Advance anything in flight first.
            foreach ($addr->sweeps()->whereIn('status', Sweep::OPEN)->get() as $sweep) {
                $this->advance($sweep, $rpc);
                $touched++;
            }

            $open = $addr->sweeps()->whereIn('status', Sweep::OPEN)->pluck('asset')->all();

            if (! in_array($chain['symbol'], $open, true)) {
                $bal = $rpc->nativeBalance($addr->address);
                if (gmp_cmp($bal, $chain['min_native_sweep_wei']) >= 0) {
                    $this->openNative($addr, $rpc, $bal);
                    $touched++;
                }
            }

            foreach ($chain['tokens'] as $symbol => $token) {
                if (in_array($symbol, $open, true)) {
                    continue;
                }
                $bal = $rpc->tokenBalance($token['address'], $addr->address);
                if (gmp_cmp($bal, $token['min_sweep']) >= 0) {
                    $sweep = Sweep::create([
                        'deposit_address_id' => $addr->id, 'asset' => $symbol,
                        'token_address' => $token['address'], 'amount' => $bal, 'status' => 'ready',
                    ]);
                    $this->advance($sweep, $rpc);
                    $touched++;
                }
            }

            $addr->forceFill(['last_scanned_at' => now()])->save();

            return $touched;
        } finally {
            $lock->release();
        }
    }

    private function openNative(DepositAddress $addr, RpcClient $rpc, string $balance): void
    {
        $chain = $this->chain($addr->chain);
        $fees = $rpc->fees($chain['gas_bump_percent'], $chain['max_fee_cap_gwei']);
        $fee = gmp_init(21000) * gmp_init($fees['max']);
        $amount = gmp_init($balance) - $fee;
        if ($amount <= 0) {
            return; // balance does not even cover gas
        }
        $sweep = Sweep::create([
            'deposit_address_id' => $addr->id, 'asset' => $chain['symbol'],
            'amount' => gmp_strval($amount), 'status' => 'ready',
        ]);
        $this->advance($sweep, $rpc);
    }

    public function advance(Sweep $sweep, ?RpcClient $rpc = null): void
    {
        $addr = $sweep->depositAddress;
        $rpc ??= RpcClient::forChain($addr->chain);
        try {
            match ($sweep->status) {
                'funding' => $this->checkFunding($sweep, $addr, $rpc),
                'ready' => $this->broadcast($sweep, $addr, $rpc),
                'broadcast' => $this->checkConfirmation($sweep, $addr, $rpc),
                default => null,
            };
        } catch (\Throwable $e) {
            // Transient problems (RPC outage, fee cap) leave the sweep in place to retry.
            Log::warning('sweep step failed', ['sweep' => $sweep->id, 'status' => $sweep->status, 'error' => $e->getMessage()]);
            $sweep->update(['error' => mb_substr($e->getMessage(), 0, 1000)]);
        }
    }

    private function broadcast(Sweep $sweep, DepositAddress $addr, RpcClient $rpc): void
    {
        $this->assertArmed();
        $chain = $this->chain($addr->chain);
        $treasury = $this->treasury($addr);
        $fees = $rpc->fees($chain['gas_bump_percent'], $chain['max_fee_cap_gwei']);

        if ($sweep->token_address === null) {
            $gas = 21000;
            $tx = ['to' => $treasury, 'value' => $sweep->amount, 'data' => ''];
        } else {
            $data = hex2bin(self::TRANSFER.$this->pad($treasury).$this->pad(gmp_strval(gmp_init($sweep->amount), 16), false));
            $est = $rpc->estimateGas(['from' => $addr->address, 'to' => $sweep->token_address, 'data' => '0x'.bin2hex($data)]);
            $gas = (int) ceil($est * 1.25);
            $need = gmp_init($gas) * gmp_init($fees['max']);
            $have = gmp_init($rpc->nativeBalance($addr->address));
            if ($have < $need) {
                $this->fundGas($sweep, $addr, $rpc, gmp_strval($need - $have));

                return;
            }
            $tx = ['to' => $sweep->token_address, 'value' => '0', 'data' => $data];
        }

        if (config('sweeper.dry_run')) {
            $sweep->update(['status' => 'dry_run', 'error' => null]);
            Log::info('sweep dry-run', $sweep->only(['id', 'asset', 'amount']) + ['to' => $treasury, 'gas' => $gas]);

            return;
        }

        $priv = $this->vault->open($addr->key_envelope, $addr->vaultContext());
        $signed = (new Eip1559Transaction(
            $chain['chain_id'], $rpc->nonce($addr->address), $fees['priority'], $fees['max'],
            $gas, $tx['to'], $tx['value'], $tx['data'],
        ))->sign($priv, $addr->address);
        unset($priv);

        $hash = $rpc->sendRaw($signed['raw']);
        $sweep->update([
            'status' => 'broadcast', 'tx_hash' => $hash, 'error' => null,
            'fee_wei' => gmp_strval(gmp_init($gas) * gmp_init($fees['max'])),
        ]);
    }

    private function fundGas(Sweep $sweep, DepositAddress $addr, RpcClient $rpc, string $needWei): void
    {
        $chain = $this->chain($addr->chain);
        $funder = SystemKey::where('name', "gas-funder:{$addr->chain}")->first()
            ?? throw new RuntimeException("No gas funder configured for {$addr->chain} (run sweeper:funder:set)");

        if (config('sweeper.dry_run')) {
            $sweep->update(['status' => 'dry_run', 'error' => "would fund {$needWei} wei from {$funder->address}"]);

            return;
        }

        $fees = $rpc->fees($chain['gas_bump_percent'], $chain['max_fee_cap_gwei']);
        $priv = $this->vault->open($funder->key_envelope, $funder->vaultContext());
        $signed = (new Eip1559Transaction(
            $chain['chain_id'], $rpc->nonce($funder->address), $fees['priority'], $fees['max'],
            21000, $addr->address, $needWei,
        ))->sign($priv, $funder->address);
        unset($priv);

        $sweep->update(['status' => 'funding', 'funding_tx_hash' => $rpc->sendRaw($signed['raw']), 'error' => null]);
    }

    private function checkFunding(Sweep $sweep, DepositAddress $addr, RpcClient $rpc): void
    {
        $r = $rpc->receipt($sweep->funding_tx_hash);
        if ($r === null) {
            return;
        }
        if (($r['status'] ?? '0x0') !== '0x1') {
            $sweep->update(['status' => 'failed', 'error' => 'gas funding transaction reverted']);

            return;
        }
        $sweep->update(['status' => 'ready']);
        $this->advance($sweep->refresh(), $rpc);
    }

    private function checkConfirmation(Sweep $sweep, DepositAddress $addr, RpcClient $rpc): void
    {
        $r = $rpc->receipt($sweep->tx_hash);
        if ($r === null) {
            return; // still pending (no replacement/RBF logic; see README limitations)
        }
        if (($r['status'] ?? '0x0') !== '0x1') {
            $sweep->update(['status' => 'failed', 'error' => 'sweep transaction reverted']);
            $this->notify($sweep, 'sweep.failed');

            return;
        }
        $confs = $rpc->blockNumber() - (int) hexdec($r['blockNumber']) + 1;
        if ($confs >= $this->chain($addr->chain)['confirmations']) {
            $sweep->update(['status' => 'confirmed', 'confirmed_at' => now(), 'error' => null]);
            $this->notify($sweep, 'sweep.confirmed');
        }
    }

    private function notify(Sweep $sweep, string $event): void
    {
        if ($sweep->depositAddress->client?->webhook_url) {
            DeliverWebhook::dispatch($sweep->id, $event);
        }
    }

    private function assertArmed(): void
    {
        if (! config('sweeper.enabled')) {
            throw new RuntimeException('Sweeper disabled (SWEEPER_ENABLED=false)');
        }
    }

    private function chain(string $name): array
    {
        return config("sweeper.chains.$name") ?? throw new RuntimeException("Unknown chain $name");
    }

    /** Treasury comes only from config and must be a valid address distinct from the source. */
    private function treasury(DepositAddress $addr): string
    {
        $t = $this->chain($addr->chain)['treasury'] ?? null;
        if (! $t || ! Crypto::isAddress($t)) {
            throw new RuntimeException("Treasury not configured for {$addr->chain}");
        }
        $t = Crypto::checksum($t);
        if ($t === Crypto::checksum($addr->address)) {
            throw new RuntimeException('Treasury equals deposit address');
        }

        return $t;
    }

    private function pad(string $hexOrAddr, bool $stripPrefix = true): string
    {
        $h = $stripPrefix ? strtolower(preg_replace('/^0x/', '', $hexOrAddr)) : $hexOrAddr;

        return str_pad($h, 64, '0', STR_PAD_LEFT);
    }
}
