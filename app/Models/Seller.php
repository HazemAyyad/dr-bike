<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Seller extends Model
{
    use HasFactory;

    protected $table = 'sellers';
        protected $fillable = [
        'name',
        'address',
        'phone',
        'job_title',
        'type',
        'facebook_username',
        'facebook_link',
        'instagram_username',
        'instagram_link',
        'related_people',
        'ID_image',
        'license_image',
        'work_address',
        'relative_phone',
        'relative_job_title',
        'notes',
        'is_canceled',
        'sub_phone',

    ];

    protected $casts = ['ID_image'=>'array',
    'license_image'=>'array'];

    public function outgoingChecks(){
        return $this->hasMany(OutgoingCheck::class);
    }

        public function sentIncomingChecks()
    {
        return $this->hasMany(IncomingCheck::class, 'from_seller');
    }

    // Incoming checks where the seller is the recipient
    public function receivedIncomingChecks()
    {
        return $this->hasMany(IncomingCheck::class, 'to_seller');
    }

    public function bills(){
        return $this->hasMany(Bill::class);
    }

    public function debts(){
        return $this->hasMany(Debt::class);
    }
    public function returns(){
        return $this->hasMany(ReturnModel::class);
    }
}
