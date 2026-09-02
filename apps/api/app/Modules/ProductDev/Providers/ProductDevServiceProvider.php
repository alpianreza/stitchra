<?php

namespace Modules\ProductDev\Providers;

use Illuminate\Support\ServiceProvider;

class ProductDevServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/pd.php');
    }
}
