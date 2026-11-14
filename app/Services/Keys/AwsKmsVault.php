<?php

namespace App\Services\Keys;

use RuntimeException;

/**
 * AWS KMS envelope encryption: KMS generates the data key (the KEK never leaves KMS),
 * the secret is AES-256-GCM encrypted locally, and only the KMS-wrapped data key is stored.
 *
 * Requires `composer require aws/aws-sdk-php`. NOTE: this driver has not been exercised
 * against a live KMS key in this repo; test it against a dev key before relying on it.
 */
final class AwsKmsVault implements KeyVault
{
    private object $kms;

    public function __construct(private string $keyId, string $region)
    {
        if (! class_exists(\Aws\Kms\KmsClient::class)) {
            throw new RuntimeException('aws/aws-sdk-php is not installed');
        }
        $this->kms = new \Aws\Kms\KmsClient(['version' => '2014-11-01', 'region' => $region]);
    }

    public function seal(string $plaintext, string $context): string
    {
        $dk = $this->kms->generateDataKey([
            'KeyId' => $this->keyId,
            'KeySpec' => 'AES_256',
            'EncryptionContext' => ['ctx' => $context],
        ]);
        $iv = random_bytes(12);
        $ct = openssl_encrypt($plaintext, 'aes-256-gcm', $dk['Plaintext'], OPENSSL_RAW_DATA, $iv, $tag, $context, 16);

        return base64_encode(json_encode([
            'v' => 1,
            'w' => base64_encode($dk['CiphertextBlob']),
            'c' => base64_encode($iv.$tag.$ct),
        ]));
    }

    public function open(string $envelope, string $context): string
    {
        $env = json_decode((string) base64_decode($envelope, true), true);
        if (! is_array($env)) {
            throw new RuntimeException('Malformed envelope');
        }
        $dek = $this->kms->decrypt([
            'CiphertextBlob' => base64_decode($env['w']),
            'EncryptionContext' => ['ctx' => $context],
        ])['Plaintext'];
        $raw = base64_decode($env['c']);
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $dek, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16), $context);
        if ($plain === false) {
            throw new RuntimeException('Decryption failed');
        }

        return $plain;
    }
}
