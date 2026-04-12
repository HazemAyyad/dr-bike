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
        'status',
        'total',
        'due_date',
        'currency',
        'check_id',
        'bank_name',
        'img',
        'seller_id',
        'notes',
    ];

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
        return OutgoingCheck::where('status','not_cashed')->sum('total');
    }

    // data for first page for both incoming and outgoing checks
    public static function generalChecksData(){
        $totalOutgoingChecksNotCashedCount = OutgoingCheck::
        where('status','not_cashed')->count();

        $totalOutgoingChecksCashedCount = OutgoingCheck::
        where('status','cashed_to_person')->count();

        $totalIncomingChecksNotCashedCount = IncomingCheck::
        where('status','not_cashed')->count();

        $totalIncomingChecksCashedCount = IncomingCheck::
        where('status','cashed_to_person')->count();

        $totalIncomingChecksCashedToBoxCount = IncomingCheck::
        where('status','cashed_to_box')->count();

        $totalOutgoingChecksDollar = OutgoingCheck::where('currency','دولار')
        ->where('status','not_cashed')->sum('total');

        $totalOutgoingChecksDinar = OutgoingCheck::where('currency','دينار')
        ->where('status','not_cashed')->sum('total');

        $totalOutgoingChecksShekel = OutgoingCheck::where('currency','شيكل')
        ->where('status','not_cashed')->sum('total');

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

            'total_incoming_checks_dollar' => $totalIncomingChecksDollar,
            'total_incoming_checks_dinar' => $totalIncomingChecksDinar,
            'total_incoming_checks_shekel' => $totalIncomingChecksShekel,

        ];
    }
}
