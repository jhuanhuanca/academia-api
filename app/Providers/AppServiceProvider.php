<?php

namespace App\Providers;

use App\Mail\BrevoTransport;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Mail::extend('brevo', function (array $config = []) {
            $key = (string) ($config['key'] ?? config('services.brevo.key') ?? '');
            if ($key === '') {
                throw new \RuntimeException('Falta BREVO_API_KEY en .env');
            }

            return new BrevoTransport($key);
        });
    }
}
