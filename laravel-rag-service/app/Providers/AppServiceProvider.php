<?php

namespace App\Providers;

use App\Services\RagService;
use App\Services\PromptManager;
use App\Repositories\QdrantRepository;
use Illuminate\Support\ServiceProvider;
use App\Repositories\Interfaces\QdrantRepositoryInterface;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(PromptManager::class, function ($app) {
            return new PromptManager();
        });

        $this->app->bind(RagService::class, function ($app) {
            return new RagService($app->make(PromptManager::class));
        });

        $this->app->bind(QdrantRepositoryInterface::class, QdrantRepository::class);

    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
