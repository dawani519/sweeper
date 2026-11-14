<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SystemKey extends Model
{
    protected $fillable = ['name', 'address', 'key_envelope'];

    protected $hidden = ['key_envelope'];

    public function vaultContext(): string
    {
        return "system:{$this->name}:{$this->address}";
    }
}
