<?php

namespace Modules\Packing\Services;

use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Core\Services\AuditService;
use Modules\Packing\Models\Carton;
use Modules\Packing\Models\PackingInstruction;
use Modules\Packing\Models\PackingList;
use Modules\Sales\Models\SalesOrder;
use RuntimeException;

class PackingInstructionService
{
    public function __construct(private PackingService $packing, private AuditService $audit) {}

    public function candidates(int $companyId): array
    {
        return SalesOrder::withoutGlobalScopes()->with(['customer', 'lines.style', 'lines.colorway', 'lines.size'])
            ->where('company_id', $companyId)->whereIn('status', ['CONFIRMED', 'IN_PROGRESS'])
            ->orderByDesc('id')->get()->map(function (SalesOrder $so) {
                $instruction = PackingInstruction::withoutGlobalScopes()->with(['lines.style', 'lines.colorway', 'lines.size'])
                    ->where('company_id', $so->company_id)->where('sales_order_id', $so->id)->where('is_active', true)->latest('version')->first();
                return ['id' => $so->id, 'doc_no' => $so->doc_no, 'status' => $so->status, 'customer' => $so->customer,
                    'lines' => $so->lines, 'active_instruction' => $instruction];
            })->values()->all();
    }

    public function activeForSalesOrder(SalesOrder $so, User $user): ?PackingInstruction
    {
        $this->assertAccess($user, (int) $so->company_id);
        return PackingInstruction::withoutGlobalScopes()->with(['lines.style', 'lines.colorway', 'lines.size'])
            ->where('company_id', $so->company_id)->where('sales_order_id', $so->id)->where('is_active', true)->latest('version')->first();
    }

    public function createVersion(SalesOrder $so, string $type, array $lines, User $user): PackingInstruction
    {
        return DB::transaction(function () use ($so, $type, $lines, $user) {
            $locked = SalesOrder::withoutGlobalScopes()->with('lines')->whereKey($so->id)->lockForUpdate()->firstOrFail();
            $this->assertAccess($user, (int) $locked->company_id);
            if (! in_array($locked->status, ['CONFIRMED', 'IN_PROGRESS'], true)) throw new RuntimeException('BR-024: Packing Instruction hanya untuk SO CONFIRMED/IN_PROGRESS.');
            if (! in_array($type, PackingInstruction::TYPES, true)) throw new RuntimeException('BR-024: tipe packing instruction tidak valid.');
            if ($lines === []) throw new RuntimeException('BR-024: instruction wajib memiliki matrix.');

            $seen = [];
            foreach ($lines as $line) {
                $key = ((int) $line['style_id']).'-'.((int) $line['colorway_id']).'-'.((int) $line['size_id']);
                if (isset($seen[$key])) throw new RuntimeException('BR-024: matrix instruction tidak boleh duplikat.');
                $seen[$key] = true;
                if ((int) ($line['ratio_qty'] ?? 0) <= 0) throw new RuntimeException('BR-024: ratio quantity wajib bilangan bulat positif.');
                if (! $locked->lines->contains(fn ($soLine) => (int) $soLine->style_id === (int) $line['style_id']
                    && (int) $soLine->colorway_id === (int) $line['colorway_id'] && (int) $soLine->size_id === (int) $line['size_id'])) {
                    throw new RuntimeException('BR-024: matrix instruction tidak terdapat pada SO.');
                }
            }
            if ($type === 'SOLID' && collect($lines)->contains(fn ($line) => (int) $line['ratio_qty'] !== 1)) {
                throw new RuntimeException('BR-024: SOLID memakai ratio 1; setiap carton hanya satu SKU.');
            }

            $current = PackingInstruction::withoutGlobalScopes()->where('company_id', $locked->company_id)
                ->where('sales_order_id', $locked->id)->where('is_active', true)->lockForUpdate()->get();
            PackingInstruction::withoutGlobalScopes()->whereIn('id', $current->pluck('id'))->update(['is_active' => false, 'updated_by' => $user->id]);
            $version = (int) PackingInstruction::withoutGlobalScopes()->where('sales_order_id', $locked->id)->max('version') + 1;
            $instruction = PackingInstruction::create(['company_id' => $locked->company_id, 'sales_order_id' => $locked->id,
                'version' => $version, 'pack_type' => $type, 'is_active' => true, 'created_by' => $user->id]);
            foreach ($lines as $line) $instruction->lines()->create(['style_id' => $line['style_id'], 'colorway_id' => $line['colorway_id'],
                'size_id' => $line['size_id'], 'ratio_qty' => $line['ratio_qty']]);
            $this->audit->record('create', $instruction, after: ['sales_order_id' => $locked->id, 'version' => $version,
                'pack_type' => $type, 'matrix_count' => count($lines), 'policy' => 'BR-024/BR-081:SO_VERSIONED_EXACT_CARTON_TEMPLATE']);
            return $instruction->fresh(['lines.style', 'lines.colorway', 'lines.size']);
        });
    }

