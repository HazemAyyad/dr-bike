<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class IncomingCheckBox extends Model
{
    use HasFactory;
    protected $table = 'incoming_check_boxes';
    protected $fillable = ['incoming_check_id','box_id','status'];

    public function box(){
        return $this->belongsTo(Box::class);
    }
    public function incomingCheck(){
        return $this->belongsTo(IncomingCheck::class,'incoming_check_id');
    }
}
