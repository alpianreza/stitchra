<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;
use Modules\Core\Http\Middleware\ResolveCompany;
use Modules\Core\Http\Middleware\EnsurePermission;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            // Modular monolith: mount semua route modul di bawah /api.
            // Catatan: provider modul juga memuat file yang sama tanpa prefix
            // (backward-compat CLI); route efektif untuk frontend = /api/*.
            Route::middleware('api')
                ->prefix('api')
                ->group(function (): void {
                    foreach (glob(app_path('Modules/*/routes/*.php')) as $moduleRouteFile) {
                        require $moduleRouteFile;
                    }
                });
        },
    )
    ->withProviders([
        Modules\Core\Providers\CoreServiceProvider::class,
        Modules\Cutting\Providers\CuttingServiceProvider::class,
        Modules\Finance\Providers\FinanceServiceProvider::class,
        Modules\Inventory\Providers\InventoryServiceProvider::class,
        Modules\MasterData\Providers\MasterDataServiceProvider::class,
        Modules\Packing\Providers\PackingServiceProvider::class,
        Modules\Planning\Providers\PlanningServiceProvider::class,
        Modules\ProductDev\Providers\ProductDevServiceProvider::class,
        Modules\Production\Providers\ProductionServiceProvider::class,
        Modules\Purchasing\Providers\PurchasingServiceProvider::class,
        Modules\Qc\Providers\QcServiceProvider::class,
        Modules\Reporting\Providers\ReportingServiceProvider::class,
        Modules\Sales\Providers\SalesServiceProvider::class,
        Modules\Shipping\Providers\ShippingServiceProvider::class,
        Modules\ShopFloor\Providers\ShopFloorServiceProvider::class,
        Modules\Subcon\Providers\SubconServiceProvider::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        // App ini murni Bearer-token (localStorage), bukan Sanctum SPA cookie-flow.
        // statefulApi() membuat request browser kena session+CSRF -> 419 saat login.
        // $middleware->statefulApi();

        $middleware->alias([
            'company' => ResolveCompany::class,
            'permission' => EnsurePermission::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
