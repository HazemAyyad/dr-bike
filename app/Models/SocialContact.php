<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SocialContact extends Model
{
    protected $fillable = [
        'channel', 'external_id', 'name', 'profile_picture_url', 'customer_id',
        'supplier_id', 'employee_id', 'last_message_at', 'raw_profile',
    ];

    protected $casts = [
        'last_message_at' => 'datetime',
        'raw_profile' => 'array',
    ];

    public function conversations() { return $this->hasMany(SocialConversation::class); }
    public function messages() { return $this->hasMany(SocialMessage::class); }
    public function customer() { return $this->belongsTo(Customer::class); }
    public function supplier() { return $this->belongsTo(Seller::class, 'supplier_id'); }
    public function employee() { return $this->belongsTo(User::class, 'employee_id'); }
}
