<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Core\Providers\CoreServiceProvider;
use Modules\MasterData\Providers\MasterDataServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->register(CoreServiceProvider::class);
        $this->app->register(MasterDataServiceProvider::class);
    }

    public function boot(): void
    {
        //
    }
}
