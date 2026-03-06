<?php

namespace App\Providers;

use App\Services\FirebaseCredentialsService;
use Illuminate\Support\ServiceProvider;
use Kreait\Firebase\Factory;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(FirebaseCredentialsService::class, fn () => new FirebaseCredentialsService());

        $this->app->singleton('firebase.auth', function ($app) {
            /** @var FirebaseCredentialsService $firebaseCredentials */
            $firebaseCredentials = $app->make(FirebaseCredentialsService::class);
            $creds = $firebaseCredentials->credentials();
            return (new Factory)->withServiceAccount($creds)->createAuth();
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
