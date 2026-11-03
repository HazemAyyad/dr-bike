<?php

namespace Tests\Unit;

use App\Http\Controllers\API\InstantSales;
use App\Models\SalesOrder;
use App\Services\SalesOrderService;
use App\Support\SaleAdditionalNotes;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

class SalesOrderAdditionalNotesTest extends TestCase
{
    public function test_notes_keep_the_instant_sale_normalization_and_rounding(): void
    {
        $input = [
            ['text' => ' تركيب ', 'amount' => '12.505'],
            ['text' => 'ملاحظة مجانية', 'amount' => 0],
            ['text' => '', 'amount' => 5],
            ['text' => '', 'amount' => 0],
        ];
        $normalized = SaleAdditionalNotes::normalize($input);
        $controller = (new ReflectionClass(InstantSales::class))->newInstanceWithoutConstructor();
        $instant = new ReflectionMethod(InstantSales::class, 'normalizeInstantSaleNotes');

        $this->assertSame($instant->invoke($controller, $input), $normalized);
        $this->assertCount(3, $normalized);
        $this->assertSame('تركيب', $normalized[0]['text']);
        $this->assertSame(17.51, SaleAdditionalNotes::total($normalized));
    }

    public function test_totals_include_notes_once_and_ignore_a_forged_client_total(): void
    {
        $result = $this->totals([
            'items' => [['quantity' => 2, 'unit_price' => 50]],
            'discount' => 10,
            'customer_delivery_fee' => 20,
            'additional_notes' => [['text' => 'تركيب', 'amount' => 15.5]],
            'total' => 99999,
        ]);
        $this->assertSame(100.0, $result['subtotal']);
        $this->assertSame(125.5, $result['calculated_total']);
        $this->assertSame(125.5, $result['total']);
    }

    public function test_partial_updates_preserve_notes_and_an_empty_list_removes_them(): void
    {
        $order = new SalesOrder([
            'subtotal' => 100, 'discount' => 10, 'customer_delivery_fee' => 20,
            'additional_notes' => [['text' => 'تركيب', 'amount' => 15.5]],
        ]);
        $this->assertSame(125.5, $this->totals([], $order)['total']);
        $this->assertSame(110.0, $this->totals(['additional_notes' => []], $order)['total']);
        $this->assertSame(15.5, $order->additional_notes_total);
    }

    public function test_negative_note_amounts_are_rejected(): void
    {
        $service = (new ReflectionClass(SalesOrderService::class))->newInstanceWithoutConstructor();
        $validate = new ReflectionMethod(SalesOrderService::class, 'validateOrderPayload');
        $this->expectException(ValidationException::class);
        $validate->invoke($service, new Request([
            'additional_notes' => [['text' => 'تركيب', 'amount' => -1]],
        ]), true);
    }

    private function totals(array $data, ?SalesOrder $order = null): array
    {
        $service = (new ReflectionClass(SalesOrderService::class))->newInstanceWithoutConstructor();

        return (new ReflectionMethod(SalesOrderService::class, 'calculateTotals'))
            ->invoke($service, $data, $order);
    }
}
