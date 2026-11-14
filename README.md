# Sweeper

A custodial deposit-address service built with Laravel. Your website asks Sweeper for a deposit address. Sweeper generates the keypair itself, seals the private key with envelope encryption, watches the chain over JSON-RPC, and consolidates funds (the native coin and configured ERC-20 tokens) into your treasury address. Your site is notified by signed webhook.

**Scope:** Sweeper only sweeps addresses it generated. There is no endpoint to import a private key, and the destination address comes from configuration, never from request input.

## Stack

- Laravel 13, PHP 8.3+, MySQL
- PHP extension `gmp` (required for the cryptography)
- `kornrunner/keccak` and `simplito/elliptic-php` for Ethereum addresses and signing
- EVM chains only

## Setup

```bash
composer install
cp .env.example .env && php artisan key:generate
php -d extension=gmp artisan migrate
php artisan sweeper:client:create "My Site" --webhook=https://mysite.example/hooks/sweeper
php artisan sweeper:funder:set sepolia --generate     # only needed for ERC-20 sweeps
```

Set these in `.env`:

- `SEPOLIA_TREASURY` / `ETHEREUM_TREASURY` — your own treasury addresses
- RPC URLs (comma-separated for failover)
- `SWEEPER_KEK` — the key-encryption key. **Back it up: losing it loses every private key.**
- `SWEEPER_ENABLED` and `SWEEPER_DRY_RUN` — defaults are disabled and dry-run. Set `SWEEPER_ENABLED=true` and `SWEEPER_DRY_RUN=false` only when ready.

Run the scheduler (`php artisan schedule:work`, which calls `sweeper:tick` every minute) and a queue worker (`php artisan queue:work`) for webhooks.

## API

Requests are HMAC-signed. Send `X-Key-Id`, `X-Timestamp` (unix seconds) and `X-Signature` = hex `HMAC-SHA256(secret, "{ts}.{METHOD}.{path+query}.{raw body}")`. There is a 5-minute replay window.

| Method | Path | Purpose |
|---|---|---|
| `POST` | `/api/v1/addresses` | Create a deposit address: `{"chain":"sepolia","external_ref":"order-9"}` |
| `GET` | `/api/v1/addresses?external_ref=order-9` | Look up by your reference |
| `GET` | `/api/v1/addresses/{id}` | Address detail with sweep history |

Webhook events `sweep.confirmed` and `sweep.failed` carry `X-Sweeper-Signature` = HMAC-SHA256 of `"{X-Sweeper-Timestamp}.{body}"`.

```php
$ts = time();
$body = json_encode(['chain' => 'sepolia', 'external_ref' => 'order-9']);
$sig = hash_hmac('sha256', "$ts.POST./api/v1/addresses.$body", $secret);
Http::withHeaders(['X-Key-Id' => $keyId, 'X-Timestamp' => $ts, 'X-Signature' => $sig])
    ->withBody($body, 'application/json')
    ->post('https://sweeper.example/api/v1/addresses');
```

## Key management

The `KeyVault` interface has a driver chosen by `SWEEPER_VAULT`:

- `local` — a per-record random AES-256-GCM data key wrapped by `SWEEPER_KEK`. The ciphertext is bound (AAD) to chain and address, so copying it to another row fails.
- `aws` — AWS KMS `GenerateDataKey` / `Decrypt` (`composer require aws/aws-sdk-php`).

## Testing

```bash
php -d extension=gmp vendor/bin/phpunit
```

Tests use a separate `sweeper_testing` MySQL database. 11 tests cover Keccak / address / EIP-55 / RLP vectors, EIP-1559 signing with sender recovery, vault round-trip and context binding, HMAC authentication (bad signature, stale timestamp), address creation, a native sweep against a mocked RPC, and the kill switch.

## Not verified

- Any live-network broadcast. Try Sepolia with dust first.
- The ERC-20 and gas-funding path (written, not tested).
- The AWS KMS driver against a live key.

## Limitations

No transaction replacement (RBF) for stuck transactions; EVM chains only; `dry_run` rows accumulate each tick; keep the gas-funder balance small. Run behind HTTPS and restrict access to the database and the KEK.
