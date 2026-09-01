<?php

namespace Modules\ShopFloor\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Sanctum\PersonalAccessToken;
use Modules\Core\Models\Concerns\BelongsToCompany;
use Modules\Core\Models\User;

class ShopfloorDevice extends Model
{
    use BelongsToCompany;
    public const STATUSES=['ACTIVE','REVOKED'];
    protected $fillable=['public_id','company_id','user_id','token_id','name','platform','status','last_seen_at','revoked_at','revoked_by'];
    protected $hidden=['token_id'];
    protected function casts():array{return['last_seen_at'=>'datetime','revoked_at'=>'datetime'];}
    public function user():BelongsTo{return$this->belongsTo(User::class);}
    public function token():BelongsTo{return$this->belongsTo(PersonalAccessToken::class,'token_id');}
}
