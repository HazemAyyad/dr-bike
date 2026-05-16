<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CronJobLog extends Model
{
    protected $table = 'cron_job_logs';

    protected $fillable = [
        'job_name',
        'command_name',
        'status',
        'started_at',
        'finished_at',
        'duration_seconds',
        'output',
        'error_message',
        'payload',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'payload' => 'array',
    ];
}
