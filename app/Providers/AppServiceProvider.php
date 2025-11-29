<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Registrar el servicio de conversión DOCX a PDF
        $this->app->singleton(\App\Services\DocxToPdfCloudConvertService::class, function ($app) {
            return new \App\Services\DocxToPdfCloudConvertService();
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
    }
}
