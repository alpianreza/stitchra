<?php

namespace Modules\ProductDev\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Modules\Core\Models\Concerns\BelongsToCompany;
use Modules\MasterData\Models\Style;

class Sample extends Model
{
    use BelongsToCompany;

    public const STAGES = ['PROTO','FIT','PP','TOP'];
    public const BUYER_STATUSES = ['PENDING','APPROVED','REJECTED','COMMENTED'];

    protected $fillable = [
        'company_id', 'doc_no', 'style_id', 'stage', 'version',
        'buyer_status', 'created_by', 'updated_by',
        'style_spec_id', 'measurement_chart_id', 'tech_pack_id', 'revision_of_id', 'notes', 'request_key',
    ];

    protected $hidden = ['request_key'];

    public function styleSpec(): BelongsTo
    {
        return $this->belongsTo(StyleSpec::class);
    }

    public function measurementChart(): BelongsTo
    {
        return $this->belongsTo(MeasurementChart::class);
    }

    public function techPack(): BelongsTo
    {
        return $this->belongsTo(TechPack::class);
    }

    public function isSuperseded(): bool
    {
        // Ambiguous legacy duplicate version numbers also fail closed.
        return self::withoutGlobalScopes()->where('company_id', $this->company_id)
            ->where('style_id', $this->style_id)->where('stage', $this->stage)
            ->where('version', '>=', $this->version)->where('id', '!=', $this->id)->exists();
    }

    public function style(): BelongsTo
    {
        return $this->belongsTo(Style::class);
    }

    public function latestApproval(): HasOne
    {
        return $this->hasOne(SampleApproval::class)->latestOfMany();
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(SampleApproval::class);
    }
}
