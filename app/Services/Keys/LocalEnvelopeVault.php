<?php

namespace App\Services\Keys;

use RuntimeException;

/**
 * Per-record random data key (DEK) encrypts the secret with AES-256-GCM; the DEK is
 * wrapped by the key-encryption key (KEK) from the environment. `context` is bound
 * as AAD, so a ciphertext copied to another row/address will not decrypt.
 *
 * Envelope: base64(json{v, kek, w: wrappedDek, c: ciphertext}) with each part base64(iv|tag|ct).
 */
final class LocalEnvelopeVault implements KeyVault
{
    public function __construct(private ?string $kekB64, private string $kekId = 'v1') {}

    private function kek(): string
    {
        $kek = base64_decode((string) $this->kekB64, true);
        if ($kek === false || strlen($kek) !== 32) {
            throw new RuntimeException('SWEEPER_KEK must be base64 of exactly 32 bytes');
        }

        return $kek;
    }

    private function encrypt(string $key, string $plain, string $aad): string
    {
        $iv = random_bytes(12);
        $ct = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, $aad, 16);
        if ($ct === false) {
            throw new RuntimeException('Encryption failed');
        }

        return base64_encode($iv.$tag.$ct);
    }

    private function decrypt(string $key, string $blob, string $aad): string
    {
        $raw = base64_decode($blob, true);
        if ($raw === false || strlen($raw) < 28) {
            throw new RuntimeException('Malformed ciphertext');
        }
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16), $aad);
        if ($plain === false) {
            throw new RuntimeException('Decryption failed (wrong key, wrong context, or tampered data)');
        }

        return $plain;
    }

    public function seal(string $plaintext, string $context): string
    {
        $dek = random_bytes(32);
        $env = [
            'v' => 1,
            'kek' => $this->kekId,
            'w' => $this->encrypt($this->kek(), $dek, 'dek|'.$context),
            'c' => $this->encrypt($dek, $plaintext, 'data|'.$context),
        ];

        return base64_encode(json_encode($env));
    }

    public function open(string $envelope, string $context): string
    {
        $env = json_decode((string) base64_decode($envelope, true), true);
        if (! is_array($env) || ! isset($env['w'], $env['c'])) {
            throw new RuntimeException('Malformed envelope');
        }
        $dek = $this->decrypt($this->kek(), $env['w'], 'dek|'.$context);

        return $this->decrypt($dek, $env['c'], 'data|'.$context);
    }
}
