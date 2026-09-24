<?php

namespace App\Exceptions;

use RuntimeException;

class SourceLinkedDebtTransactionException extends RuntimeException
{
    public function __construct(
        public readonly string $source,
        public readonly ?int $sourceId,
    ) {
        parent::__construct('هذه الحركة مرتبطة بعملية أصلية ولا يمكن تعديلها من دفتر الديون.');
    }
}
