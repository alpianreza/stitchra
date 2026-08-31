<?php

namespace Modules\MasterData\Services;

use Illuminate\Support\Facades\DB;
use RuntimeException;

class UomConversionService
{
    public function convert(int $companyId, int $materialId, float $qty, int $fromUomId, int $toUomId): float
    {
        if ($qty <= 0) throw new RuntimeException('Qty konversi wajib lebih besar dari nol.');
        if ($fromUomId === $toUomId) return round($qty, 4);
        $from = $this->code($companyId, $fromUomId);
        $to = $this->code($companyId, $toUomId);
        $fromFactor = $this->lengthFactor($from); $toFactor = $this->lengthFactor($to);
        if ($fromFactor !== null && $toFactor !== null) return round($qty * $fromFactor / $toFactor, 4);
        $direct = DB::table('uom_conversions')->where('company_id', $companyId)->where('material_id', $materialId)
            ->where('from_uom_id', $fromUomId)->where('to_uom_id', $toUomId)->value('rate');
        if ($direct !== null && (float) $direct > 0) return round($qty * (float) $direct, 4);
        $inverse = DB::table('uom_conversions')->where('company_id', $companyId)->where('material_id', $materialId)
            ->where('from_uom_id', $toUomId)->where('to_uom_id', $fromUomId)->value('rate');
        if ($inverse !== null && (float) $inverse > 0) return round($qty / (float) $inverse, 4);
        throw new RuntimeException("Konversi UOM {$from} ke {$to} belum tersedia untuk material.");
    }

    public function toMeters(int $companyId, int $uomId, float $qty): float
    {
        $factor = $this->lengthFactor($this->code($companyId, $uomId));
        if ($factor === null) throw new RuntimeException('UOM panjang harus meter atau yard.');
        return round($qty * $factor, 4);
    }

    public function fromMeters(int $companyId, int $uomId, float $meters): float
    {
        $factor = $this->lengthFactor($this->code($companyId, $uomId));
        if ($factor === null) throw new RuntimeException('UOM panjang harus meter atau yard.');
        return round($meters / $factor, 4);
    }

    public function code(int $companyId, int $uomId): string
    {
        $code = DB::table('uoms')->where('company_id', $companyId)->where('id', $uomId)->value('code');
        if ($code === null) throw new RuntimeException('UOM tidak ditemukan pada company aktif.');
        return strtoupper(trim((string) $code));
    }

    private function lengthFactor(string $code): ?float
    {
        return match ($code) {
            'M', 'MTR', 'METER', 'METRE' => 1.0,
            'YD', 'YRD', 'YDS', 'YARD', 'YARDS' => 0.9144,
            default => null,
        };
    }
}
