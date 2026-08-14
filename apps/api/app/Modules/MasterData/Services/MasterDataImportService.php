<?php

namespace Modules\MasterData\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Modules\Core\Support\CurrentCompany;
use Modules\MasterData\Models\IntegrationJob;
use Modules\MasterData\Support\MasterDataRegistry;
use RuntimeException;

/**
 * Import CSV master data — validasi per baris memakai rules registry.
 * Baris invalid tidak masuk; semua error terlapor per baris (kualitas master = risiko #3 FASE 0).
 */
class MasterDataImportService
{
    public function import(string $entity, UploadedFile $file, int $userId): IntegrationJob
    {
        $config = MasterDataRegistry::get($entity);

        if ($config === null) {
            throw new RuntimeException("Entity [{$entity}] tidak dikenal.");
        }

        $companyId = CurrentCompany::id();
        $path = $file->store("imports/{$companyId}/{$entity}", 's3');

        $job = IntegrationJob::create([
            'company_id' => $companyId,
            'type' => 'MASTER_IMPORT',
            'entity' => $entity,
            'file_path' => $path,
            'status' => 'PROCESSING',
            'created_by' => $userId,
        ]);

        $errors = [];
        $success = 0;
        $total = 0;

        $handle = fopen($file->getRealPath(), 'r');
        $header = fgetcsv($handle);

        if ($header === false) {
            $job->update(['status' => 'FAILED', 'errors' => [['row' => 0, 'message' => 'File kosong / header tidak terbaca']]]);

            return $job;
        }

        $header = array_map(fn ($h) => trim($h), $header);

        while (($row = fgetcsv($handle)) !== false) {
            $total++;
            $data = array_combine($header, $row);

            if ($data === false) {
                $errors[] = ['row' => $total, 'message' => 'Jumlah kolom tidak sesuai header'];
                continue;
            }

            $validator = Validator::make($data, $config['rules']);

            if ($validator->fails()) {
                $errors[] = ['row' => $total, 'message' => $validator->errors()->first()];
                continue;
            }

            try {
                DB::transaction(function () use ($config, $data, $companyId, $userId) {
                    $config['model']::create(array_merge($data, [
                        'company_id' => $companyId,
                        'created_by' => $userId,
                    ]));
                });
                $success++;
            } catch (\Throwable $e) {
                $errors[] = ['row' => $total, 'message' => $e->getMessage()];
            }
        }

        fclose($handle);

        $job->update([
            'status' => 'DONE',
            'total_rows' => $total,
            'success_rows' => $success,
            'failed_rows' => $total - $success,
            'errors' => $errors ?: null,
        ]);

        return $job;
    }
}
