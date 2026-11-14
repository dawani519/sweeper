<?php

namespace App\Services\Keys;

/**
 * Envelope encryption for secrets at rest. The returned envelope is an opaque
 * string safe to store in the database; plaintext never touches disk or logs.
 */
interface KeyVault
{
    public function seal(string $plaintext, string $context): string;

    public function open(string $envelope, string $context): string;
}
