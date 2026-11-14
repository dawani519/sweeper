<?php

namespace App\Providers;

use App\Services\Keys\AwsKmsVault;
use App\Services\Keys\KeyVault;
use App\Services\Keys\LocalEnvelopeVault;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(KeyVault::class, function () {
            return match (config('sweeper.vault.driver')) {
                'aws' => new AwsKmsVault(config('sweeper.vault.aws.key_id'), config('sweeper.vault.aws.region')),
                default => new LocalEnvelopeVault(config('sweeper.vault.local.kek'), config('sweeper.vault.local.kek_id')),
            };
        });
    }

    public function boot(): void {}
}
