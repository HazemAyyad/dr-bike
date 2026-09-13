<?php

namespace Tests\Unit;

use App\Services\LegacyInventoryReviewWorkbookService;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\TestCase;

class LegacyInventoryReviewWorkbookServiceTest extends TestCase
{
    public function test_workbook_round_trip_returns_only_explicitly_approved_rows(): void
    {
        $service = new LegacyInventoryReviewWorkbookService;
        $groups = [$this->group()];
        $path = $this->temporaryXlsxPath();

        try {
            $spreadsheet = $service->build($groups);
            (new Xlsx($spreadsheet))->save($path);
            $spreadsheet->disconnectWorksheets();

            $spreadsheet = IOFactory::load($path);
            $sheet = $spreadsheet->getSheetByName('Cost Review');
            $sheet->setCellValue('H2', 2.5);
            $sheet->setCellValue('J2', 'فاتورة المورد رقم 15');
            $sheet->setCellValue('K2', 'راجعه صاحب المتجر');
            $sheet->setCellValue('L2', 'APPROVE');
            (new Xlsx($spreadsheet))->save($path);
            $spreadsheet->disconnectWorksheets();

            $preview = $service->preview($path, $groups);

            $this->assertSame([], $preview['errors']);
            $this->assertSame(1, $preview['summary']['groups']);
            $this->assertSame(2, $preview['summary']['identities']);
            $this->assertSame(5.0, $preview['summary']['quantity']);
            $this->assertSame(12.5, $preview['summary']['value']);
            $this->assertSame(2.5, $preview['rows'][0]['unit_cost']);
            $this->assertSame('فاتورة المورد رقم 15', $preview['rows'][0]['cost_evidence']);
            $this->assertSame($groups[0]['identities'], $preview['rows'][0]['identities']);
        } finally {
            @unlink($path);
        }
    }

    public function test_workbook_preview_rejects_changed_inventory_quantity(): void
    {
        $service = new LegacyInventoryReviewWorkbookService;
        $groups = [$this->group()];
        $path = $this->temporaryXlsxPath();

        try {
            $spreadsheet = $service->build($groups);
            $sheet = $spreadsheet->getSheetByName('Cost Review');
            $sheet->setCellValue('F2', 99);
            $sheet->setCellValue('H2', 2.5);
            $sheet->setCellValue('J2', 'تقدير موثق');
            $sheet->setCellValue('L2', 'APPROVE');
            (new Xlsx($spreadsheet))->save($path);
            $spreadsheet->disconnectWorksheets();

            $preview = $service->preview($path, $groups);

            $this->assertSame([], $preview['rows']);
            $this->assertCount(1, $preview['errors']);
            $this->assertStringContainsString('الكمية تغيرت', $preview['errors'][0]);
        } finally {
            @unlink($path);
        }
    }

    /** @return array<string, mixed> */
    private function group(): array
    {
        return [
            'group_key' => 'product:10:scope:variants',
            'product_id' => 10,
            'product_code' => '000010',
            'product_name' => 'منتج متعدد',
            'scope' => 'variants',
            'scope_label' => 'كل الألوان والأحجام بتكلفة موحدة',
            'variant_count' => 2,
            'missing_quantity' => 5.0,
            'variant_details' => "M / أحمر (2)\nM / أزرق (3)",
            'identities' => [
                ['identity_key' => 'product:10:variant:100', 'missing_quantity' => 2.0],
                ['identity_key' => 'product:10:variant:101', 'missing_quantity' => 3.0],
            ],
        ];
    }

    private function temporaryXlsxPath(): string
    {
        $base = tempnam(sys_get_temp_dir(), 'legacy-review-');
        $this->assertNotFalse($base);
        @unlink($base);

        return $base.'.xlsx';
    }
}
