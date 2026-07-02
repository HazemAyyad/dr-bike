<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsAppMessage extends Model
{
    protected $table = 'whatsapp_messages';

    protected $fillable = [
        'whatsapp_conversation_id', 'whatsapp_contact_id', 'phone', 'direction', 'message_type',
        'body', 'template_name', 'media_url', 'meta_message_id', 'meta_status', 'raw_payload',
        'response_payload', 'status', 'error_message', 'sent_by', 'is_automatic',
        'customer_deleted_at',
        'reply_to_message_id', 'reply_to_meta_message_id',
    ];
    protected $casts = [
        'raw_payload' => 'array',
        'response_payload' => 'array',
        'is_automatic' => 'boolean',
        'customer_deleted_at' => 'datetime',
    ];

    public function conversation() { return $this->belongsTo(WhatsAppConversation::class, 'whatsapp_conversation_id'); }
    public function contact() { return $this->belongsTo(WhatsAppContact::class, 'whatsapp_contact_id'); }
    public function sender() { return $this->belongsTo(User::class, 'sent_by'); }
    public function replyTo() { return $this->belongsTo(self::class, 'reply_to_message_id'); }
}
