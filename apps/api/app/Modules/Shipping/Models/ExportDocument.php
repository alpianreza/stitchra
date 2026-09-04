<?php
namespace Modules\Shipping\Models;
use Illuminate\Database\Eloquent\Model;use Illuminate\Database\Eloquent\Relations\BelongsTo;use Modules\Core\Models\Concerns\BelongsToCompany;
class ExportDocument extends Model{use BelongsToCompany;public const TYPES=['COO','BILL_OF_LADING','LC_DOCUMENT','CUSTOMS','OTHER'];public const STATUSES=['DRAFT','ISSUED','CANCELLED'];protected $fillable=['company_id','shipment_id','document_type','reference_no','issue_date','file_reference','status','created_by','updated_by'];protected function casts():array{return['issue_date'=>'date'];}public function shipment():BelongsTo{return$this->belongsTo(Shipment::class);}}
