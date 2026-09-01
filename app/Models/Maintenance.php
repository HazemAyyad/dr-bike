<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Maintenance extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'maintenance';

    protected $fillable = [
        'customer_id',
        'description',
        'status',
        'receipt_date',
        'end_date',
        'receipt_time',
        'files',
        'seller_id',
        'labor_cost',
        'discount',
        'invoice_total',
        'paid_amount',
        'service_lines',
        'additional_charges',
        'payment_box_id',
        'maintenance_daily_session_id',
        'instant_sale_id',
    ];

    protected $casts = [
        'files' => 'array',
        'labor_cost' => 'float',
        'discount' => 'float',
        'invoice_total' => 'float',
        'paid_amount' => 'float',
        'service_lines' => 'array',
        'additional_charges' => 'array',
    ];


    /**
     * Get the customer associated with the maintenance.
     */
    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
    public function seller()
    {
        return $this->belongsTo(Seller::class);
    }

    public function products()
    {
        return $this->hasMany(MaintenanceProduct::class);
    }

    public function activityLogs()
    {
        return $this->hasMany(MaintenanceActivityLog::class);
    }

    public function instantSale()
    {
        return $this->belongsTo(InstantSale::class);
    }

    public function maintenanceDailySession()
    {
        return $this->belongsTo(MaintenanceDailySession::class);
    }

    public function payments()
    {
        return $this->hasMany(MaintenancePayment::class);
    }
}
