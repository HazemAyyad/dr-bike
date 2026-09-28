<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Seller;

class OutgoingCheck extends Model
{
    use HasFactory;
    protected $table = 'outgoing_checks';

    protected $fillable = [
        'customer_id',
        'parent_outgoing_check_id',
        'origin_installment_id',
        'status',
        'settlement_status',
        'restructured_at',
        'total',
        'due_date',
        'currency',
        'check_id',
        'bank_name',
        'img',
        'back_image',
        'seller_id',
        'box_id',
        'notes',
        'batch_number',
    ];

    protected $casts = ['restructured_at' => 'datetime'];

    protected $appends = ['settled_amount', 'remaining_amount'];

    public function settlements(){ return $this->hasMany(OutgoingCheckSettlement::class); }
    public function installments(){ return $this->hasMany(OutgoingCheckInstallment::class); }
    public function parentCheck(){ return $this->belongsTo(self::class, 'parent_outgoing_check_id'); }
    public function scheduledChecks(){ return $this->hasMany(self::class, 'parent_outgoing_check_id'); }
    public function originInstallment(){ return $this->belongsTo(OutgoingCheckInstallment::class, 'origin_installment_id'); }

    public function getSettledAmountAttribute(): float
    {
        return round((float) ($this->relationLoaded('settlements')
            ? $this->settlements->sum('amount')
            : $this->settlements()->sum('amount')), 4);
    }

    public function getRemainingAmountAttribute(): float
    {
        return max(0, round((float) $this->total - $this->settled_amount, 4));
    }

    /**
     * Get the customer that owns the check.
     */
    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function seller(){
        return $this->belongsTo(Seller::class);
    }

    // how many checks are there
    public static function checksCount(){
       return OutgoingCheck::count();  
    }

    //total checks values
    public static function totalAmount(){
        return static::openChecks()->get()->sum('remaining_amount');
    }

    public static function openChecks()
    {
        return static::query()
            ->whereIn('status', ['not_cashed', 'partially_settled', 'restructured'])
            ->with('settlements');
    }

    // data for first page for both incoming and outgoing checks
    public static function generalChecksData(){
        $ordinaryOpenOutgoing = OutgoingCheck::query()
            ->where('status', 'not_cashed')
            ->whereNull('parent_outgoing_check_id');
        $totalOutgoingChecksNotCashedCount = (clone $ordinaryOpenOutgoing)->count();

        $totalOutgoingChecksCashedCount = OutgoingCheck::
        where('status','cashed_to_person')->count();

        $totalIncomingChecksNotCashedCount = IncomingCheck::
        where('status','not_cashed')->count();

        $totalIncomingChecksCashedCount = IncomingCheck::
        where('status','cashed_to_person')->count();

        $totalIncomingChecksCashedToBoxCount = IncomingCheck::
        where('status','cashed_to_box')->count();

        // Checks that were actually paid/cashed. Keep this separate from
        // "cashed_to_person", which only means the check was handed over.
        $paidOutgoingChecks = OutgoingCheck::query()
            ->whereIn('status', ['cashed_from_box', 'settled']);

        // The headline "not cashed" totals contain ordinary checks only.
        // Partial parents and scheduled instruments are reported separately.
        $openOutgoing = $ordinaryOpenOutgoing->get();
        $totalOutgoingChecksDollar = $openOutgoing->where('currency','دولار')->sum('remaining_amount');
        $totalOutgoingChecksDinar = $openOutgoing->where('currency','دينار')->sum('remaining_amount');
        $totalOutgoingChecksShekel = $openOutgoing->where('currency','شيكل')->sum('remaining_amount');

        $totalIncomingChecksDollar = IncomingCheck::where('currency','دولار')
        ->where('status','not_cashed')->sum('total');

        $totalIncomingChecksDinar = IncomingCheck::where('currency','دينار')
        ->where('status','not_cashed')->sum('total');

        $totalIncomingChecksShekel = IncomingCheck::where('currency','شيكل')
        ->where('status','not_cashed')->sum('total');

        return [
            'not_cashed_outgoing_checks_count' => $totalOutgoingChecksNotCashedCount,
            'cashed_outgoing_checks_count' => $totalOutgoingChecksCashedCount,

            'not_cashed_incoming_checks_count' => $totalIncomingChecksNotCashedCount,
            'cashed_incoming_checks_count' => $totalIncomingChecksCashedCount,
            'cashed_to_box_incoming_checks_count' => $totalIncomingChecksCashedToBoxCount,



            'total_outgoing_checks_dollar' => $totalOutgoingChecksDollar,
            'total_outgoing_checks_dinar' => $totalOutgoingChecksDinar,
            'total_outgoing_checks_shekel' => $totalOutgoingChecksShekel,
            'partially_settled_outgoing_checks_count' => OutgoingCheck::where('status', 'partially_settled')->count(),
            'restructured_outgoing_checks_count' => OutgoingCheck::whereIn('status', ['restructured', 'restructured_parent'])->count(),
            'pending_outgoing_installments_count' => OutgoingCheckInstallment::where('status', 'pending')->count(),
            'scheduled_outgoing_checks_count' => OutgoingCheckInstallment::whereIn('status', ['pending', 'materialized'])->count(),
            'scheduled_outgoing_checks_shekel' => self::scheduledInstallmentsTotal('شيكل'),
            'scheduled_outgoing_checks_dollar' => self::scheduledInstallmentsTotal('دولار'),
            'scheduled_outgoing_checks_dinar' => self::scheduledInstallmentsTotal('دينار'),
            'partially_paid_outgoing_checks_count' => OutgoingCheck::whereNull('parent_outgoing_check_id')
                ->whereIn('status', ['partially_settled', 'restructured', 'restructured_parent'])->count(),
            'paid_outgoing_checks_count' => (clone $paidOutgoingChecks)->count(),
            'paid_outgoing_checks_shekel' => (float) (clone $paidOutgoingChecks)
                ->where('currency', 'شيكل')->sum('total'),
            'paid_outgoing_checks_dollar' => (float) (clone $paidOutgoingChecks)
                ->where('currency', 'دولار')->sum('total'),
            'paid_outgoing_checks_dinar' => (float) (clone $paidOutgoingChecks)
                ->where('currency', 'دينار')->sum('total'),
            'settled_outgoing_checks_shekel' => (float) OutgoingCheckSettlement::query()
                ->whereHas('check', fn ($query) => $query->where('currency', 'شيكل'))->sum('amount'),
            'settled_outgoing_checks_dollar' => (float) OutgoingCheckSettlement::query()
                ->whereHas('check', fn ($query) => $query->where('currency', 'دولار'))->sum('amount'),
            'settled_outgoing_checks_dinar' => (float) OutgoingCheckSettlement::query()
                ->whereHas('check', fn ($query) => $query->where('currency', 'دينار'))->sum('amount'),

            'total_incoming_checks_dollar' => $totalIncomingChecksDollar,
            'total_incoming_checks_dinar' => $totalIncomingChecksDinar,
            'total_incoming_checks_shekel' => $totalIncomingChecksShekel,

        ];
    }

    private static function scheduledInstallmentsTotal(string $currency): float
    {
        return (float) OutgoingCheckInstallment::query()
            ->whereIn('status', ['pending', 'materialized'])
            ->whereHas('check', fn ($query) => $query->where('currency', $currency))
            ->sum('amount');
    }
}
