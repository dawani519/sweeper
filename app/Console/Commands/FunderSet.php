<?php

namespace App\Console\Commands;

use App\Models\SystemKey;
use App\Services\Keys\KeyVault;
use App\Support\Evm\Crypto;
use Illuminate\Console\Command;

class FunderSet extends Command
{
    protected $signature = 'sweeper:funder:set {chain} {--generate : Generate a new key instead of prompting}';

    protected $description = 'Set the gas-funder wallet (pays gas to sweep ERC-20 tokens). Keep its balance small.';

    public function handle(KeyVault $vault): int
    {
        $chain = $this->argument('chain');
        if (! config("sweeper.chains.$chain")) {
            $this->error('Unknown chain');

            return self::FAILURE;
        }

        $priv = $this->option('generate') ? Crypto::generateKeypair()['private'] : $this->secret('Private key (hex, input hidden)');
        $priv = strtolower(preg_replace('/^0x/', '', trim((string) $priv)));
        if (! preg_match('/^[0-9a-f]{64}$/', $priv)) {
            $this->error('Expected 64 hex characters');

            return self::FAILURE;
        }

        $row = new SystemKey(['name' => "gas-funder:$chain", 'address' => Crypto::addressFromPrivate($priv)]);
        $row->key_envelope = $vault->seal($priv, $row->vaultContext());
        SystemKey::where('name', $row->name)->delete();
        $row->save();

        $this->info("Funder for $chain: {$row->address}  (send a small amount of native coin to it)");

        return self::SUCCESS;
    }
}
