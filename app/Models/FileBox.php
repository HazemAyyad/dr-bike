<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FileBox extends Model
{
    use HasFactory;
    protected $table = 'file_boxes';
    protected $fillable = ['name','treasury_id','is_cancelled'];

    public function treasury(){
        return $this->belongsTo(Treasury::class,'treasury_id');
    }

    public function files(){
        return $this->hasMany(File::class,'file_box_id');
    }

        public function papers(){
        return $this->hasMany(Paper::class,'file_box_id');
    }
}
