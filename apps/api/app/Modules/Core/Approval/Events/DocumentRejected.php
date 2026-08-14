<?php

namespace Modules\Core\Approval\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Modules\Core\Models\ApprovalRequest;

class DocumentRejected
{
    use Dispatchable;

    public function __construct(public ApprovalRequest $request) {}
}
