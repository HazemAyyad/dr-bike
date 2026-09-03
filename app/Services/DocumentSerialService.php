<?php

namespace App\Services;

use App\Models\DocumentSerial;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class DocumentSerialService
{
    public const TYPE_SALES_ORDER = 'SO';

    /** فاتورة البيع الفوري */
    public const TYPE_INSTANT_SALE_INVOICE = 'IS';

    /** فاتورة مرتجع المبيعات */
    public const TYPE_SALES_RETURN = 'SR';

    public function usesYearPrefix(string $documentType): bool
    {
        return ! in_array($documentType, [
            self::TYPE_SALES_ORDER,
            self::TYPE_INSTANT_SALE_INVOICE,
            self::TYPE_SALES_RETURN,
        ], true);
    }

    public function nextSerial(string $documentType, ?Carbon $at = null): string
    {
        $at ??= now();
        $withYear = $this->usesYearPrefix($documentType);
        $year = $withYear ? (int) $at->format('Y') : 0;
        $yearSuffix = $at->format('y');

        $number = DB::transaction(function () use ($year, $documentType) {
            $row = DocumentSerial::query()
                ->where('year', $year)
                ->where('document_type', $documentType)
                ->lockForUpdate()
                ->first();

            if (! $row) {
                $lastNumber = (int) DocumentSerial::query()
                    ->where('document_type', $documentType)
                    ->lockForUpdate()
                    ->max('last_number');

                $row = DocumentSerial::create([
                    'year' => $year,
                    'document_type' => $documentType,
                    'last_number' => $lastNumber,
                ]);
            }

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

    public function assignPrefixedToModel(
        object $model,
        string $documentType,
        string $prefix,
        string $column = 'serial_number',
        ?Carbon $at = null
    ): string {
        if (! empty($model->{$column})) {
            return (string) $model->{$column};
        }

        $serial = $prefix.$this->nextSerial($documentType, $at);
        $model->forceFill([$column => $serial])->save();

        return $serial;
    }
}
