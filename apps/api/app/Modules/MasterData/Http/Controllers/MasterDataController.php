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

    private function validateData(Request $request, array $config, bool $isUpdate = false): array
    {
        $companyId = CurrentCompany::id();
        $rules = $this->tenantScopedRules($config['rules'], $companyId);

        if ($isUpdate) {
            $rules = collect($rules)->mapWithKeys(function (array $rule, string $field): array {
                array_unshift($rule, 'sometimes');

                return [$field => $rule];
            })->all();
        }

        foreach (['code', 'style_no', 'nik'] as $uniqueField) {
            if (isset($rules[$uniqueField])) {
                $table = (new $config['model'])->getTable();
                $rules[$uniqueField][] = Rule::unique($table, $uniqueField)
                    ->where('company_id', $companyId)
                    ->ignore($isUpdate ? $request->route('id') : null);
            }
        }

        return $request->validate($rules);
    }

    /** Scope every registry exists rule to the active company. */
    private function tenantScopedRules(array $rules, int $companyId): array
    {
        foreach ($rules as $field => $rule) {
            $segments = is_array($rule) ? $rule : explode('|', $rule);

            foreach ($segments as $index => $segment) {
                if (! is_string($segment) || ! preg_match('/^exists:([^,]+)(?:,([^,]+))?$/', $segment, $matches)) {
                    continue;
                }

                $segments[$index] = Rule::exists($matches[1], $matches[2] ?? 'id')
                    ->where('company_id', $companyId);
            }

            $rules[$field] = $segments;
        }

        return $rules;
    }
}
