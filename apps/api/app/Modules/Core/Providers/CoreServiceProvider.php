<?php

namespace Modules\Core\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Core\Approval\ApprovalEngine;
use Modules\Core\Services\AuditService;
use Modules\Core\Services\NumberingService;

class CoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Shared services — satu instance per request (singleton aman: stateless)
        $this->app->singleton(NumberingService::class);
        $this->app->singleton(AuditService::class);
        $this->app->singleton(ApprovalEngine::class);
    }

    public function boot(): void
    {
        // Load routes & views modul Core bila ada
        // $this->loadRoutesFrom(__DIR__.'/../../routes/core.php');
    }
}
