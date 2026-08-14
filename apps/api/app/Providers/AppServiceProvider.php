<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Core\Providers\CoreServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->register(CoreServiceProvider::class);
    }

    public function boot(): void
    {
        //
    }
}
