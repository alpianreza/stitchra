<?php

namespace Modules\ShopFloor\Exceptions;

use RuntimeException;

class ScanConflictException extends RuntimeException
{
    public function __construct(string$message,public readonly int$currentVersion,public readonly array$snapshot=[]){parent::__construct($message);}
}
