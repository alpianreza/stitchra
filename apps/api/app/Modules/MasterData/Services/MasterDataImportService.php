<?php

namespace Modules\MasterData\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Modules\Core\Support\CurrentCompany;
use Modules\MasterData\Models\IntegrationJob;
use Modules\MasterData\Support\MasterDataRegistry;
use Modules\MasterData\Support\MasterDataValidation;
use RuntimeException;
use Throwable;

class MasterDataImportService
{
    public function import(string $entity, UploadedFile $file, int $userId): IntegrationJob
    {
        $config = MasterDataRegistry::get($entity);
        if ($config === null) {
            throw new RuntimeException("Entity [{$entity}] tidak dikenal.");
        }

        $companyId = CurrentCompany::id();
        if ($companyId === null) {
            throw new RuntimeException('Company context tidak tersedia.');
        }

        $path = $file->store("imports/{$companyId}/{$entity}", 's3');
        $job = IntegrationJob::create([
            'company_id' => $companyId,
            'type' => 'MASTER_IMPORT',
            'entity' => $entity,
            'file_path' => $path,
            'status' => 'PROCESSING',
            'created_by' => $userId,
        ]);

        $handle = fopen($file->getRealPath(), 'r');
        if ($handle === false) {
            return $this->failJob($job, 'File tidak dapat dibaca.');
        }

        try {
            $header = $this->readCsv($handle);
            if ($header === false) {
                return $this->failJob($job, 'File kosong / header tidak terbaca.');
            }

            $header = array_map(fn ($value) => ltrim(trim((string) $value), "\xEF\xBB\xBF"), $header);
            $invalidHeaders = array_filter($header, fn ($value) => $value === '');
            $duplicateHeaders = count($header) !== count(array_unique($header));
            $unknownHeaders = array_values(array_diff($header, array_keys($config['rules'])));

            if ($invalidHeaders !== [] || $duplicateHeaders || $unknownHeaders !== []) {
                return $this->failJob($job, $unknownHeaders !== []
                    ? 'Kolom tidak dikenal: '.implode(', ', $unknownHeaders)
                    : 'Header CSV kosong atau duplikat.');
            }

            $errors = [];
            $success = 0;
            $total = 0;
            $maxRows = max(1, (int) config('master_data.import_max_rows', 10000));

            while (($row = $this->readCsv($handle)) !== false) {
                $total++;
                if ($total > $maxRows) {
                    $errors[] = ['row' => $total, 'message' => "Batas maksimum {$maxRows} baris terlampaui."];
                    break;
                }

                $data = array_combine($header, $row);
                if ($data === false) {
                    $errors[] = ['row' => $total, 'message' => 'Jumlah kolom tidak sesuai header.'];
                    continue;
                }

                $data = array_map(static fn ($value) => trim((string) $value) === '' ? null : trim((string) $value), $data);
                $data = MasterDataValidation::normalize($data);
                $validator = Validator::make($data, MasterDataValidation::rules($config, $companyId, $data));

                if ($validator->fails()) {
                    $errors[] = ['row' => $total, 'message' => $validator->errors()->first()];
                    continue;
                }

                try {
                    DB::transaction(function () use ($config, $data, $companyId, $userId): void {
                        $config['model']::create(array_merge($data, [
                            'company_id' => $companyId,
                            'created_by' => $userId,
                        ]));
                    });
                    $success++;
                } catch (Throwable $exception) {
                    report($exception);
                    $errors[] = ['row' => $total, 'message' => 'Gagal menyimpan baris: data duplikat atau referensi tidak valid.'];
                }
            }

            $job->update([
                'status' => 'DONE',
                'total_rows' => $total,
                'success_rows' => $success,
                'failed_rows' => $total - $success,
                'errors' => $errors ?: null,
            ]);

            return $job;
        } finally {
            fclose($handle);
        }
    }

    /** @return array<int, string|null>|false */
    private function readCsv($handle): array|false
    {
        return fgetcsv($handle, null, ',', '"', '');
    }

    private function failJob(IntegrationJob $job, string $message): IntegrationJob
    {
        $job->update(['status' => 'FAILED', 'errors' => [['row' => 0, 'message' => $message]]]);

        return $job;
    }
}
