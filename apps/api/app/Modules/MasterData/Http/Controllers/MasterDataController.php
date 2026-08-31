<?php

namespace Modules\MasterData\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Core\Services\AuditService;
use Modules\Core\Support\CurrentCompany;
use Modules\MasterData\Support\MasterDataRegistry;
use Modules\MasterData\Support\MasterDataValidation;

class MasterDataController extends Controller
{
    public function __construct(private AuditService $audit) {}

    public function index(Request $request, string $entity): JsonResponse
    {
        $config = $this->config($entity);
        $this->authorize($request, $config['entity'], 'view');

        $filters = $request->validate([
            'q' => ['sometimes', 'nullable', 'string', 'max:255'],
            'active' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ]);

        $query = $config['model']::query();
        $searchFields = array_values(array_intersect(
            ['code', 'name', 'style_no', 'nik', 'period'],
            array_keys($config['rules']),
        ));

        if (($search = $filters['q'] ?? null) !== null && $search !== '' && $searchFields !== []) {
            $query->where(function ($nested) use ($search, $searchFields): void {
                foreach ($searchFields as $index => $field) {
                    $method = $index === 0 ? 'where' : 'orWhere';
                    $nested->{$method}($field, 'like', "%{$search}%");
                }
            });
        }

        $model = new $config['model'];
        if (array_key_exists('active', $filters) && in_array('is_active', $model->getFillable(), true)) {
            $query->where('is_active', $filters['active']);
        }

        return response()->json($query->orderByDesc('id')->paginate($filters['per_page'] ?? 25));
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
        $data = $this->validateData($request, $config, $record);
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
        $before = $record->toArray();
        $record->delete();

        $this->audit->record('delete', $record, before: $before, request: $request);

        return response()->json(['message' => 'Dihapus (soft delete).']);
    }

    private function config(string $entity): array
    {
        $config = MasterDataRegistry::get($entity);
        abort_if($config === null, 404, "Entity [{$entity}] tidak dikenal.");

        return $config;
    }

    private function authorize(Request $request, string $entityCode, string $action): void
    {
        $permission = "master.{$entityCode}.{$action}";

        if (! $request->user()->hasPermission($permission)) {
            abort(403, "Permission [{$permission}] diperlukan.");
        }
    }

    private function validateData(Request $request, array $config, ?Model $record = null): array
    {
        $companyId = CurrentCompany::id();
        abort_if($companyId === null, 500, 'Company context tidak tersedia.');

        $submitted = MasterDataValidation::normalize($request->all());
        $request->merge($submitted);
        $values = $record === null
            ? $submitted
            : array_merge($record->getAttributes(), $submitted);

        $rules = MasterDataValidation::rules(
            config: $config,
            companyId: $companyId,
            values: $values,
            presentFields: $record === null ? null : array_keys($submitted),
            ignoreId: $record?->getKey(),
        );

        return $request->validate($rules);
    }
}
