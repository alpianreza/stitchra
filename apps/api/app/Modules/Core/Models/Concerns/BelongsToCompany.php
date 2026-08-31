<?php

namespace Modules\Core\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use LogicException;
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
            $currentCompanyId = CurrentCompany::id();

            if (empty($model->company_id)) {
                if ($currentCompanyId === null) {
                    throw new LogicException('company_id wajib diisi ketika tidak ada company aktif.');
                }

                $model->company_id = $currentCompanyId;

                return;
            }

            if ($currentCompanyId !== null && (int) $model->company_id !== $currentCompanyId) {
                throw new LogicException('Tidak dapat membuat data untuk company lain dalam konteks aktif.');
            }
        });
    }
}
