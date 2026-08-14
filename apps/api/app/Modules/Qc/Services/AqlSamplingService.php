<?php

namespace Modules\Qc\Services;

/**
 * BR-008/071: AQL sampling — ISO 2859-1, General Inspection Level II, single sampling normal.
 * Tabel disederhanakan untuk AQL yang lazim di garment (1.0/1.5/2.5/4.0/6.5);
 * baris dengan panah di tabel resmi memakai sample size terdekat (konservatif).
 */
class AqlSamplingService
{
    /** [lot_min, lot_max, code, sample_size, [aql => [Ac, Re]]] */
    private const TABLE = [
        [2, 8, 'A', 2,        ['2.5' => [0, 1], '4.0' => [0, 1], '6.5' => [0, 1]]],
        [9, 15, 'B', 3,       ['2.5' => [0, 1], '4.0' => [0, 1], '6.5' => [1, 2]]],
        [16, 25, 'C', 5,      ['2.5' => [0, 1], '4.0' => [1, 2], '6.5' => [1, 2]]],
        [26, 50, 'D', 8,      ['1.5' => [0, 1], '2.5' => [0, 1], '4.0' => [1, 2], '6.5' => [2, 3]]],
        [51, 90, 'E', 13,     ['1.5' => [0, 1], '2.5' => [1, 2], '4.0' => [2, 3], '6.5' => [3, 4]]],
        [91, 150, 'F', 20,    ['1.0' => [0, 1], '1.5' => [1, 2], '2.5' => [1, 2], '4.0' => [2, 3], '6.5' => [3, 4]]],
        [151, 280, 'G', 32,   ['1.0' => [1, 2], '1.5' => [1, 2], '2.5' => [2, 3], '4.0' => [3, 4], '6.5' => [5, 6]]],
        [281, 500, 'H', 50,   ['1.0' => [1, 2], '1.5' => [2, 3], '2.5' => [3, 4], '4.0' => [5, 6], '6.5' => [7, 8]]],
        [501, 1200, 'J', 80,  ['1.0' => [2, 3], '1.5' => [3, 4], '2.5' => [5, 6], '4.0' => [7, 8], '6.5' => [10, 11]]],
        [1201, 3200, 'K', 125, ['1.0' => [3, 4], '1.5' => [5, 6], '2.5' => [7, 8], '4.0' => [10, 11], '6.5' => [14, 15]]],
        [3201, 10000, 'L', 200, ['1.0' => [5, 6], '1.5' => [7, 8], '2.5' => [10, 11], '4.0' => [14, 15], '6.5' => [21, 22]]],
        [10001, 35000, 'M', 315, ['1.0' => [7, 8], '1.5' => [10, 11], '2.5' => [14, 15], '4.0' => [21, 22]]],
        [35001, 150000, 'N', 500, ['1.0' => [10, 11], '1.5' => [14, 15], '2.5' => [21, 22]]],
        [150001, PHP_INT_MAX, 'P', 800, ['1.0' => [14, 15], '1.5' => [21, 22], '2.5' => [21, 22]]],
    ];

    /** Lot size → [code, sample_size] (G-II) */
    public function sampleFor(float $lotQty): array
    {
        foreach (self::TABLE as [$min, $max, $code, $n]) {
            if ($lotQty >= $min && $lotQty <= $max) {
                return ['code' => $code, 'sample_size' => $n];
            }
        }

        return ['code' => 'P', 'sample_size' => 800];
    }

    /** [Ac, Re] untuk sample size & AQL */
    public function acceptReject(int $sampleSize, float $aql): array
    {
        $key = number_format($aql, 1);

        foreach (self::TABLE as [, , , $n, $map]) {
            if ($n === $sampleSize) {
                return $map[$key] ?? [0, 1];   // fallback konservatif
            }
        }

        return [0, 1];
    }

    /**
     * Verdict final inspection.
     * - Critical: Ac selalu 0 (satu defect critical = FAIL)
     * - Major/Minor: FAIL bila defects ≥ Re
     */
    public function verdict(float $lotQty, int $defectsMajor, int $defectsMinor, int $defectsCritical, float $aqlMajor, float $aqlMinor): array
    {
        $sample = $this->sampleFor($lotQty);
        [$acMajor, $reMajor] = $this->acceptReject($sample['sample_size'], $aqlMajor);
        [, $reMinor] = $this->acceptReject($sample['sample_size'], $aqlMinor);

        $pass = $defectsCritical === 0
            && $defectsMajor < $reMajor
            && $defectsMinor < $reMinor;

        return [
            'verdict' => $pass ? 'PASS' : 'FAIL',
            'sample_code' => $sample['code'],
            'sample_size' => $sample['sample_size'],
            'accept_major' => $acMajor,
            'reject_major' => $reMajor,
            'reject_minor' => $reMinor,
        ];
    }
}
