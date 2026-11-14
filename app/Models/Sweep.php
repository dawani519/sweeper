<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Sweep extends Model
{
    public const OPEN = ['funding', 'ready', 'broadcast'];

    protected $fillable = [
        'deposit_address_id', 'asset', 'token_address', 'amount', 'status',
        'funding_tx_hash', 'tx_hash', 'fee_wei', 'error', 'confirmed_at', 'webhook_sent_at',
    ];

    protected function casts(): array
    {
        return ['confirmed_at' => 'datetime', 'webhook_sent_at' => 'datetime'];
    }

    public function depositAddress(): BelongsTo
    {
        return $this->belongsTo(DepositAddress::class);
    }
}
