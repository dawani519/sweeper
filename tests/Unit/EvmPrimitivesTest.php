<?php

namespace Tests\Unit;

use App\Support\Evm\Crypto;
use App\Support\Evm\Eip1559Transaction;
use App\Support\Evm\Rlp;
use PHPUnit\Framework\TestCase;

class EvmPrimitivesTest extends TestCase
{
    public function test_keccak_empty_vector(): void
    {
        $this->assertSame('c5d2460186f7233c927e7db2dcc703c0e500b653ca82273b7bfad8045d85a470', bin2hex(Crypto::keccak('')));
    }

    public function test_known_private_key_yields_known_address(): void
    {
        // private key = 1 -> well-known address
        $priv = str_repeat('0', 63).'1';
        $this->assertSame('0x7E5F4552091A69125d5DfCb7b8C2659029395Bdf', Crypto::addressFromPrivate($priv));
    }

    public function test_eip55_checksum_vector(): void
    {
        $this->assertSame(
            '0x5aAeb6053F3E94C9b9A09f33669435E7Ef1BeAed',
            Crypto::checksum('0x5aaeb6053f3e94c9b9a09f33669435e7ef1beaed')
        );
    }

    public function test_rlp_vectors(): void
    {
        $this->assertSame('83646f67', bin2hex(Rlp::encode('dog')));
        $this->assertSame('c88363617483646f67', bin2hex(Rlp::encode(['cat', 'dog'])));
        $this->assertSame('c0', bin2hex(Rlp::encode([])));
        $this->assertSame('80', bin2hex(Rlp::encode(Rlp::int(0))));
        $this->assertSame('820400', bin2hex(Rlp::encode(Rlp::int(1024))));
        $this->assertSame('0f', bin2hex(Rlp::encode(Rlp::int(15))));
    }

    public function test_generated_keypair_is_consistent(): void
    {
        $kp = Crypto::generateKeypair();
        $this->assertSame(64, strlen($kp['private']));
        $this->assertSame($kp['address'], Crypto::addressFromPrivate($kp['private']));
        $this->assertTrue(Crypto::isAddress($kp['address']));
    }

    public function test_transaction_signs_and_self_verifies(): void
    {
        $kp = Crypto::generateKeypair();
        $tx = new Eip1559Transaction(1, 7, '1000000000', '30000000000', 21000, $kp['address'], '1000');
        $out = $tx->sign($kp['private'], $kp['address']);
        $this->assertStringStartsWith('0x02', $out['raw']);
        $this->assertSame(66, strlen($out['hash']));
    }
}
