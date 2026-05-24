<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FollowupActivityLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'followup_id',
        'user_id',
        'action',
        'description',
        'changes',
    ];

    protected $casts = [
        'changes' => 'array',
    ];

    public function followup()
    {
        return $this->belongsTo(Followup::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
