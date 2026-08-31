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

    private const UPPERCASE_FIELDS = [
        'country', 'currency', 'type', 'category', 'tracking_level',
        'lifecycle', 'normal_balance', 'section', 'severity',
    ];

    /** @param array<string, mixed> $values @return array<string, mixed> */
    public static function normalize(array $values): array
    {
        foreach (self::UPPERCASE_FIELDS as $field) {
            if (isset($values[$field]) && is_string($values[$field])) {
                $values[$field] = strtoupper(trim($values[$field]));
            }
        }

        return $values;
    }

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

        self::appendCompositeUniqueRules($rules, $table, $companyId, $values, $presentFields, $ignoreId);
        self::appendBusinessRules($rules, $table, $values, $presentFields);

        return $rules;
    }

    private static function appendCompositeUniqueRules(
        array &$rules,
        string $table,
        int $companyId,
        array $values,
        ?array $presentFields,
        ?int $ignoreId,
    ): void {
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
    }

    private static function appendBusinessRules(
        array &$rules,
        string $table,
        array $values,
        ?array $presentFields,
    ): void {
        foreach (['gsm', 'width_cm', 'rate', 'rate_per_minute', 'cost_per_minute'] as $field) {
            if (isset($rules[$field])) {
                $rules[$field][] = 'gt:0';
            }
        }

        if ($table !== 'materials' || ! isset($rules['tracking_level'])) {
            return;
        }

        $type = $values['type'] ?? null;
        if (! in_array($type, ['FABRIC', 'TRIM', 'PACKAGING'], true)) {
            return;
        }

        $typeChanged = $presentFields !== null && in_array('type', $presentFields, true);
        if ($presentFields === null || $typeChanged) {
            $rules['tracking_level'] = array_values(array_filter(
                $rules['tracking_level'],
                fn ($rule) => $rule !== 'sometimes' && $rule !== 'nullable',
            ));
            array_unshift($rules['tracking_level'], 'required');
        }

        $expected = $type === 'FABRIC' ? 'ROLL' : 'LOT';
        $rules['tracking_level'][] = Rule::in([$expected]);
    }
}
