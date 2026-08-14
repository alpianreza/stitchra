<?php

namespace Modules\Production\Providers;

use Illuminate\Support\ServiceProvider;

class ProductionServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Routes produksi digabung di planning.php (planning + production berbagi route file sementara Phase 5–6)
    }
}
