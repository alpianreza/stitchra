<?php

namespace Modules\ProductDev\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Modules\Core\Models\User;
use Modules\Core\Services\AuditService;
use Modules\MasterData\Models\Style;
use Modules\ProductDev\Models\TechPack;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class TechPackService
{
    public function __construct(private StyleDevelopmentService $styles, private AuditService $audit) {}

    public function upload(Style $style, UploadedFile $file, array $input, User $user): TechPack
    {
        $data = Validator::make([...$input, 'file' => $file], [
            'file' => ['required', 'file', 'mimes:pdf,png,jpg,jpeg,docx,xlsx,zip', 'extensions:pdf,png,jpg,jpeg,docx,xlsx,zip', 'max:'.config('pd.tech_pack_max_kb')],
            'upload_key' => 'required|uuid', 'revision_notes' => 'nullable|string|max:5000',
        ])->validate();
        $this->styles->access($style, $user);
        $hash = hash_file('sha256', $file->getRealPath());
        if ($hash === false) {
            throw new RuntimeException('File upload tidak dapat dibaca.');
        }
        $fileName = mb_substr(preg_replace('/[\\x00-\\x1F\\x7F]/u', '', basename(str_replace('\\', '/', $file->getClientOriginalName()))), 0, 200);
        $diskName = (string) config('pd.tech_pack_disk');
        if (! in_array($diskName, ['local', 's3'], true)) {
            throw new RuntimeException('Tech Pack harus memakai private disk local atau s3.');
        }
        // This variable is only set for a successfully stored new object.
        $storedPath = null;
        try {
            return DB::transaction(function () use ($style, $file, $data, $user, $hash, $fileName, $diskName, &$storedPath): TechPack {
                $style = $this->styles->lockStyle($style, $user);
                $existing = TechPack::withoutGlobalScopes()->where('company_id', $style->company_id)
                    ->where('upload_key', $data['upload_key'])->first();
                if ($existing) {
                    if ((int) $existing->style_id !== (int) $style->id || $existing->sha256 !== $hash
                        || $existing->file_name !== $fileName || $existing->revision_notes !== ($data['revision_notes'] ?? null)) {
                        throw new RuntimeException('Upload key sudah dipakai untuk payload berbeda.');
                    }

                    return $existing;
                }
                $version = (int) TechPack::withoutGlobalScopes()->where('company_id', $style->company_id)
                    ->where('style_id', $style->id)->max('version') + 1;
                $directory = "companies/{$style->company_id}/styles/{$style->id}/tech-packs";
                $name = Str::uuid().'.'.$file->guessExtension();
                $path = Storage::disk($diskName)->putFileAs($directory, $file, $name, ['visibility' => 'private']);
                if (! $path) {
                    throw new RuntimeException('Penyimpanan Tech Pack gagal. Periksa konfigurasi private storage.');
                }
                $storedPath = $path;
                $pack = TechPack::create([
                    'company_id' => $style->company_id, 'style_id' => $style->id,
                    'version' => $version, 'file_path' => $path, 'file_name' => $fileName,
                    'disk' => $diskName, 'mime_type' => $file->getMimeType(),
                    'size_bytes' => $file->getSize(), 'sha256' => $hash,
                    'revision_notes' => $data['revision_notes'] ?? null,
                    'upload_key' => $data['upload_key'], 'created_by' => $user->id,
                ]);
                $this->audit->record('upload', $pack, after: $pack->toArray());

                return $pack;
            }); // No automatic DB retry around external file writes.
        } catch (Throwable $exception) {
            if ($storedPath !== null) {
                try {
                    Storage::disk($diskName)->delete($storedPath);
                } catch (Throwable $cleanupError) {
                    report($cleanupError);
                }
            }
            throw $exception;
        }
    }

    public function download(TechPack $pack, User $user): StreamedResponse
    {
        $style = Style::withoutGlobalScopes()->where('company_id', $pack->company_id)->whereKey($pack->style_id)->firstOrFail();
        $this->styles->access($style, $user);
        $prefix = "companies/{$pack->company_id}/styles/{$pack->style_id}/tech-packs/";
        if (! in_array($pack->disk, ['local', 's3'], true) || ! $pack->sha256
            || ! str_starts_with($pack->file_path, $prefix) || str_contains($pack->file_path, '..')) {
            throw new RuntimeException('Metadata storage legacy belum lengkap; rekonsiliasi diperlukan sebelum download.');
        }
        $disk = Storage::disk($pack->disk);
        if (! $disk->exists($pack->file_path)) {
            throw new RuntimeException('File Tech Pack tidak tersedia pada storage.');
        }

        return $disk->download($pack->file_path, $pack->file_name, [
            'Content-Type' => 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}