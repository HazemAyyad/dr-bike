<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Goal extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'type',
        'achievement_percentage',
        'current_value', //
        'targeted_value',
        'notes',
        'customer_id',
        'product_id',
        'employee_id',
        'is_canceled',


        'form', // الصيغة values:
        //  total_profit_values / net_profit / sell_pieces /
        // purchase_pieces / finish_tasks / pay_person / deposit_to_box

        'scope', // values : main_categories / sub_categories / product / employee / person / box
        'seller_id','box_id',
        'due_date',
    ];

    protected $casts = [
    'created_at' => 'datetime:Y-m-d',
    'due_date' => 'datetime:Y-m-d',

];

    /**
     * Get the user who owns the goal.
     */


        public function box()
    {
        return $this->belongsTo(Box::class);
    }
    /**
     * Get the product associated with the goal.
     */


    public function employee(){
        return $this->belongsTo(EmployeeDetail::class,'employee_id');
    }

    public function logs(){
        return $this->hasMany(GoalLog::class);
    }
}
