<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DepositAddress extends Model
{
    protected $fillable = ['api_client_id', 'chain', 'address', 'key_envelope', 'external_ref', 'active', 'last_scanned_at'];

    protected $hidden = ['key_envelope'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'last_scanned_at' => 'datetime'];
    }

    /** AAD binding: ciphertext only decrypts for this chain+address. */
    public function vaultContext(): string
    {
        return "deposit:{$this->chain}:{$this->address}";
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(ApiClient::class, 'api_client_id');
    }

    public function sweeps(): HasMany
    {
        return $this->hasMany(Sweep::class);
    }
}