    public function createPackingList(SalesOrder $so, int $moId, User $user): PackingList
    {
        return DB::transaction(function () use ($so, $moId, $user) {
            $instruction = PackingInstruction::withoutGlobalScopes()->where('company_id', $so->company_id)
                ->where('sales_order_id', $so->id)->where('is_active', true)->latest('version')->lockForUpdate()->first();
            if ($instruction === null) throw new RuntimeException('BR-024/BR-081: SO belum memiliki Packing Instruction aktif.');
            $list = $this->packing->create($so, $moId, $user);
            $list->update(['packing_instruction_id' => $instruction->id, 'updated_by' => $user->id]);
            $this->audit->record('update', $list, after: ['packing_instruction_id' => $instruction->id,
                'packing_instruction_version' => $instruction->version, 'pack_type' => $instruction->pack_type]);
            return $list->fresh(['salesOrder.customer', 'productionOrder', 'qcInspection']);
        });
    }

    public function addCarton(PackingList $list, array $carton, array $lines, User $user): Carton
    {
        return DB::transaction(function () use ($list, $carton, $lines, $user) {
            $locked = PackingList::withoutGlobalScopes()->whereKey($list->id)->lockForUpdate()->firstOrFail();
            if ($locked->packing_instruction_id !== null) $this->assertCartonMatches((int) $locked->company_id, (int) $locked->packing_instruction_id, $lines);
            return $this->packing->addCarton($locked, $carton, $lines, $user);
        });
    }

    public function finalize(PackingList $list, int $warehouseId, User $user): PackingList
    {
        return DB::transaction(function () use ($list, $warehouseId, $user) {
            $locked = PackingList::withoutGlobalScopes()->whereKey($list->id)->lockForUpdate()->firstOrFail();
            if ($locked->packing_instruction_id !== null) {
                $cartons = DB::table('cartons')->where('packing_list_id', $locked->id)->get();
                foreach ($cartons as $carton) {
                    $lines = DB::table('carton_lines')->where('carton_id', $carton->id)->get()->map(fn ($line) => (array) $line)->all();
                    $this->assertCartonMatches((int) $locked->company_id, (int) $locked->packing_instruction_id, $lines);
                }
            }
            return $this->packing->finalize($locked, $warehouseId, $user);
        });
    }

    private function assertCartonMatches(int $companyId, int $instructionId, array $lines): void
    {
        $instruction = PackingInstruction::withoutGlobalScopes()->with('lines')->where('company_id', $companyId)->whereKey($instructionId)->firstOrFail();
        $incoming = collect($lines)->mapWithKeys(fn ($line) => [((int) $line['style_id']).'-'.((int) $line['colorway_id']).'-'.((int) $line['size_id']) => (float) $line['qty']]);
        $template = $instruction->lines->mapWithKeys(fn ($line) => [$line->style_id.'-'.$line->colorway_id.'-'.$line->size_id => (int) $line->ratio_qty]);

        if ($instruction->pack_type === 'SOLID') {
            if ($incoming->count() !== 1 || ! $template->has($incoming->keys()->first())) throw new RuntimeException('BR-081: SOLID carton wajib berisi tepat satu SKU yang diizinkan instruction.');
            return;
        }
        if ($incoming->keys()->sort()->values()->all() !== $template->keys()->sort()->values()->all()) {
            throw new RuntimeException('BR-081: matrix carton tidak sama dengan template '.$instruction->pack_type.'.');
        }
        $multiplier = null;
        foreach ($template as $key => $ratio) {
            $current = ((float) $incoming[$key]) / $ratio;
            if ($current <= 0 || abs($current - round($current)) > 0.0001) throw new RuntimeException('BR-081: quantity carton wajib kelipatan bulat dari ratio instruction.');
            if ($multiplier === null) $multiplier = $current;
            elseif (abs($multiplier - $current) > 0.0001) throw new RuntimeException('BR-081: komposisi carton tidak mengikuti ratio instruction.');
        }
    }

    private function assertAccess(User $user, int $companyId): void
    {
        if ((int) $user->company_id !== $companyId && ! $user->companies()->whereKey($companyId)->exists()) throw new RuntimeException('User tidak memiliki akses ke company packing.');
    }
}
