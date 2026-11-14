<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ApiClient extends Model
{
    protected $fillable = ['name', 'key_id', 'secret_envelope', 'webhook_url', 'active'];

    protected $hidden = ['secret_envelope'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(DepositAddress::class);
    }
}
