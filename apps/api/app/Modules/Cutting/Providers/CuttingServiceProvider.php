<?php

namespace Modules\Cutting\Providers;

use Illuminate\Support\ServiceProvider;

class CuttingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../../routes/cutting.php');
    }
}
