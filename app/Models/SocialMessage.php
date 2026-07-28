<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SocialMessage extends Model
{
    protected $fillable = [
        'social_conversation_id', 'social_contact_id', 'channel',
        'external_sender_id', 'external_recipient_id', 'direction',
        'message_type', 'body', 'media_url', 'meta_message_id', 'meta_status',
        'raw_payload', 'response_payload', 'status', 'error_message', 'sent_by',
    ];

    protected $casts = [
        'raw_payload' => 'array',
        'response_payload' => 'array',
    ];

    public function conversation() { return $this->belongsTo(SocialConversation::class, 'social_conversation_id'); }
    public function contact() { return $this->belongsTo(SocialContact::class, 'social_contact_id'); }
    public function sender() { return $this->belongsTo(User::class, 'sent_by'); }
}
