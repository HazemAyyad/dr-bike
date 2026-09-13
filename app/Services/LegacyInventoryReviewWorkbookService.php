<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class LegacyInventoryReviewWorkbookService
{
    private const TEMPLATE_VERSION = 'legacy-cost-review-v1';

    private const SHEET_NAME = 'Cost Review';

    private const HEADERS = [
        'رقم المنتج',
        'كود المنتج',
        'اسم المنتج',
        'نطاق الاعتماد',
        'عدد الألوان/الأحجام',
        'الكمية الناقصة',
        'تفاصيل الألوان/الأحجام والكميات',
        'تكلفة الوحدة',
        'العملة',
        'مصدر أو أساس التكلفة',
        'ملاحظات',
        'اكتب APPROVE للاعتماد',
        '_group_key',
        '_template_version',
    ];

    /** @param array<int, array<string, mixed>> $groups */
    public function build(array $groups): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(self::SHEET_NAME);
        $sheet->setRightToLeft(true);
        $sheet->fromArray([self::HEADERS], null, 'A1');

        $rowNumber = 2;
        foreach ($groups as $group) {
            $sheet->fromArray([[
                $group['product_id'],
                $group['product_code'],
                $group['product_name'],
                $group['scope_label'],
                $group['variant_count'],
                $group['missing_quantity'],
                $group['variant_details'],
                null,
                'شيكل',
                null,
                null,
                null,
                $group['group_key'],
                self::TEMPLATE_VERSION,
            ]], null, 'A'.$rowNumber);

            $currencyValidation = $sheet->getCell('I'.$rowNumber)->getDataValidation();
            $currencyValidation->setType(DataValidation::TYPE_LIST)
                ->setErrorStyle(DataValidation::STYLE_STOP)
                ->setAllowBlank(false)
                ->setShowErrorMessage(true)
                ->setErrorTitle('عملة غير صالحة')
                ->setError('اختر شيكل أو دولار أو دينار.')
                ->setFormula1('"شيكل,دولار,دينار"');

            $approvalValidation = $sheet->getCell('L'.$rowNumber)->getDataValidation();
            $approvalValidation->setType(DataValidation::TYPE_LIST)
                ->setErrorStyle(DataValidation::STYLE_STOP)
                ->setAllowBlank(true)
                ->setShowErrorMessage(true)
                ->setErrorTitle('تأكيد غير صالح')
                ->setError('اكتب APPROVE فقط للحالات التي تمت مراجعتها.')
                ->setFormula1('"APPROVE"');
            $rowNumber++;
        }

        $lastRow = max(2, $rowNumber - 1);
        $sheet->freezePane('A2');
        $sheet->setAutoFilter('A1:L'.$lastRow);
        $sheet->getStyle('A1:N1')->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
        $sheet->getStyle('A1:N1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF2563EB');
        $sheet->getStyle('A1:N'.$lastRow)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getStyle('G2:G'.$lastRow)->getAlignment()->setWrapText(true);
        $sheet->getStyle('H2:H'.$lastRow)->getNumberFormat()->setFormatCode('0.000000');
        $sheet->getStyle('F2:F'.$lastRow)->getNumberFormat()->setFormatCode('0.00');
        $sheet->getColumnDimension('A')->setWidth(13);
        $sheet->getColumnDimension('B')->setWidth(14);
        $sheet->getColumnDimension('C')->setWidth(35);
        $sheet->getColumnDimension('D')->setWidth(34);
        $sheet->getColumnDimension('E')->setWidth(20);
        $sheet->getColumnDimension('F')->setWidth(18);
        $sheet->getColumnDimension('G')->setWidth(55);
        $sheet->getColumnDimension('H')->setWidth(18);
        $sheet->getColumnDimension('I')->setWidth(14);
        $sheet->getColumnDimension('J')->setWidth(38);
        $sheet->getColumnDimension('K')->setWidth(38);
        $sheet->getColumnDimension('L')->setWidth(25);
        $sheet->getColumnDimension('M')->setVisible(false);
        $sheet->getColumnDimension('N')->setVisible(false);

        $instructions = $spreadsheet->createSheet();
        $instructions->setTitle('تعليمات');
        $instructions->setRightToLeft(true);
        $instructions->fromArray([
            ['تعليمات مراجعة تكلفة المخزون القديم'],
            ['1', 'لا تعدّل بيانات المنتج أو الكمية أو الأعمدة المخفية.'],
            ['2', 'أدخل تكلفة الوحدة الحقيقية فقط من فاتورة أو مورد أو تقدير إداري موثق. لا تستخدم سعر البيع.'],
            ['3', 'صف الألوان والأحجام يطبق تكلفة واحدة على كل المتغيرات المذكورة. اتركه فارغاً إذا كانت تكاليفها مختلفة.'],
            ['4', 'اكتب مصدر السعر بوضوح، مثل: فاتورة المورد رقم 25 أو اعتماد صاحب المتجر بتاريخ محدد.'],
            ['5', 'اكتب APPROVE فقط للصفوف المؤكدة. الصفوف الفارغة لن تُستورد.'],
            ['6', 'رفع الملف يعرض معاينة فقط. التنفيذ يحتاج تأكيداً منفصلاً داخل صفحة النظام.'],
        ], null, 'A1');
        $instructions->getStyle('A1:B1')->getFont()->setBold(true)->setSize(16);
        $instructions->getColumnDimension('A')->setWidth(8);
        $instructions->getColumnDimension('B')->setWidth(110);
        $instructions->getStyle('A1:B7')->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    /**
     * @param  array<int, array<string, mixed>>  $currentGroups
     * @return array{rows: array<int, array<string, mixed>>, errors: array<int, string>, summary: array<string, int|float>}
     */
    public function preview(string $path, array $currentGroups): array
    {
        try {
            $reader = IOFactory::createReaderForFile($path);
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($path);
        } catch (\Throwable) {
            throw ValidationException::withMessages(['file' => ['تعذر قراءة ملف Excel. نزّل قالباً جديداً ولا تغيّر صيغته.']]);
        }

        try {
            $sheet = $spreadsheet->getSheetByName(self::SHEET_NAME);
            if ($sheet === null) {
                throw ValidationException::withMessages(['file' => ['ورقة Cost Review غير موجودة. استخدم الملف المصدر من هذه الصفحة.']]);
            }
            $rows = $sheet->toArray(null, false, false, false);
        } finally {
            $spreadsheet->disconnectWorksheets();
        }

        if (($rows[0] ?? null) !== self::HEADERS) {
            throw ValidationException::withMessages(['file' => ['عناوين ملف المراجعة غير مطابقة. نزّل قالباً جديداً ولا تحذف الأعمدة.']]);
        }
        if (count($rows) > 501) {
            throw ValidationException::withMessages(['file' => ['عدد صفوف الملف أكبر من الحد المسموح.']]);
        }

        $currentByKey = collect($currentGroups)->keyBy('group_key');
        $approved = [];
        $errors = [];
        $seen = [];

        foreach (array_slice($rows, 1) as $offset => $row) {
            $rowNumber = $offset + 2;
            $unitCostRaw = $this->text($row[7] ?? null);
            $costEvidence = $this->text($row[9] ?? null);
            $notes = $this->text($row[10] ?? null);
            $approval = strtoupper($this->text($row[11] ?? null));
            if ($unitCostRaw === '' && $costEvidence === '' && $notes === '' && $approval === '') {
                continue;
            }

            $groupKey = $this->text($row[12] ?? null);
            $version = $this->text($row[13] ?? null);
            $current = $currentByKey->get($groupKey);
            if ($version !== self::TEMPLATE_VERSION || $current === null) {
                $errors[] = "السطر {$rowNumber}: القالب قديم أو هوية المجموعة لم تعد صالحة.";

                continue;
            }
            if (isset($seen[$groupKey])) {
                $errors[] = "السطر {$rowNumber}: المجموعة مكررة في الملف.";

                continue;
            }
            $seen[$groupKey] = true;

            if ((int) ($row[0] ?? 0) !== (int) $current['product_id']
                || (int) ($row[4] ?? -1) !== (int) $current['variant_count']
                || ! is_numeric($row[5] ?? null)
                || abs((float) $row[5] - (float) $current['missing_quantity']) > 0.0001) {
                $errors[] = "السطر {$rowNumber}: بيانات المنتج أو الكمية تغيرت. صدّر ملفاً جديداً.";

                continue;
            }
            if ($approval !== 'APPROVE') {
                $errors[] = "السطر {$rowNumber}: اكتب APPROVE لتأكيد السعر أو أفرغ حقول الاعتماد لتأجيل الصف.";

                continue;
            }
            if (! is_numeric($unitCostRaw) || (float) $unitCostRaw <= 0 || (float) $unitCostRaw > 999999999) {
                $errors[] = "السطر {$rowNumber}: تكلفة الوحدة يجب أن تكون رقماً أكبر من صفر.";

                continue;
            }
            $currency = $this->currency($this->text($row[8] ?? null));
            if ($currency === null) {
                $errors[] = "السطر {$rowNumber}: العملة يجب أن تكون شيكل أو دولار أو دينار.";

                continue;
            }
            if ($costEvidence === '' || mb_strlen($costEvidence) > 500) {
                $errors[] = "السطر {$rowNumber}: مصدر أو أساس التكلفة مطلوب وبحد أقصى 500 حرف.";

                continue;
            }
            if (mb_strlen($notes) > 1000) {
                $errors[] = "السطر {$rowNumber}: الملاحظات يجب ألا تتجاوز 1000 حرف.";

                continue;
            }

            $approved[] = array_merge($current, [
                'unit_cost' => (float) $unitCostRaw,
                'currency' => $currency,
                'cost_evidence' => $costEvidence,
                'notes' => $notes !== '' ? $notes : null,
                'source_row' => $rowNumber,
            ]);
        }

        if ($approved === [] && $errors === []) {
            $errors[] = 'لم يتم اعتماد أي صف. عبئ السعر والمصدر واكتب APPROVE للحالات المؤكدة.';
        }

        return [
            'rows' => $approved,
            'errors' => $errors,
            'summary' => [
                'groups' => count($approved),
                'identities' => collect($approved)->sum(fn (array $group) => count($group['identities'])),
                'quantity' => (float) collect($approved)->sum('missing_quantity'),
                'value' => (float) collect($approved)->sum(fn (array $group) => (float) $group['missing_quantity'] * (float) $group['unit_cost']),
            ],
        ];
    }

    private function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    private function currency(string $value): ?string
    {
        return match (mb_strtoupper(trim($value))) {
            'شيكل', 'NIS', 'ILS' => 'شيكل',
            'دولار', 'USD' => 'دولار',
            'دينار', 'JOD' => 'دينار',
            default => null,
        };
    }
}
