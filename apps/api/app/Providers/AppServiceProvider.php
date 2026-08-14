<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Core\Providers\CoreServiceProvider;
use Modules\Cutting\Providers\CuttingServiceProvider;
use Modules\Finance\Providers\FinanceServiceProvider;
use Modules\Inventory\Providers\InventoryServiceProvider;
use Modules\MasterData\Providers\MasterDataServiceProvider;
use Modules\Packing\Providers\PackingServiceProvider;
use Modules\Planning\Providers\PlanningServiceProvider;
use Modules\ProductDev\Providers\ProductDevServiceProvider;
use Modules\Production\Providers\ProductionServiceProvider;
use Modules\Purchasing\Providers\PurchasingServiceProvider;
use Modules\Qc\Providers\QcServiceProvider;
use Modules\Reporting\Providers\ReportingServiceProvider;
use Modules\Sales\Providers\SalesServiceProvider;
use Modules\Shipping\Providers\ShippingServiceProvider;
use Modules\ShopFloor\Providers\ShopFloorServiceProvider;
use Modules\Subcon\Providers\SubconServiceProvider;

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
        $this->app->register(QcServiceProvider::class);
        $this->app->register(PackingServiceProvider::class);
        $this->app->register(ShippingServiceProvider::class);
        $this->app->register(SubconServiceProvider::class);
        $this->app->register(FinanceServiceProvider::class);
        $this->app->register(ReportingServiceProvider::class);
        $this->app->register(InventoryServiceProvider::class);
    }

    public function boot(): void
    {
        //
    }
}
