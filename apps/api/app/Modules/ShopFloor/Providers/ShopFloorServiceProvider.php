<?php

namespace Modules\ShopFloor\Providers;

use Illuminate\Support\ServiceProvider;

class ShopFloorServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../../routes/shopfloor.php');
    }
}
