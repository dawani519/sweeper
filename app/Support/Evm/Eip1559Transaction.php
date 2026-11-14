<?php

namespace App\Support\Evm;

use RuntimeException;

/** EIP-1559 (type 0x02) transaction. */
final class Eip1559Transaction
{
    public function __construct(
        public readonly int $chainId,
        public readonly int $nonce,
        public readonly string $maxPriorityFeePerGas, // wei, decimal string
        public readonly string $maxFeePerGas,
        public readonly int $gasLimit,
        public readonly string $to,
        public readonly string $value = '0',
        public readonly string $data = '', // raw bytes
    ) {}

    private function fields(): array
    {
        return [
            Rlp::int($this->chainId),
            Rlp::int($this->nonce),
            Rlp::int($this->maxPriorityFeePerGas),
            Rlp::int($this->maxFeePerGas),
            Rlp::int($this->gasLimit),
            hex2bin(substr($this->to, 2)),
            Rlp::int($this->value),
            $this->data,
            [], // access list
        ];
    }

    /** @return array{raw: string, hash: string} 0x-prefixed signed raw tx and its tx hash */
    public function sign(string $privHex, string $expectedFrom): array
    {
        $digest = Crypto::keccak("\x02".Rlp::encode($this->fields()));
        $sig = Crypto::sign($digest, $privHex);

        // Never broadcast something that does not recover to the expected sender.
        if (Crypto::recoverAddress($digest, $sig) !== Crypto::checksum($expectedFrom)) {
            throw new RuntimeException('Signature self-check failed');
        }

        $signed = "\x02".Rlp::encode([
            ...$this->fields(),
            Rlp::int($sig['v']),
            ltrim($sig['r'], "\x00"),
            ltrim($sig['s'], "\x00"),
        ]);

        return ['raw' => '0x'.bin2hex($signed), 'hash' => '0x'.bin2hex(Crypto::keccak($signed))];
    }
}
