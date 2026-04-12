<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BoxLog extends Model
{
    use HasFactory;
    protected $table = 'box_logs';
    protected $fillable = [
        'from_box_id',
        'to_box_id',
        'description',
        'value',
        'box_id',
        'type',

    ];

    /**
     * Relation to the "from" box.
     */
    public function fromBox()
    {
        return $this->belongsTo(Box::class, 'from_box_id');
    }

    /**
     * Relation to the "to" box.
     */
    public function toBox()
    {
        return $this->belongsTo(Box::class, 'to_box_id');
    }

    public function box(){
        return $this->belongsTo(Box::class, 'box_id');

    }
}
