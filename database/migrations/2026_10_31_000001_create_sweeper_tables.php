<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_clients', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('key_id', 40)->unique();
            $t->text('secret_envelope');
            $t->string('webhook_url')->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
        });

        Schema::create('deposit_addresses', function (Blueprint $t) {
            $t->id();
            $t->foreignId('api_client_id')->constrained()->cascadeOnDelete();
            $t->string('chain', 32);
            $t->string('address', 42);
            $t->text('key_envelope');
            $t->string('external_ref', 120)->nullable()->comment('Your own id, e.g. user/order id');
            $t->boolean('active')->default(true);
            $t->timestamp('last_scanned_at')->nullable();
            $t->timestamps();
            $t->unique(['chain', 'address']);
            $t->index(['api_client_id', 'external_ref']);
        });

        Schema::create('sweeps', function (Blueprint $t) {
            $t->id();
            $t->foreignId('deposit_address_id')->constrained()->cascadeOnDelete();
            $t->string('asset', 20);                       // native symbol or token symbol
            $t->string('token_address', 42)->nullable();
            $t->string('amount', 80);                      // base units, decimal string
            $t->string('status', 20)->index();             // funding|ready|broadcast|confirmed|failed|dry_run
            $t->string('funding_tx_hash', 66)->nullable();
            $t->string('tx_hash', 66)->nullable()->index();
            $t->string('fee_wei', 80)->nullable();
            $t->text('error')->nullable();
            $t->timestamp('confirmed_at')->nullable();
            $t->timestamp('webhook_sent_at')->nullable();
            $t->timestamps();
        });

        Schema::create('system_keys', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();                  // e.g. gas-funder:sepolia
            $t->string('address', 42);
            $t->text('key_envelope');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_keys');
        Schema::dropIfExists('sweeps');
        Schema::dropIfExists('deposit_addresses');
        Schema::dropIfExists('api_clients');
    }
};
