<?php

namespace App\Jobs;

use App\Models\Sweep;
use App\Services\Keys\KeyVault;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;

/** POSTs a signed event to the client's webhook. Verify with HMAC-SHA256 over "{timestamp}.{body}". */
class DeliverWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 6;

    public function __construct(public int $sweepId, public string $event) {}

    public function backoff(): array
    {
        return [30, 120, 600, 1800, 7200];
    }

    public function handle(KeyVault $vault): void
    {
        $sweep = Sweep::with('depositAddress.client')->findOrFail($this->sweepId);
        $client = $sweep->depositAddress->client;
        if (! $client?->webhook_url) {
            return;
        }

        $body = json_encode([
            'event' => $this->event,
            'sweep' => [
                'id' => $sweep->id, 'status' => $sweep->status, 'chain' => $sweep->depositAddress->chain,
                'address' => $sweep->depositAddress->address, 'external_ref' => $sweep->depositAddress->external_ref,
                'asset' => $sweep->asset, 'amount' => $sweep->amount, 'tx_hash' => $sweep->tx_hash,
                'confirmed_at' => $sweep->confirmed_at?->toIso8601String(),
            ],
        ]);
        $ts = (string) time();
        $secret = $vault->open($client->secret_envelope, "client:{$client->key_id}");

        Http::timeout(10)->withHeaders([
            'Content-Type' => 'application/json',
            'X-Sweeper-Timestamp' => $ts,
            'X-Sweeper-Signature' => hash_hmac('sha256', $ts.'.'.$body, $secret),
        ])->withBody($body, 'application/json')->post($client->webhook_url)->throw();

        $sweep->update(['webhook_sent_at' => now()]);
    }
}
