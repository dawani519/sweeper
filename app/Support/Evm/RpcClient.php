<?php

namespace App\Support\Evm;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/** JSON-RPC client with ordered failover across several endpoints. */
final class RpcClient
{
    /** @param string[] $urls */
    public function __construct(private array $urls, private int $timeout = 15) {}

    public static function forChain(string $chain): self
    {
        $urls = config("sweeper.chains.$chain.rpc");
        if (! $urls) {
            throw new RuntimeException("No RPC configured for chain $chain");
        }

        return new self($urls);
    }

    public function call(string $method, array $params = []): mixed
    {
        $last = null;
        foreach ($this->urls as $url) {
            try {
                $res = Http::timeout($this->timeout)->retry(2, 250, throw: false)
                    ->post($url, ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params]);
                if (! $res->successful()) {
                    $last = new RuntimeException("RPC HTTP {$res->status()} from $url");

                    continue;
                }
                $json = $res->json();
                if (isset($json['error'])) {
                    // A protocol-level error is an answer, not an outage; don't fail over.
                    throw new RpcException($json['error']['message'] ?? 'RPC error', (int) ($json['error']['code'] ?? 0));
                }

                return $json['result'] ?? null;
            } catch (RpcException $e) {
                throw $e;
            } catch (\Throwable $e) {
                $last = $e;
            }
        }
        throw new RuntimeException('All RPC endpoints failed: '.$last?->getMessage());
    }

    private static function dec(?string $hex): string
    {
        return gmp_strval(gmp_init(substr((string) $hex, 2) ?: '0', 16));
    }

    public function nativeBalance(string $addr): string
    {
        return self::dec($this->call('eth_getBalance', [$addr, 'latest']));
    }

    public function tokenBalance(string $token, string $holder): string
    {
        $data = '0x70a08231'.str_pad(substr(strtolower($holder), 2), 64, '0', STR_PAD_LEFT);

        return self::dec($this->call('eth_call', [['to' => $token, 'data' => $data], 'latest']));
    }

    public function nonce(string $addr): int
    {
        return (int) self::dec($this->call('eth_getTransactionCount', [$addr, 'pending']));
    }

    /** @return array{priority: string, max: string} wei */
    public function fees(int $bumpPercent, int $capGwei): array
    {
        $block = $this->call('eth_getBlockByNumber', ['latest', false]);
        $base = gmp_init(self::dec($block['baseFeePerGas'] ?? '0x0'));
        $tip = gmp_init(self::dec($this->call('eth_maxPriorityFeePerGas')));
        $max = gmp_div_q(($base * 2 + $tip) * (100 + $bumpPercent), 100);
        $cap = gmp_init($capGwei) * gmp_init('1000000000');
        if ($max > $cap) {
            throw new RuntimeException('Network fee above configured cap; deferring sweep');
        }

        return ['priority' => gmp_strval($tip), 'max' => gmp_strval($max)];
    }

    public function estimateGas(array $tx): int
    {
        return (int) self::dec($this->call('eth_estimateGas', [$tx]));
    }

    public function sendRaw(string $rawHex): string
    {
        return $this->call('eth_sendRawTransaction', [$rawHex]);
    }

    public function receipt(string $hash): ?array
    {
        return $this->call('eth_getTransactionReceipt', [$hash]);
    }

    public function blockNumber(): int
    {
        return (int) self::dec($this->call('eth_blockNumber'));
    }
}
