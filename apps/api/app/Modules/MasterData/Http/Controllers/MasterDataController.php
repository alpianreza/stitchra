<?php

namespace Modules\MasterData\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Modules\Core\Services\AuditService;
use Modules\Core\Support\CurrentCompany;
use Modules\MasterData\Support\MasterDataRegistry;

/**
 * CRUD generik master data.
 * BR-110: permission dicek server-side (master.<entity>.<action>).
 * BR-011: company scope via trait BelongsToCompany pada model.
 * BR-016: semua mutasi tercatat audit.
 */
class MasterDataController extends Controller
{
    public function __construct(private AuditService $audit) {}

    public function index(Request $request, string $entity): JsonResponse
    {
        $config = $this->config($entity);
        $this->authorize($request, $config['entity'], 'view');

        $query = $config['model']::query();

        if ($search = $request->query('q')) {
            $query->where(function ($q) use ($search) {
                $q->where('code', 'like', "%{$search}%")
                  ->orWhere('name', 'like', "%{$search}%")
                  ->orWhere('style_no', 'like', "%{$search}%")
                  ->orWhere('nik', 'like', "%{$search}%");
            });
        }
        if ($request->query('active') !== null) {
            $query->where('is_active', (bool) $request->query('active'));
        }

        $perPage = min((int) $request->query('per_page', 25), 100);

        return response()->json($query->orderByDesc('id')->paginate($perPage));
    }

    public function store(Request $request, string $entity): JsonResponse
    {
        $config = $this->config($entity);
        $this->authorize($request, $config['entity'], 'create');

        $data = $this->validateData($request, $config);
        $data['company_id'] = CurrentCompany::id();
        $data['created_by'] = $request->user()->id;

        $record = $config['model']::create($data);

        $this->audit->record('create', $record, after: $record->toArray(), request: $request);

        return response()->json($record, 201);
    }

    public function show(Request $request, string $entity, int $id): JsonResponse
    {
        $config = $this->config($entity);
        $this->authorize($request, $config['entity'], 'view');

        return response()->json($config['model']::findOrFail($id));
    }

    public function update(Request $request, string $entity, int $id): JsonResponse
    {
        $config = $this->config($entity);
        $this->authorize($request, $config['entity'], 'update');

        $record = $config['model']::findOrFail($id);
        $before = $record->toArray();

        $data = $this->validateData($request, $config, isUpdate: true);
        $data['updated_by'] = $request->user()->id;

        $record->update($data);

        $this->audit->record('update', $record, before: $before, after: $record->fresh()->toArray(), request: $request);

        return response()->json($record->fresh());
    }

    public function destroy(Request $request, string $entity, int $id): JsonResponse
    {
        $config = $this->config($entity);
        $this->authorize($request, $config['entity'], 'delete');

        $record = $config['model']::findOrFail($id);

        // Master yang dipakai transaksi tidak boleh dihapus (soft delete dijaga FK RESTRICT di DB)
        $record->delete();

        $this->audit->record('delete', $record, before: $record->toArray(), request: $request);

        return response()->json(['message' => 'Dihapus (soft delete).']);
    }

    private function config(string $entity): array
    {
        $config = MasterDataRegistry::get($entity);
        abort_if($config === null, 404, "Entity [{$entity}] tidak dikenal.");

        return $config;
    }

    /** BR-110: server-side permission check */
    private function authorize(Request $request, string $entityCode, string $action): void
    {
        $permission = "master.{$entityCode}.{$action}";

        if (! $request->user()->hasPermission($permission)) {
            abort(403, "Permission [{$permission}] diperlukan.");
        }
    }

    private function validateData(Request $request, array $config, bool $isUpdate = false): array
    {
        $rules = $config['rules'];

        if ($isUpdate) {
            $rules = collect($rules)->mapWithKeys(fn ($rule, $field) => [$field => 'sometimes|'.$rule])->all();
        }

        // Unik per company untuk kolom code/style_no/nik bila ada
        $companyId = CurrentCompany::id();
        foreach (['code', 'style_no', 'nik'] as $uniqueField) {
            if (isset($rules[$uniqueField])) {
                $table = (new $config['model'])->getTable();
                $rules[$uniqueField] .= '|'.Rule::unique($table, $uniqueField)
                    ->where('company_id', $companyId)
                    ->ignore($isUpdate ? $request->route('id') : null);
            }
        }

        return $request->validate($rules);
    }
}
