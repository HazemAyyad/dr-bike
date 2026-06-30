<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsAppConversation extends Model
{
    protected $table = 'whatsapp_conversations';

    protected $fillable = ['whatsapp_contact_id', 'phone', 'status', 'assigned_admin_id', 'last_message', 'last_message_at', 'unread_count'];
    protected $casts = ['last_message_at' => 'datetime', 'unread_count' => 'integer'];

    public function contact() { return $this->belongsTo(WhatsAppContact::class, 'whatsapp_contact_id'); }
    public function messages() { return $this->hasMany(WhatsAppMessage::class, 'whatsapp_conversation_id'); }
    public function assignedAdmin() { return $this->belongsTo(User::class, 'assigned_admin_id'); }
}
