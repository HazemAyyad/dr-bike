<?php

namespace App\Services;

use App\Models\DocumentSerial;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class DocumentSerialService
{
    public const TYPE_SALES_ORDER = 'SO';

    public function nextSerial(string $documentType, ?Carbon $at = null): string
    {
        $at ??= now();
        $year = (int) $at->format('Y');
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

        return sprintf('%s/%07d', $yearSuffix, $number);
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
