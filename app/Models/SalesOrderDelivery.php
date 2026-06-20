<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesOrderDelivery extends Model
{
    protected $fillable = [
        'sales_order_id',
        'sales_order_package_id',
        'delivery_company_id',
        'delivery_company_name',
        'tracking_number',
        'carrier_contact_name',
        'carrier_contact_phone',
        'carrier_office_name',
        'carrier_vehicle_number',
        'external_reference',
        'handed_over_by_user_id',
        'shiply_employee_email',
        'shiply_mode',
        'shiply_parcel_code',
        'handed_over_at',
        'delivered_at',
    ];

    protected $casts = [
        'handed_over_at' => 'datetime',
        'delivered_at' => 'datetime',
    ];

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }
}
