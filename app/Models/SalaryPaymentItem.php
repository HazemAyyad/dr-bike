<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SalaryPaymentItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'batch_id', 'salary_period_id', 'employee_id', 'amount_paid',
        'remaining_before', 'remaining_after', 'receipt_status', 'received_at',
        'employee_signature_path', 'employee_signature_original_path',
        'employee_signature_id',
        'employee_signature_name', 'employee_signature_source',
        'employee_signature_hash', 'receipt_hash',
        'acknowledgment_ip', 'acknowledgment_device', 'dispute_reason', 'disputed_at',
    ];

    protected $casts = [
        'amount_paid' => 'decimal:2', 'remaining_before' => 'decimal:2',
        'remaining_after' => 'decimal:2', 'received_at' => 'datetime', 'disputed_at' => 'datetime',
    ];

    public function batch() { return $this->belongsTo(SalaryPaymentBatch::class, 'batch_id'); }
    public function salaryPeriod() { return $this->belongsTo(EmployeeSalaryPeriod::class, 'salary_period_id'); }
    public function employee() { return $this->belongsTo(EmployeeDetail::class); }
    public function employeeSignature() { return $this->belongsTo(EmployeeSignature::class, 'employee_signature_id'); }
}
