<?php

namespace Modules\Finance\Providers;

use Illuminate\Support\ServiceProvider;

class FinanceServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/finance.php');
        $this->loadRoutesFrom(__DIR__.'/../routes/cogs.php');
        $this->loadRoutesFrom(__DIR__.'/../routes/corrections.php');
    }
}
