<?php

namespace App\Providers;

use App\Auth\OtpPasswordBrokerManager;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // The framework's PasswordResetServiceProvider is deferred: it would
        // re-register `auth.password` on first resolution and clobber our OTP
        // manager. Force it eager here, then win the singleton.
        $this->app->register(\Illuminate\Auth\Passwords\PasswordResetServiceProvider::class);
        $this->app->singleton('auth.password', fn ($app) => new OtpPasswordBrokerManager($app));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
