<?php

return [
    // Global kill switch. When false, nothing is signed or broadcast.
    'enabled' => env('SWEEPER_ENABLED', false),

    // Build and log sweeps without signing or broadcasting.
    'dry_run' => env('SWEEPER_DRY_RUN', true),

    /*
     | Key management. Drivers: "local" (AES-256-GCM envelope encryption, KEK from
     | env) or "aws" (AWS KMS GenerateDataKey/Decrypt; needs aws/aws-sdk-php).
     */
    'vault' => [
        'driver' => env('SWEEPER_VAULT', 'local'),
        'local' => [
            // base64 of 32 random bytes: php -r "echo base64_encode(random_bytes(32));"
            'kek' => env('SWEEPER_KEK'),
            'kek_id' => env('SWEEPER_KEK_ID', 'v1'),
        ],
        'aws' => [
            'key_id' => env('SWEEPER_AWS_KMS_KEY_ID'),
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
        ],
    ],

    'api' => [
        'timestamp_tolerance' => 300,
    ],

    /*
     | Chains. The treasury address is the ONLY place funds can be swept to; it is
     | configured here and never accepted from API input.
     */
    'chains' => [
        'sepolia' => [
            'chain_id' => 11155111,
            'symbol' => 'ETH',
            'rpc' => array_values(array_filter(explode(',', env('SEPOLIA_RPC', 'https://ethereum-sepolia-rpc.publicnode.com')))),
            'treasury' => env('SEPOLIA_TREASURY'),
            'confirmations' => 3,
            'min_native_sweep_wei' => '100000000000000', // 0.0001 ETH
            'gas_bump_percent' => 20,
            'max_fee_cap_gwei' => 200,
            'tokens' => [
                // 'USDC' => ['address' => '0x...', 'decimals' => 6, 'min_sweep' => '1000000'],
            ],
        ],
        'ethereum' => [
            'chain_id' => 1,
            'symbol' => 'ETH',
            'rpc' => array_values(array_filter(explode(',', env('ETHEREUM_RPC', 'https://ethereum-rpc.publicnode.com')))),
            'treasury' => env('ETHEREUM_TREASURY'),
            'confirmations' => 12,
            'min_native_sweep_wei' => '5000000000000000', // 0.005 ETH
            'gas_bump_percent' => 20,
            'max_fee_cap_gwei' => 150,
            'tokens' => [
                'USDC' => ['address' => '0xA0b86991c6218b36c1d19D4a2e9Eb0cE3606eB48', 'decimals' => 6, 'min_sweep' => '10000000'],
                'USDT' => ['address' => '0xdAC17F958D2ee523a2206206994597C13D831ec7', 'decimals' => 6, 'min_sweep' => '10000000'],
            ],
        ],
    ],
];
