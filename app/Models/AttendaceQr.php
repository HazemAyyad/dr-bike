<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AttendaceQr extends Model
{
    use HasFactory;
    protected $table = 'attendace_qrs';
    protected $fillable = ['code_text','file_name'];
}
