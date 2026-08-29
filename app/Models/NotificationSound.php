<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NotificationSound extends Model
{
    protected $fillable = [
        'key', 'name', 'source', 'category', 'description', 'android_resource',
        'ios_filename', 'file_path', 'mime_type', 'file_size', 'duration_ms',
        'checksum', 'version', 'is_active', 'background_capable', 'uploaded_by',
        'fallback_sound_id',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'background_capable' => 'boolean',
    ];

    protected $hidden = ['file_path'];

    protected $appends = ['preview_url'];

    public function fallback(): BelongsTo
    {
        return $this->belongsTo(self::class, 'fallback_sound_id');
    }

    public function policies(): HasMany
    {
        return $this->hasMany(NotificationPolicy::class, 'sound_id');
    }

    public function getPreviewUrlAttribute(): ?string
    {
        return $this->source === 'uploaded'
            ? url('/api/admin/notification-sounds/'.$this->id.'/file')
            : null;
    }
}
