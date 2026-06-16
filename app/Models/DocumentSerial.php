<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentSerial extends Model
{
    protected $fillable = [
        'year',
        'document_type',
        'last_number',
    ];
}
