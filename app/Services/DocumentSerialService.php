<?php

namespace App\Services;

use App\Models\DocumentSerial;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class DocumentSerialService
{
    public const TYPE_SALES_ORDER = 'SO';

    /** فاتورة البيع الفوري المرتبطة بطلبية — يتضمن سنة */
    public const TYPE_INSTANT_SALE_INVOICE = 'IS';

    public function usesYearPrefix(string $documentType): bool
    {
        return $documentType !== self::TYPE_SALES_ORDER;
    }

    public function nextSerial(string $documentType, ?Carbon $at = null): string
    {
        $at ??= now();
        $withYear = $this->usesYearPrefix($documentType);
        $year = $withYear ? (int) $at->format('Y') : 0;
        $yearSuffix = $at->format('y');

        $number = DB::transaction(function () use ($year, $documentType) {
            $row = DocumentSerial::query()
                ->lockForUpdate()
                ->firstOrCreate(
                    ['year' => $year, 'document_type' => $documentType],
                    ['last_number' => 0]
                );

            $row->last_number = (int) $row->last_number + 1;
            $row->save();

            return (int) $row->last_number;
        });

        if ($withYear) {
            return sprintf('%s/%07d', $yearSuffix, $number);
        }

        return sprintf('%07d', $number);
    }

    public function assignToModel(object $model, string $documentType, string $column = 'serial_number', ?Carbon $at = null): string
    {
        if (! empty($model->{$column})) {
            return (string) $model->{$column};
        }

        $serial = $this->nextSerial($documentType, $at);
        $model->forceFill([$column => $serial])->save();

        return $serial;
    }
}
