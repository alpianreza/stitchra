<?php

namespace Modules\Core\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Modules\Core\Support\CurrentCompany;

/**
 * BR-011: scope data per company — semua model tenant memakai trait ini.
 * Query otomatis terfilter company aktif; create otomatis mengisi company_id.
 */
class BelongsToCompanyScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $companyId = CurrentCompany::id();

        if ($companyId !== null) {
            $builder->where($model->qualifyColumn('company_id'), $companyId);
        }
    }
}

trait BelongsToCompany
{
    public static function bootBelongsToCompany(): void
    {
        static::addGlobalScope(new BelongsToCompanyScope);

        static::creating(function (Model $model): void {
            if (empty($model->company_id) && CurrentCompany::id() !== null) {
                $model->company_id = CurrentCompany::id();
            }
        });
    }
}
