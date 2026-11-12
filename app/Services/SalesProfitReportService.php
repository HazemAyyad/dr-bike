<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SalesProfitReportService
{
    private Collection $colors;

    public function __construct(private SalesProfitReportBuilder $builder) {}

    public function report(array $filters): array
    {
        $filters = array_merge(['currency' => 'شيكل', 'source' => 'all', 'include_returns' => true], $filters);
        $filters['source'] = $filters['source'] ?? 'all';
        $filters['include_returns'] = filter_var($filters['include_returns'] ?? true, FILTER_VALIDATE_BOOLEAN);
        $from = $filters['from_date'].' 00:00:00';
        $to = $filters['to_date'].' 23:59:59.999999';
        $documents = [];
        $represented = [];
        $products = DB::table('products')->get(['id', 'product_code', 'nameAr', 'nameEng'])->keyBy('id'); // Include archived labels.
        $sizes = DB::table('sizes')->pluck('size', 'id');
        $this->colors = DB::table('size_colors')->pluck('colorAr', 'id');
        $boxes = DB::table('boxes')->pluck('currency', 'id');
        $orders = DB::table('sales_orders')->get(['id', 'instant_sale_id', 'financial_posted_at', 'is_debt_collection', 'status', 'serial_number', 'total', 'discount'])->keyBy('id');
        $maintenance = DB::table('maintenance')->whereNull('deleted_at')->get(['id', 'instant_sale_id', 'status', 'end_date', 'updated_at', 'invoice_total', 'discount'])->keyBy('id');
        $periodOrders = $orders->filter(fn ($row) => $this->inPeriod($row->financial_posted_at, $from, $to));
        $invoiceRoots = DB::table('instant_sales')->whereNull('parent_id')
            ->where(fn ($q) => $q->whereBetween('created_at', [$from, $to])
                ->orWhereIn('sales_order_id', $periodOrders->keys())
                ->orWhereIn('id', $periodOrders->pluck('instant_sale_id')->filter()))->get();
        $children = DB::table('instant_sales')->whereIn('parent_id', $invoiceRoots->pluck('id'))->get()->groupBy('parent_id');
        $invoiceLineIds = $invoiceRoots->pluck('id')->concat($children->flatten(1)->pluck('id'));
        $repairIds = $maintenance->filter(fn ($row) => $this->inPeriod($row->end_date ?: $row->updated_at, $from, $to))->keys()
            ->concat($invoiceRoots->pluck('maintenance_id')->filter())
            ->concat($maintenance->whereIn('instant_sale_id', $invoiceRoots->pluck('id'))->keys())->unique();
        $movements = Schema::hasTable('product_stock_movements')
            ? DB::table('product_stock_movements')->where('created_at', '<=', $to)
                ->where(fn ($q) => $q->whereBetween('created_at', [$from, $to])
                    ->orWhere(fn ($q) => $q->where('reference_type', 'instant_sale')->whereIn('reference_id', $invoiceLineIds))
                    ->orWhere(fn ($q) => $q->where('reference_type', 'sales_order')->whereIn('reference_id', $periodOrders->keys()))
                    ->orWhere(fn ($q) => $q->where('reference_type', 'maintenance')->whereIn('reference_id', $repairIds)))
                ->get()->groupBy(fn ($row) => ($row->reference_type ?? '').':'.($row->reference_id ?? ''))
            : collect();
        $stockInvoiceIds = $movements->flatten(1)->where('reference_type', 'instant_sale')->pluck('reference_id')->unique();
        foreach (DB::table('instant_sales')->whereIn('id', $stockInvoiceIds)->pluck('id') as $id) {
            $represented['instant_sale:'.$id] = true;
        }
        $maintenanceByInvoice = $maintenance->filter(fn ($row) => $row->instant_sale_id)->keyBy('instant_sale_id');
        $orderByInvoice = $orders->filter(fn ($row) => $row->instant_sale_id)->keyBy('instant_sale_id');

        foreach ($invoiceRoots as $invoice) {
            $order = $orders->get($invoice->sales_order_id) ?: $orderByInvoice->get($invoice->id);
            $repair = $maintenance->get($invoice->maintenance_id) ?: $maintenanceByInvoice->get($invoice->id);
            // A linked invoice represents its order/maintenance even when outside this period.
            if ($order && ($order->financial_posted_at || $order->is_debt_collection || $order->status === 'canceled')) {
                $represented['sales_order:'.$order->id] = true;
            }
            if ($repair && $repair->status === 'delivered') {
                $represented['maintenance:'.$repair->id] = true;
            }
            foreach (collect([$invoice])->concat($children->get($invoice->id, collect())) as $line) {
                $represented['instant_sale:'.$line->id] = true;
            }
            $source = $repair ? 'maintenance' : ($order ? 'order' : 'sale');
            $date = $order?->financial_posted_at ?: $invoice->created_at;
            if ($invoice->cancelled_at || $invoice->status === 'cancelled'
                || ($order && (! $order->financial_posted_at || $order->is_debt_collection || $order->status === 'canceled'))
                || ($repair && $repair->status !== 'delivered')
                || ! $this->inPeriod($date, $from, $to)) {
                continue;
            }
            $currency = $this->currency($boxes->get($invoice->payment_box_id));
            if (! $this->selected($source, $currency, $filters)) {
                continue;
            }
            $lines = [];
            $packageComponents = [];
            foreach (collect([$invoice])->concat($children->get($invoice->id, collect())) as $line) {
                if (! $line->product_id) {
                    continue;
                }
                $quantity = (float) $line->quantity;
                $cost = $line->inventory_total_cost === null ? null : (float) $line->inventory_total_cost;
                if (($invoice->sale_kind ?? null) === 'adjustment') {
                    $cost = 0.0;
                }
                $costRows = $movements->get($repair ? 'maintenance:'.$repair->id : ($order ? 'sales_order:'.$order->id : 'instant_sale:'.$line->id), collect());
                if ($cost === null) {
                    $cost = $this->movementCost($costRows, $line->product_id, $line->size_color_id, $quantity);
                }
                $lines[] = $this->line($line, $products, $sizes, $quantity,
                    $line->is_bonus ? 0.0 : (float) $line->cost * $quantity,
                    $currency === 'شيكل' ? $cost : null, (bool) $line->is_bonus);
                if (($invoice->offer_package_id ?? null) && ! $line->is_bonus && (float) $line->cost === 0.0) {
                    $packageComponents[] = array_key_last($lines);
                }
                $represented['instant_sale:'.$line->id] = true;
            }
            if (($invoice->offer_package_id ?? null)) {
                if ($packageComponents !== []) {
                    $quantity = array_sum(array_map(fn ($index) => $lines[$index]['quantity'], $packageComponents));
                    foreach ($packageComponents as $index) {
                        $lines[$index]['gross'] = $quantity > 0
                            ? (float) $invoice->cost * (float) $invoice->quantity * $lines[$index]['quantity'] / $quantity : 0.0;
                    }
                } else {
                    $lines[] = ['product_code' => '', 'product' => 'حزمة بدون تفصيل مكونات',
                        'quantity' => (float) $invoice->quantity, 'bonus' => 0.0, 'unit' => 'حزمة',
                        'gross' => (float) $invoice->cost * (float) $invoice->quantity, 'cost_total' => null];
                }
            }
            $documents[] = $this->builder->document($this->document('instant_sale:'.$invoice->id,
                $invoice->serial_number ?: 'SAL-'.$invoice->id, $date, $source,
                $source === 'maintenance' ? 'فاتورة صيانة' : ($source === 'order' ? 'فاتورة طلبية' : (($invoice->sale_kind ?? null) === 'adjustment' ? 'فاتورة تعويض' : 'فاتورة مبيعات')),
                (float) $invoice->total_cost, (float) $invoice->discount), $lines);
        }

        $orderItems = DB::table('sales_order_items')->whereIn('sales_order_id', $periodOrders->keys())->where('is_hidden', false)->get()->groupBy('sales_order_id');
        foreach ($orders as $order) {
            if (isset($represented['sales_order:'.$order->id]) || ! $order->financial_posted_at
                || $order->is_debt_collection || $order->status === 'canceled') {
                continue;
            }
            $represented['sales_order:'.$order->id] = true;
            if ($order->instant_sale_id) {
                continue;
            }
            if (! $this->inPeriod($order->financial_posted_at, $from, $to) || ! $this->selected('order', 'شيكل', $filters)) {
                continue;
            }
            $lines = [];
            foreach ($orderItems->get($order->id, collect()) as $item) {
                $quantity = (float) $item->quantity;
                $cost = $this->movementCost($movements->get('sales_order:'.$order->id, collect()), $item->product_id, $item->size_color_id, $quantity);
                $lines[] = $this->line($item, $products, $sizes, $quantity, (float) $item->line_total, $cost);
            }
            $documents[] = $this->builder->document($this->document('sales_order:'.$order->id,
                $order->serial_number ?: 'ORD-'.$order->id, $order->financial_posted_at, 'order', 'فاتورة طلبية',
                (float) $order->total, (float) $order->discount), $lines);
        }

        // Older delivered repairs may have parts and an invoice total without an InstantSale link.
        $parts = DB::table('maintenance_products')->whereIn('maintenance_id', $repairIds)->get()->groupBy('maintenance_id');
        foreach ($maintenance as $repair) {
            if (isset($represented['maintenance:'.$repair->id]) || $repair->status !== 'delivered') {
                continue;
            }
            $represented['maintenance:'.$repair->id] = true;
            if ($repair->instant_sale_id) {
                continue;
            }
            $date = $repair->end_date ?: $repair->updated_at;
            if (! $this->inPeriod($date, $from, $to) || ! $this->selected('maintenance', 'شيكل', $filters)) {
                continue;
            }
            $lines = [];
            foreach ($parts->get($repair->id, collect()) as $part) {
                $cost = $part->inventory_total_cost === null
                    ? $this->movementCost($movements->get('maintenance:'.$repair->id, collect()), $part->product_id, $part->size_color_id, (float) $part->quantity)
                    : (float) $part->inventory_total_cost;
                $lines[] = $this->line($part, $products, $sizes, (float) $part->quantity, (float) $part->line_total, $cost);
            }
            $documents[] = $this->builder->document($this->document('maintenance:'.$repair->id,
                'MNT-'.$repair->id, $date, 'maintenance', 'فاتورة صيانة', (float) $repair->invoice_total, (float) $repair->discount), $lines);
        }

        if (Schema::hasTable('profit_sales')) {
            foreach (DB::table('profit_sales')->whereNull('cancelled_at')->where(fn ($q) => $q->whereNull('status')->orWhere('status', '!=', 'cancelled'))
                ->whereBetween('created_at', [$from, $to])->get() as $sale) {
                if (! $this->selected('profit_sale', $this->currency($boxes->get($sale->payment_box_id)), $filters)) {
                    continue;
                }
                $documents[] = $this->builder->document($this->document('profit_sale:'.$sale->id,
                    'PRF-'.$sale->id, $sale->created_at, 'profit_sale', 'بيع ربحي', (float) $sale->total_cost, 0), []);
            }
        }

        if ($filters['include_returns']) {
            $returns = DB::table('sales_returns')->where('status', 'completed')->whereNull('cancelled_at')
                ->whereIn('return_type', ['direct', 'partial'])->whereBetween('completed_at', [$from, $to])->get();
            $items = DB::table('sales_return_items')->whereIn('sales_return_id', $returns->pluck('id'))->get()->groupBy('sales_return_id');
            $invoiceIds = $returns->pluck('instant_sale_id')->concat($items->flatten(1)->pluck('instant_sale_id'))->filter()->unique();
            $allInvoices = DB::table('instant_sales')->whereIn('id', $invoiceIds)->get()->keyBy('id');
            $allInvoices = $allInvoices->union(DB::table('instant_sales')->whereIn('id', $allInvoices->pluck('parent_id')->filter())->get()->keyBy('id'));
            foreach ($returns as $return) {
                $original = $allInvoices->get($return->instant_sale_id);
                if ($original?->parent_id) {
                    $original = $allInvoices->get($original->parent_id) ?: $original;
                }
                $source = $return->sales_order_id || $original?->sales_order_id || $orderByInvoice->has($original?->id)
                    ? 'order' : ($original?->maintenance_id || $maintenanceByInvoice->has($original?->id) ? 'maintenance' : 'sale');
                if (! $this->selected($source, $this->currency($return->currency), $filters)) {
                    continue;
                }
                $lines = [];
                foreach ($items->get($return->id, collect()) as $item) {
                    $lines[] = $this->line($item, $products, $sizes, (float) $item->quantity,
                        -(float) $item->line_total, $item->inventory_total_cost === null || $filters['currency'] !== 'شيكل'
                            ? null : -(float) $item->inventory_total_cost,
                        (bool) ($allInvoices->get($item->instant_sale_id)?->is_bonus ?? false));
                }
                if ($lines === []) {
                    $lines[] = ['product_code' => '', 'product' => 'مرتجع بدون تفصيل تكلفة', 'quantity' => 1.0,
                        'bonus' => 0.0, 'unit' => '', 'gross' => -(float) $return->total_amount, 'cost_total' => null];
                }
                $documents[] = $this->builder->document($this->document('sales_return:'.$return->id,
                    $return->serial_number ?: 'RET-'.$return->id, $return->completed_at, $source, 'مردودات '.match ($source) {
                        'order' => 'طلبية', 'maintenance' => 'صيانة', default => 'مبيعات',
                    }, -(float) $return->total_amount, 0), $lines);
            }
        }
        usort($documents, fn ($a, $b) => [$a['date'], $a['key']] <=> [$b['date'], $b['key']]);

        return ['filters' => $filters, 'documents' => $documents, 'summary' => $this->builder->summary($documents),
            'withdrawals' => $this->unbilledWithdrawals($movements, $represented, $products, $sizes, $filters, $from, $to),
            'notes' => [
                'يشمل البيع والطلبيات المرحّلة ماليًا والصيانة المسلّمة والبيع الربحي. الفاتورة المرتبطة بطلبية أو صيانة تُحتسب مرة واحدة. الملغى وطلبات تحصيل الدين مستبعدة.',
                'الربح إجمالي قبل المصاريف والرواتب ورسوم شركات التوصيل. الربح = البيع بعد الخصم − تكلفة المخزون المسجلة. نسبة الربح = الربح ÷ القيمة المطلقة للتكلفة × 100؛ عند تكلفة صفر تظهر —.',
                'تُوزّع خصومات الفاتورة على بنودها لتطابق أرباح التفصيلي والإجمالي. البونص إيراده صفر وتكلفته ضمن تكلفة الفاتورة. الخدمات والإضافات تظهر في بند مستقل.',
                'قيمة الحزمة تُوزّع على مكوناتها حسب الكمية؛ ربح الفاتورة لا يتغير بهذا التوزيع. عمود الوحدة يعرض المقاس المسجل إن وجد.',
                'المرتجعات بالسالب حسب تاريخ اعتمادها، وقد تخص مبيعات من فترة سابقة. تكلفة المرتجع هي التكلفة المحفوظة فيه.',
                '— تعني تكلفة غير مكتملة أو غير قابلة للمقارنة. لا تُستبدل تكلفة تاريخية مفقودة بسعر الشراء الحالي. تكلفة المخزون بالشيكل؛ لا يُطرح الشيكل من مبيعات بعملة أخرى.',
                'السحوبات غير الممثلة بمستند بيع معتمد للفترة تظهر في كشف مستقل حسب تاريخ حركة المخزون، خارج إجمالي الأرباح؛ قد تشمل حركات داخلية أو تسويات وليست مبيعات.',
            ]];
    }

    private function document(string $key, string $number, string $date, string $source, string $type, float $sales, float $discount): array
    {
        return ['key' => $key, 'number' => $number, 'date' => substr($date, 0, 10), 'source' => $source,
            'type' => $type, 'sales_total' => $sales, 'discount' => $discount];
    }

    private function line(object $line, Collection $products, Collection $sizes, float $quantity, float $gross, ?float $cost, bool $bonus = false): array
    {
        $product = $products->get($line->product_id);
        $name = ($line->product_name ?? null) ?: ($product?->nameAr ?: $product?->nameEng ?: 'صنف #'.$line->product_id);
        $color = trim((string) $this->colors->get($line->size_color_id));
        if ($color !== '' && ! str_contains($name, $color)) {
            $name .= ' / '.$color;
        }

        return ['product_code' => (string) ($product?->product_code ?: $line->product_id),
            'product' => $name, 'color' => $color,
            'quantity' => $quantity, 'bonus' => $bonus ? $quantity : 0.0,
            'unit' => (string) $sizes->get($line->size_id), 'gross' => $gross, 'cost_total' => $cost];
    }

    private function movementCost(Collection $rows, int $productId, ?int $variant, float $quantity): ?float
    {
        $rows = $rows->filter(fn ($row) => (int) $row->product_id === $productId
            && (int) $row->size_color_id === (int) $variant && (float) $row->quantity != 0);
        $consumed = -(float) $rows->sum('quantity');
        if ($consumed < $quantity - 0.0001 || $consumed <= 0 || $rows->contains(fn ($row) => $row->total_cost === null)) {
            return null;
        }
        $cost = $rows->sum(fn ($row) => $row->quantity < 0 ? (float) $row->total_cost : -(float) $row->total_cost);

        return $cost < -0.0001 ? null : round(max(0, $cost) / $consumed * $quantity, 6);
    }

    private function unbilledWithdrawals(Collection $movements, array $represented, Collection $products, Collection $sizes, array $filters, string $from, string $to): array
    {
        if ($filters['currency'] !== 'شيكل') {
            return [];
        }
        $rows = [];
        foreach ($movements as $reference => $group) {
            if (isset($represented[$reference])) {
                continue;
            }
            $source = match ($group->first()->reference_type) {
                'sales_order' => 'order', 'maintenance' => 'maintenance', 'instant_sale' => 'sale', default => 'stock'
            };
            if (! $this->selected($source, 'شيكل', $filters)) {
                continue;
            }
            foreach ($group as $movement) {
                // Returns and cancellations belong to their original operation, not to an unbilled sale.
                if ($movement->quantity >= 0 || in_array($movement->type, ['sales_return_cancel', 'purchase_invoice_delete', 'purchase_delete_return_restore'], true)
                    || in_array($movement->reference_type, ['sales_return', 'sales_return_cancel', 'purchase_return', 'purchase_invoice_delete'], true)
                    || ! $this->inPeriod($movement->created_at, $from, $to)) {
                    continue;
                }
                $rows[] = ['number' => (string) $movement->id, 'date' => substr($movement->created_at, 0, 10),
                    'type' => match ($source) {
                        'order' => 'سحب طلبية #'.$movement->reference_id,
                        'maintenance' => 'سحب صيانة #'.$movement->reference_id,
                        'sale' => 'سحب بيع غير مرتبط بفاتورة',
                        default => match ($movement->type) {
                            'destruction' => 'إتلاف',
                            'assembly_component', 'disassembly_output' => 'سحب تجميع أو تفكيك',
                            'stock_adjustment_out', 'manual_set' => 'تسوية مخزون',
                            default => 'سحب مخزون آخر',
                        },
                    },
                    ...$this->line($movement, $products, $sizes, abs((float) $movement->quantity), 0,
                        $movement->total_cost === null ? null : (float) $movement->total_cost),
                    'note' => $movement->note ?? ''];
            }
        }

        return $rows;
    }

    private function inPeriod(?string $date, string $from, string $to): bool
    {
        return $date !== null && substr($date, 0, 10) >= substr($from, 0, 10) && substr($date, 0, 10) <= substr($to, 0, 10);
    }

    private function selected(string $source, string $currency, array $filters): bool
    {
        return ($filters['source'] === 'all' || $filters['source'] === $source) && $currency === $filters['currency'];
    }

    private function currency(?string $value): string
    {
        return match (mb_strtolower(trim((string) $value))) {
            'usd', 'dollar', 'دولار' => 'دولار', 'jod', 'dinar', 'دينار' => 'دينار',
            'nis', 'ils', 'shekel', 'شيكل', '' => 'شيكل', default => (string) $value,
        };
    }
}
