<?php

namespace Modules\Production\Providers;

use Illuminate\Support\ServiceProvider;

class ProductionServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../../routes/production.php');
        // Route MO (Phase 5) ada di Modules/Planning/routes/planning.php
    }
}
