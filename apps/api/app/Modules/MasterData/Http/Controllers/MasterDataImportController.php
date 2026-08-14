<?php

namespace Modules\MasterData\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Core\Services\AuditService;
use Modules\MasterData\Services\MasterDataImportService;
use Modules\MasterData\Support\MasterDataRegistry;

/** Import CSV master data — permission: master.<entity>.create (BR-110) */
class MasterDataImportController extends Controller
{
    public function __construct(
        private MasterDataImportService $importer,
        private AuditService $audit,
    ) {}

    public function __invoke(Request $request, string $entity): JsonResponse
    {
        $config = MasterDataRegistry::get($entity);
        abort_if($config === null, 404, "Entity [{$entity}] tidak dikenal.");

        if (! $request->user()->hasPermission("master.{$config['entity']}.create")) {
            abort(403, "Permission [master.{$config['entity']}.create] diperlukan.");
        }

        // BR-112: validasi upload (type + size)
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:10240'],
        ]);

        $job = $this->importer->import($entity, $request->file('file'), $request->user()->id);

        $this->audit->record('import', 'integration_jobs', documentId: $job->id, after: [
            'entity' => $entity,
            'total' => $job->total_rows,
            'success' => $job->success_rows,
            'failed' => $job->failed_rows,
        ], request: $request);

        return response()->json($job, 201);
    }
}
