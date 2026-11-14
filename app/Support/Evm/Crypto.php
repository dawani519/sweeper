<?php

namespace App\Support\Evm;

use Elliptic\EC;
use InvalidArgumentException;
use kornrunner\Keccak;

final class Crypto
{
    private static ?EC $ec = null;

    private static function ec(): EC
    {
        return self::$ec ??= new EC('secp256k1');
    }

    public static function keccak(string $raw): string
    {
        return hex2bin(Keccak::hash($raw, 256));
    }

    /** @return array{private: string, address: string} private key as 64-char hex */
    public static function generateKeypair(): array
    {
        $key = self::ec()->genKeyPair(['entropy' => bin2hex(random_bytes(64))]);
        $priv = str_pad($key->getPrivate('hex'), 64, '0', STR_PAD_LEFT);

        return ['private' => $priv, 'address' => self::addressFromPrivate($priv)];
    }

    public static function addressFromPrivate(string $privHex): string
    {
        $pub = self::ec()->keyFromPrivate($privHex, 'hex')->getPublic(false, 'hex'); // 04||X||Y

        return self::checksum(substr(bin2hex(self::keccak(hex2bin(substr($pub, 2)))), 24));
    }

    /** @return array{r: string, s: string, v: int} r,s as 32-byte raw strings, v = recovery id (0/1) */
    public static function sign(string $hash32, string $privHex): array
    {
        $sig = self::ec()->keyFromPrivate($privHex, 'hex')->sign(bin2hex($hash32), ['canonical' => true]);

        return [
            'r' => hex2bin(str_pad($sig->r->toString(16), 64, '0', STR_PAD_LEFT)),
            's' => hex2bin(str_pad($sig->s->toString(16), 64, '0', STR_PAD_LEFT)),
            'v' => (int) $sig->recoveryParam,
        ];
    }

    /** Recover the signer address from a hash and signature (used for self-verification). */
    public static function recoverAddress(string $hash32, array $sig): string
    {
        $pub = self::ec()->recoverPubKey(
            bin2hex($hash32),
            ['r' => bin2hex($sig['r']), 's' => bin2hex($sig['s'])],
            $sig['v']
        );

        return self::checksum(substr(bin2hex(self::keccak(hex2bin(substr($pub->encode('hex'), 2)))), 24));
    }

    public static function isAddress(string $a): bool
    {
        return (bool) preg_match('/^0x[0-9a-fA-F]{40}$/', $a);
    }

    /** EIP-55 checksum encoding. */
    public static function checksum(string $addr): string
    {
        $a = strtolower(preg_replace('/^0x/i', '', $addr));
        if (! preg_match('/^[0-9a-f]{40}$/', $a)) {
            throw new InvalidArgumentException('Invalid address');
        }
        $hash = Keccak::hash($a, 256);
        $out = '';
        foreach (str_split($a) as $i => $c) {
            $out .= ctype_alpha($c) && hexdec($hash[$i]) >= 8 ? strtoupper($c) : $c;
        }

        return '0x'.$out;
    }
}
