<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    use HasFactory;
    protected $fillable = [
        'name',//
        'address',//
        'phone',//
        'job_title',//
        'type',//
        'facebook_username',//
        'facebook_link',//
        'instagram_username',//
        'instagram_link',//
        'related_people',//
        'ID_image',//
        'license_image',//
        'work_address',//
        'relative_phone',//
        'relative_job_title',//

        'sub_phone',//
        'notes',
        'is_canceled',


    ];
    
    protected $casts = ['ID_image'=>'array',
    'license_image'=>'array'];

    public function orders(){
        return $this->hasMany(Order::class);
    }

    public function maintenances(){
        return $this->hasMany(Maintenance::class);
    }

    public function followups(){
        return $this->hasMany(Followup::class);
    }

    public function instantBuyings(){
        return $this->hasMany(InstantBuying::class);
    }

    public function debts(){
        return $this->hasMany(Debt::class);
    }

    public function goals(){
        return $this->hasMany(Goal::class);
    }

    public function draws(){
        return $this->hasMany(Draw::class);
    }

    public function deposits(){
        return $this->hasMany(Deposit::class);
    }

    public function outgoingChecks(){
        return $this->hasMany(OutgoingCheck::class);

    }

        public function sentIncomingChecks()
    {
        return $this->hasMany(IncomingCheck::class, 'from_customer');
    }

    // Incoming checks where the customer is the recipient
    public function receivedIncomingChecks()
    {
        return $this->hasMany(IncomingCheck::class, 'to_customer');
    }
}
