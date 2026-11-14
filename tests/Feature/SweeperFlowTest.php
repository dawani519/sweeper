<?php

namespace Tests\Feature;

use App\Models\ApiClient;
use App\Models\DepositAddress;
use App\Models\Sweep;
use App\Services\Keys\KeyVault;
use App\Services\Sweeper\SweepService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SweeperFlowTest extends TestCase
{
    use RefreshDatabase;

    private const TREASURY = '0x5aAeb6053F3E94C9b9A09f33669435E7Ef1BeAed';

    private function client(): array
    {
        $secret = 'topsecret';
        $c = new ApiClient(['name' => 't', 'key_id' => 'swk_test']);
        $c->secret_envelope = app(KeyVault::class)->seal($secret, 'client:swk_test');
        $c->save();

        return [$c, $secret];
    }

    private function signed(string $method, string $uri, string $secret, array $body = [], ?int $ts = null)
    {
        $raw = $body ? json_encode($body) : '';
        $ts ??= time();

        return $this->call($method, $uri, [], [], [], [
            'HTTP_X_KEY_ID' => 'swk_test', 'HTTP_X_TIMESTAMP' => (string) $ts,
            'HTTP_X_SIGNATURE' => hash_hmac('sha256', "$ts.$method.$uri.$raw", $secret),
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
        ], $raw);
    }

    public function test_vault_binds_ciphertext_to_context(): void
    {
        $v = app(KeyVault::class);
        $env = $v->seal('abc', 'ctx-a');
        $this->assertSame('abc', $v->open($env, 'ctx-a'));
        $this->expectException(\RuntimeException::class);
        $v->open($env, 'ctx-b');
    }

    public function test_api_rejects_bad_signature_and_stale_timestamp(): void
    {
        [, $secret] = $this->client();
        $this->signed('GET', '/api/v1/addresses', 'wrong')->assertStatus(401);
        $this->signed('GET', '/api/v1/addresses', $secret, [], time() - 4000)->assertStatus(401);
        $this->signed('GET', '/api/v1/addresses', $secret)->assertOk();
    }

    public function test_create_address_stores_only_sealed_key(): void
    {
        [, $secret] = $this->client();
        config(['sweeper.chains.sepolia.treasury' => self::TREASURY]);

        $res = $this->signed('POST', '/api/v1/addresses', $secret, ['chain' => 'sepolia', 'external_ref' => 'order-9'])->assertCreated();
        $row = DepositAddress::firstOrFail();
        $this->assertSame($res->json('address'), $row->address);
        $this->assertStringNotContainsString('private', $res->getContent());

        $priv = app(KeyVault::class)->open($row->key_envelope, $row->vaultContext());
        $this->assertSame($row->address, \App\Support\Evm\Crypto::addressFromPrivate($priv));
    }

    public function test_native_sweep_builds_signs_and_broadcasts_to_treasury_only(): void
    {
        [$client] = $this->client();
        config([
            'sweeper.chains.sepolia.treasury' => self::TREASURY,
            'sweeper.chains.sepolia.rpc' => ['http://rpc.test'],
        ]);
        $kp = \App\Support\Evm\Crypto::generateKeypair();
        $addr = new DepositAddress(['api_client_id' => $client->id, 'chain' => 'sepolia', 'address' => $kp['address']]);
        $addr->key_envelope = app(KeyVault::class)->seal($kp['private'], $addr->vaultContext());
        $addr->save();

        $sent = null;
        Http::fake(function ($request) use (&$sent) {
            $m = $request['method'];
            if ($m === 'eth_sendRawTransaction') {
                $sent = $request['params'][0];
            }
            $r = match ($m) {
                'eth_getBalance' => '0xde0b6b3a7640000', // 1 ETH
                'eth_getBlockByNumber' => ['baseFeePerGas' => '0x3b9aca00'], // 1 gwei
                'eth_maxPriorityFeePerGas' => '0x59682f00', // 1.5 gwei
                'eth_getTransactionCount' => '0x5',
                'eth_sendRawTransaction' => '0x'.str_repeat('ab', 32),
                default => null,
            };

            return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => $r]);
        });

        app(SweepService::class)->scan($addr);

        $sweep = Sweep::firstOrFail();
        $this->assertSame('broadcast', $sweep->status, (string) $sweep->error);
        $this->assertNotNull($sent);
        $this->assertStringStartsWith('0x02', $sent);
        // Raw tx must contain the treasury address bytes as recipient.
        $this->assertStringContainsString(strtolower(substr(self::TREASURY, 2)), $sent);
        // amount = 1 ETH - 21000 * ((1*2 + 1.5) * 1.2 gwei)
        $this->assertSame((string) (10 ** 18 - 21000 * 4_200_000_000), $sweep->amount);
    }

    public function test_disabled_kill_switch_blocks_broadcast(): void
    {
        [$client] = $this->client();
        config(['sweeper.enabled' => false, 'sweeper.chains.sepolia.treasury' => self::TREASURY, 'sweeper.chains.sepolia.rpc' => ['http://rpc.test']]);
        $kp = \App\Support\Evm\Crypto::generateKeypair();
        $addr = new DepositAddress(['api_client_id' => $client->id, 'chain' => 'sepolia', 'address' => $kp['address']]);
        $addr->key_envelope = app(KeyVault::class)->seal($kp['private'], $addr->vaultContext());
        $addr->save();

        Http::fake(fn ($r) => Http::response(['result' => match ($r['method']) {
            'eth_getBalance' => '0xde0b6b3a7640000',
            'eth_getBlockByNumber' => ['baseFeePerGas' => '0x3b9aca00'],
            'eth_maxPriorityFeePerGas' => '0x59682f00',
            default => null,
        }]));

        app(SweepService::class)->scan($addr);
        $this->assertSame('ready', Sweep::firstOrFail()->status);
        Http::assertNotSent(fn ($r) => $r['method'] === 'eth_sendRawTransaction');
    }
}
