<?php

namespace App\Services;

use App\Models\Maintenance;
use App\Models\MaintenanceService;

class MaintenanceInvoiceService
{
    public function __construct(
        protected MaintenanceDeliveryService $deliveryService
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(Maintenance $maintenance): array
    {
        $maintenance->loadMissing([
            'customer:id,name,phone,address',
            'seller:id,name,phone,address',
            'products.product:id,nameAr,nameEng',
            'payments',
            'instantSale:id,serial_number,created_at,payment_box_name,payment_box_value',
        ]);

        $billing = $this->deliveryService->formatProductsSummary($maintenance);
        $person = $maintenance->customer_id ? $maintenance->customer : $maintenance->seller;
        $invoiceTotal = (float) $billing['invoice_total'];
        $paymentsTotal = round((float) $maintenance->payments->sum('amount'), 2);
        $instantSalePaid = round((float) ($maintenance->instantSale?->payment_box_value ?? 0), 2);
        $paidAmount = min(
            $invoiceTotal,
            max((float) $billing['paid_amount'], $paymentsTotal, $instantSalePaid)
        );
        $remainingAmount = max(0, round($invoiceTotal - $paidAmount, 2));
        $paymentStatus = $invoiceTotal <= 0 || $paidAmount >= $invoiceTotal
            ? 'paid'
            : ($paidAmount > 0 ? 'partial' : 'unpaid');
        $hasDebt = $maintenance->status === 'delivered' && $remainingAmount > 0;

        $invoiceDate = $maintenance->instantSale?->created_at ?? $maintenance->updated_at;
        [$services, $notes] = $this->extractServicesAndNotes((string) $maintenance->description);

        return [
            'maintenance_id' => $maintenance->id,
            'invoice_number' => 'MNT-'.str_pad((string) $maintenance->id, 6, '0', STR_PAD_LEFT),
            'invoice_date' => optional($invoiceDate)->format('Y-m-d H:i:s'),
            'invoice_date_display' => $this->formatArabicDateTime($invoiceDate),
            'status' => $maintenance->status,
            'receipt_date' => $maintenance->receipt_date,
            'receipt_time' => $maintenance->receipt_time,
            'receipt_datetime_display' => $this->formatArabicDateAndTime(
                $maintenance->receipt_date,
                $maintenance->receipt_time
            ),
            'description' => $maintenance->description,
            'notes' => $notes,
            'services' => $services,
            'maintenance_status_label' => $this->maintenanceStatusLabel((string) $maintenance->status),
            'payment_status' => $paymentStatus,
            'payment_status_label' => $this->paymentStatusLabel($paymentStatus),
            'customer_type' => $maintenance->customer_id ? 'customer' : 'seller',
            'customer_type_label' => $maintenance->customer_id ? 'زبون' : 'تاجر',
            'customer_name' => $person?->name ?? '-',
            'customer_phone' => $person?->phone,
            'customer_address' => $person?->address,
            'items' => $billing['items'],
            'parts_total' => $billing['parts_total'],
            'labor_cost' => $billing['labor_cost'],
            'discount' => $billing['discount'],
            'invoice_total' => $billing['invoice_total'],
            'paid_amount' => $paidAmount,
            'remaining_amount' => $remainingAmount,
            'debt_amount' => $hasDebt ? $remainingAmount : 0,
            'has_debt' => $hasDebt,
            'currency' => 'شيكل',
            'payments' => $billing['payments'] ?? [],
            'instant_sale_id' => $maintenance->instant_sale_id,
            'instant_sale_serial' => $maintenance->instantSale?->serial_number,
            'payment_box_name' => $maintenance->instantSale?->payment_box_name,
            'payment_box_value' => $maintenance->instantSale?->payment_box_value,
            'qr_payload' => 'doctorbike://maintenance/invoice/'.$maintenance->id,
        ];
    }

    /**
     * @return array{0: array<int, array{id: int, name: string, price: float}>, 1: string}
     */
    private function extractServicesAndNotes(string $description): array
    {
        $description = trim($description);
        if ($description === '') {
            return [[], ''];
        }

        $descriptionLines = collect(preg_split('/\R/u', $description) ?: [])
            ->map(fn ($line) => trim((string) $line))
            ->filter()
            ->values();

        $catalog = MaintenanceService::query()
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name', 'price']);

        $services = $catalog
            ->filter(fn (MaintenanceService $service) => str_contains($description, (string) $service->name))
            ->map(function (MaintenanceService $service) use ($descriptionLines) {
                $line = $descriptionLines->first(
                    fn (string $candidate) => str_contains($candidate, (string) $service->name)
                );
                $price = (float) $service->price;
                if (is_string($line) && preg_match('/-\s*([0-9,.]+)\s*$/u', $line, $matches) === 1) {
                    $price = (float) str_replace(',', '', $matches[1]);
                }

                return [
                    'id' => (int) $service->id,
                    'name' => (string) $service->name,
                    'price' => round($price, 2),
                ];
            })
            ->values()
            ->all();

        $serviceNames = collect($services)->pluck('name')->filter()->values();
        $notes = $descriptionLines
            ->reject(fn (string $line) => $serviceNames->contains(
                fn (string $name) => str_contains($line, $name)
            ))
            ->implode("\n");

        return [$services, $notes];
    }

    private function maintenanceStatusLabel(string $status): string
    {
        return match ($status) {
            'new' => 'صيانة جديدة',
            'ongoing' => 'قيد العمل',
            'ready' => 'جاهزة للتسليم',
            'delivered' => 'تم التسليم',
            default => $status,
        };
    }

    private function paymentStatusLabel(string $status): string
    {
        return match ($status) {
            'paid' => 'تم الدفع',
            'partial' => 'مدفوع جزئياً',
            'unpaid' => 'غير مدفوعة',
            default => $status,
        };
    }

    private function formatArabicDateTime($date): ?string
    {
        if (! $date) {
            return null;
        }

        $suffix = $date->format('A') === 'AM' ? 'صباحاً' : 'مساءً';

        return $date->format('Y-m-d h:i').' '.$suffix;
    }

    private function formatArabicDateAndTime(?string $date, ?string $time): ?string
    {
        if (! $date) {
            return null;
        }

        if (! $time) {
            return $date;
        }

        $parts = explode(':', $time);
        $hour = isset($parts[0]) ? (int) $parts[0] : 0;
        $minute = isset($parts[1]) ? (int) $parts[1] : 0;
        $suffix = $hour < 12 ? 'صباحاً' : 'مساءً';
        $displayHour = $hour % 12;
        $displayHour = $displayHour === 0 ? 12 : $displayHour;

        return sprintf('%s %02d:%02d %s', $date, $displayHour, $minute, $suffix);
    }
}
