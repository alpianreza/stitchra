<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Core\Providers\CoreServiceProvider;
use Modules\MasterData\Providers\MasterDataServiceProvider;
use Modules\ProductDev\Providers\ProductDevServiceProvider;
use Modules\Sales\Providers\SalesServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->register(CoreServiceProvider::class);
        $this->app->register(MasterDataServiceProvider::class);
        $this->app->register(SalesServiceProvider::class);
        $this->app->register(ProductDevServiceProvider::class);
    }

    public function boot(): void
    {
        //
    }
}
