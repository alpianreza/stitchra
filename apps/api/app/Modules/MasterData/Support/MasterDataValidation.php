<?php

namespace Modules\MasterData\Support;

use Illuminate\Validation\Rule;

/** Build consistent tenant-aware rules for CRUD and CSV imports. */
class MasterDataValidation
{
    /** @var array<string, array<int, array<int, string>>> */
    private const UNIQUE_GROUPS = [
        'colorways' => [['style_id', 'color_id']],
        'exchange_rates' => [['currency_id', 'rate_date']],
        'overhead_rates' => [['period']],
        'line_cost_rates' => [['line_id', 'period']],
    ];

    /**
     * @param array{model: class-string, rules: array<string, mixed>} $config
     * @param array<string, mixed> $values Current values merged with submitted values.
     * @param array<int, string>|null $presentFields Null for create/import; submitted keys for partial update.
     * @return array<string, array<int, mixed>>
     */
    public static function rules(
        array $config,
        int $companyId,
        array $values,
        ?array $presentFields = null,
        ?int $ignoreId = null,
    ): array {
        $model = new $config['model'];
        $table = $model->getTable();
        $rules = [];

        foreach ($config['rules'] as $field => $rule) {
            $segments = is_array($rule) ? $rule : explode('|', $rule);

            foreach ($segments as $index => $segment) {
                if (is_string($segment) && preg_match('/^exists:([^,]+)(?:,([^,]+))?$/', $segment, $matches)) {
                    $segments[$index] = Rule::exists($matches[1], $matches[2] ?? 'id')
                        ->where('company_id', $companyId);
                }
            }

            if (in_array($field, ['code', 'style_no', 'nik'], true)) {
                $segments[] = Rule::unique($table, $field)
                    ->where('company_id', $companyId)
                    ->ignore($ignoreId);
            }

            if ($presentFields !== null) {
                array_unshift($segments, 'sometimes');
            }

            $rules[$field] = $segments;
        }

        foreach (self::UNIQUE_GROUPS[$table] ?? [] as $group) {
            $target = $presentFields === null
                ? end($group)
                : collect($group)->first(fn (string $field) => in_array($field, $presentFields, true));

            if ($target === false || $target === null || ! isset($rules[$target])) {
                continue;
            }

            $unique = Rule::unique($table, $target)
                ->where('company_id', $companyId)
                ->ignore($ignoreId);

            foreach ($group as $field) {
                if ($field !== $target) {
                    $unique->where($field, $values[$field] ?? null);
                }
            }

            $rules[$target][] = $unique;
        }

        return $rules;
    }
}
