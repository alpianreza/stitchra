<?php

namespace Modules\Qc\Providers;

use Illuminate\Support\ServiceProvider;

class QcServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Route gabungan QC/Packing/Shipping/Subcon (satu file Phase 7)
        $this->loadRoutesFrom(__DIR__.'/../routes/qc.php');
    }
}
