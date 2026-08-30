<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SalaryPaymentBatch extends Model
{
    use HasFactory;

    protected $fillable = [
        'salary_month', 'box_id', 'box_log_id', 'payment_date', 'gross_total',
        'advances_total', 'cash_total', 'notes', 'invoice_img', 'media',
        'created_by_user_id', 'status',
    ];

    protected $casts = [
        'salary_month' => 'date:Y-m-d', 'payment_date' => 'date:Y-m-d',
        'invoice_img' => 'array', 'media' => 'array',
        'gross_total' => 'decimal:2', 'advances_total' => 'decimal:2', 'cash_total' => 'decimal:2',
    ];

    public function box() { return $this->belongsTo(Box::class); }
    public function boxLog() { return $this->belongsTo(BoxLog::class); }
    public function creator() { return $this->belongsTo(User::class, 'created_by_user_id'); }
    public function items() { return $this->hasMany(SalaryPaymentItem::class, 'batch_id'); }
}
