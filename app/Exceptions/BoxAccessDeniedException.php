<?php

namespace App\Exceptions;

use RuntimeException;

class BoxAccessDeniedException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('الصندوق غير متاح لك.');
    }
}
