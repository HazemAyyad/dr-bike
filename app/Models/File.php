<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class File extends Model
{
    use HasFactory;

    protected $table = 'files';
    protected $fillable = ['name','file_box_id','is_canceled'];

    public function fileBox(){
        return $this->belongsTo(FileBox::class,'file_box_id');
    }

        public function papers(){
        return $this->hasMany(Paper::class,'file_id');
    }
}
