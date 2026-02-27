<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Kreait\Firebase\Factory;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton('firebase.auth', function () {
            $b64 = config('firebase.credentials_b64');
            if (! $b64) {
                throw new \RuntimeException('FIREBASE_CREDENTIALS_B64 missing');
            }

            $json = base64_decode($b64, true);
            if ($json === false) {
                throw new \RuntimeException('FIREBASE_CREDENTIALS_B64 invalid base64');
            }

            $creds = json_decode($json, true);
            if (! is_array($creds)) {
                throw new \RuntimeException('Firebase credentials JSON invalid');
            }

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
