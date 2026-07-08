<?php

namespace App\Services;

use App\Models\Maintenance;

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
            'instantSale:id,serial_number,created_at,payment_box_name,payment_box_value',
        ]);

        $billing = $this->deliveryService->formatProductsSummary($maintenance);
        $person = $maintenance->customer_id ? $maintenance->customer : $maintenance->seller;
        $invoiceTotal = (float) $billing['invoice_total'];
        $paidAmount = (float) $billing['paid_amount'];
        $remainingAmount = max(0, round($invoiceTotal - $paidAmount, 2));
        $paymentStatus = $invoiceTotal <= 0 || $paidAmount >= $invoiceTotal
            ? 'paid'
            : ($paidAmount > 0 ? 'partial' : 'unpaid');

        $invoiceDate = $maintenance->instantSale?->created_at ?? $maintenance->updated_at;

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
            'paid_amount' => $billing['paid_amount'],
            'remaining_amount' => $remainingAmount,
            'instant_sale_id' => $maintenance->instant_sale_id,
            'instant_sale_serial' => $maintenance->instantSale?->serial_number,
            'payment_box_name' => $maintenance->instantSale?->payment_box_name,
            'payment_box_value' => $maintenance->instantSale?->payment_box_value,
        ];
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
