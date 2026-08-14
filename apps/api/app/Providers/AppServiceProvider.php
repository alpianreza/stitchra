<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Core\Providers\CoreServiceProvider;
use Modules\Cutting\Providers\CuttingServiceProvider;
use Modules\MasterData\Providers\MasterDataServiceProvider;
use Modules\Planning\Providers\PlanningServiceProvider;
use Modules\ProductDev\Providers\ProductDevServiceProvider;
use Modules\Production\Providers\ProductionServiceProvider;
use Modules\Purchasing\Providers\PurchasingServiceProvider;
use Modules\Sales\Providers\SalesServiceProvider;
use Modules\ShopFloor\Providers\ShopFloorServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->register(CoreServiceProvider::class);
        $this->app->register(MasterDataServiceProvider::class);
        $this->app->register(SalesServiceProvider::class);
        $this->app->register(ProductDevServiceProvider::class);
        $this->app->register(PurchasingServiceProvider::class);
        $this->app->register(PlanningServiceProvider::class);
        $this->app->register(ProductionServiceProvider::class);
        $this->app->register(CuttingServiceProvider::class);
        $this->app->register(ShopFloorServiceProvider::class);
    }

    public function boot(): void
    {
        //
    }
}
