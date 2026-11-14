<?php

namespace App\Support\Evm;

use InvalidArgumentException;

/** Minimal RLP encoder. Inputs are raw byte strings or (nested) arrays of them. */
final class Rlp
{
    public static function encode(string|array $item): string
    {
        if (is_array($item)) {
            $payload = implode('', array_map(fn ($i) => self::encode($i), $item));

            return self::prefix(strlen($payload), 0xc0).$payload;
        }

        if (strlen($item) === 1 && ord($item) < 0x80) {
            return $item;
        }

        return self::prefix(strlen($item), 0x80).$item;
    }

    /** Minimal big-endian bytes of a non-negative integer (0 => empty string). */
    public static function int(int|string|\GMP $value): string
    {
        $n = $value instanceof \GMP ? $value : gmp_init($value);
        if (gmp_cmp($n, 0) < 0) {
            throw new InvalidArgumentException('RLP cannot encode negative integers');
        }
        if (gmp_cmp($n, 0) === 0) {
            return '';
        }
        $hex = gmp_strval($n, 16);

        return hex2bin(strlen($hex) % 2 ? '0'.$hex : $hex);
    }

    private static function prefix(int $len, int $offset): string
    {
        if ($len <= 55) {
            return chr($offset + $len);
        }
        $lenBytes = self::int($len);

        return chr($offset + 55 + strlen($lenBytes)).$lenBytes;
    }
}
