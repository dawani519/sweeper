<?php

namespace App\Console\Commands;

use App\Models\ApiClient;
use App\Services\Keys\KeyVault;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class ClientCreate extends Command
{
    protected $signature = 'sweeper:client:create {name} {--webhook= : HTTPS URL for sweep events}';

    protected $description = 'Create an API client (your website) and print its key id + secret once';

    public function handle(KeyVault $vault): int
    {
        $webhook = $this->option('webhook');
        if ($webhook && ! preg_match('#^https://#i', $webhook) && ! app()->isLocal()) {
            $this->error('Webhook URL must be https outside local development.');

            return self::FAILURE;
        }

        $keyId = 'swk_'.Str::lower(Str::random(24));
        $secret = bin2hex(random_bytes(32));
        ApiClient::create([
            'name' => $this->argument('name'), 'key_id' => $keyId, 'webhook_url' => $webhook,
            'secret_envelope' => $vault->seal($secret, "client:$keyId"),
        ]);

        $this->info("Key ID : $keyId");
        $this->info("Secret : $secret");
        $this->warn('Store the secret now; it cannot be shown again.');

        return self::SUCCESS;
    }
}
