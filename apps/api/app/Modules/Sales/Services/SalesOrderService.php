<?php

namespace Modules\Sales\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Approval\ApprovalEngine;
use Modules\Core\Models\User;
use Modules\Core\Services\NumberingService;
use Modules\Core\Support\CurrentCompany;
use Modules\MasterData\Models\Colorway;
use Modules\MasterData\Models\Currency;
use Modules\MasterData\Models\Customer;
use Modules\MasterData\Models\Size;
use Modules\MasterData\Models\Style;
use Modules\ProductDev\Services\BomService;
use Modules\ProductDev\Services\RoutingService;
use Modules\Sales\Models\SalesOrder;
use RuntimeException;

class SalesOrderService
{
    public function __construct(
        private NumberingService $numbering,
        private ApprovalEngine $approval,
        private BomService $boms,
        private RoutingService $routings,
    ) {}

    public function create(int $companyId, array $header, array $lines, User $creator): SalesOrder
    {
        if ($companyId <= 0 || (CurrentCompany::id() !== null && CurrentCompany::id() !== $companyId)) {
            throw new RuntimeException('Company SO tidak sesuai context aktif.');
        }
        if ($lines === []) {
            throw new RuntimeException('SO wajib punya minimal 1 line (style×color×size).');
        }

        $this->assertCreatorCanUseCompany($creator, $companyId);
        $this->assertHeaderBelongsToCompany($header, $companyId);
        $this->assertMatrixLines($lines, $companyId);

        return DB::transaction(function () use ($companyId, $header, $lines, $creator): SalesOrder {
            $so = SalesOrder::create(array_merge($header, [
                'company_id' => $companyId,
                'doc_no' => $this->numbering->next($companyId, 'SO'),
                'status' => 'DRAFT',
                'created_by' => $creator->id,
            ]));

            foreach ($lines as $line) {
                $so->lines()->create($line);
            }

            return $so->load('lines');
        });
    }

    public function submit(SalesOrder $so, User $submitter): void
    {
        DB::transaction(function () use ($so, $submitter): void {
            $locked = SalesOrder::withoutGlobalScopes()
                ->where('company_id', $so->company_id)
                ->whereKey($so->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status !== 'DRAFT') {
                throw new RuntimeException('Hanya SO DRAFT yang bisa disubmit.');
            }
            if (! $locked->lines()->exists()) {
                throw new RuntimeException('SO wajib punya minimal satu line.');
            }

            $locked->update(['status' => 'SUBMITTED']);
            $this->approval->submit($locked, 'SO', $submitter);
        });
    }

    public function markApproved(SalesOrder $so): void
    {
        DB::transaction(function () use ($so): void {
            $locked = SalesOrder::withoutGlobalScopes()->whereKey($so->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'SUBMITTED') {
                throw new RuntimeException('SO harus berstatus SUBMITTED sebelum APPROVED.');
            }
            $locked->update(['status' => 'APPROVED']);
        });
    }

    public function confirm(SalesOrder $so): SalesOrder
    {
        return DB::transaction(function () use ($so): SalesOrder {
            $locked = SalesOrder::withoutGlobalScopes()->whereKey($so->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'APPROVED') {
                throw new RuntimeException('Hanya SO APPROVED yang bisa di-confirm.');
            }

            $missing = [];
            foreach ($locked->lines()->distinct()->pluck('style_id') as $styleId) {
                if ($this->boms->activeVersion($styleId) === null) {
                    $missing[] = "Style #{$styleId}: BOM belum APPROVED";
                }
                if ($this->routings->activeVersion($styleId) === null) {
                    $missing[] = "Style #{$styleId}: Routing belum APPROVED";
                }
            }

            if ($missing !== []) {
                throw new RuntimeException('BR-023: SO tidak bisa di-confirm — '.implode('; ', $missing));
            }

            $locked->update(['status' => 'CONFIRMED']);

            return $locked->fresh();
        });
    }

    public function cuttingStarted(SalesOrder $so): bool
    {
        if (! Schema::hasTable('production_orders')) {
            return false;
        }

        return DB::table('production_orders')
            ->where('sales_order_id', $so->id)
            ->whereIn('status', ['CUTTING', 'SEWING', 'FINISHING', 'QC', 'PACKED', 'CLOSED'])
            ->exists();
    }

    private function assertCreatorCanUseCompany(User $creator, int $companyId): void
    {
        $allowed = (int) $creator->company_id === $companyId
            || $creator->companies()->where('companies.id', $companyId)->exists();

        if (! $allowed) {
            throw new RuntimeException('Creator tidak memiliki akses ke company SO.');
        }
    }

    private function assertHeaderBelongsToCompany(array $header, int $companyId): void
    {
        if (! Customer::query()->where('company_id', $companyId)->whereKey($header['customer_id'] ?? null)->exists()) {
            throw new RuntimeException('Customer tidak ditemukan pada company aktif.');
        }

        if (! empty($header['currency_id'])
            && ! Currency::query()->where('company_id', $companyId)->whereKey($header['currency_id'])->exists()) {
            throw new RuntimeException('Currency tidak ditemukan pada company aktif.');
        }
    }

    private function assertMatrixLines(array $lines, int $companyId): void
    {
        $seen = [];

        foreach ($lines as $line) {
            $styleId = (int) ($line['style_id'] ?? 0);
            $colorwayId = (int) ($line['colorway_id'] ?? 0);
            $sizeId = (int) ($line['size_id'] ?? 0);
            $key = "{$styleId}:{$colorwayId}:{$sizeId}";

            if (isset($seen[$key])) {
                throw new RuntimeException('Matrix SO tidak boleh memiliki kombinasi style×colorway×size duplikat.');
            }
            $seen[$key] = true;

            $validStyle = Style::query()->where('company_id', $companyId)->whereKey($styleId)->exists();
            $validColorway = Colorway::query()->where('company_id', $companyId)
                ->where('style_id', $styleId)->whereKey($colorwayId)->exists();
            $validSize = Size::query()->where('company_id', $companyId)->whereKey($sizeId)->exists();

            if (! $validStyle || ! $validColorway || ! $validSize) {
                throw new RuntimeException('Matrix SO harus memakai style, colorway, dan size dari company/style yang sama.');
            }
        }
    }
}
