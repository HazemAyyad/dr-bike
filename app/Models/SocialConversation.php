<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SocialConversation extends Model
{
    protected $fillable = [
        'social_contact_id', 'channel', 'external_thread_id', 'status',
        'assigned_admin_id', 'last_message', 'last_message_at', 'unread_count',
    ];

    protected $casts = [
        'last_message_at' => 'datetime',
        'unread_count' => 'integer',
    ];

    public function contact() { return $this->belongsTo(SocialContact::class, 'social_contact_id'); }
    public function messages() { return $this->hasMany(SocialMessage::class); }
    public function assignedAdmin() { return $this->belongsTo(User::class, 'assigned_admin_id'); }
}
