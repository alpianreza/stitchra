<?php

namespace Modules\Finance\Models;
use Illuminate\Database\Eloquent\Model;use Illuminate\Database\Eloquent\Relations\BelongsTo;
class FxRevaluationJournal extends Model{protected $fillable=['fx_revaluation_run_id','event','journal_id','reversal_journal_id'];public function journal():BelongsTo{return$this->belongsTo(Journal::class);}public function reversalJournal():BelongsTo{return$this->belongsTo(Journal::class,'reversal_journal_id');}}
