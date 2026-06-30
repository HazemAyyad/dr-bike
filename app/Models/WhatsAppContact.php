<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsAppContact extends Model
{
    protected $table = 'whatsapp_contacts';

    protected $fillable = ['name', 'phone', 'customer_id', 'supplier_id', 'employee_id', 'last_message_at'];
    protected $casts = ['last_message_at' => 'datetime'];

    public function conversations() { return $this->hasMany(WhatsAppConversation::class); }
    public function messages() { return $this->hasMany(WhatsAppMessage::class); }
    public function customer() { return $this->belongsTo(Customer::class); }
    public function supplier() { return $this->belongsTo(Seller::class, 'supplier_id'); }
    public function employee() { return $this->belongsTo(User::class, 'employee_id'); }
}
