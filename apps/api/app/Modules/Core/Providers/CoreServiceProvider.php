<?php

namespace Modules\Core\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Modules\Core\Approval\ApprovalEngine;
use Modules\Core\Approval\Events\DocumentApproved;
use Modules\Core\Listeners\HandleDocumentApproved;
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
        // BR-015: approval APPROVED → aksi domain (SO confirm, BOM approve, dst.)
        Event::listen(DocumentApproved::class, HandleDocumentApproved::class);

        $this->loadRoutesFrom(__DIR__.'/../routes/approvals.php');
    }
}
