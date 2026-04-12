<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Treasury extends Model
{
    use HasFactory;
    protected $table = 'treasuries';
    protected $fillable = ['name','is_canceled'];

    public function fileBoxes(){
        return $this->hasMany(FileBox::class,'treasury_id');
    }

    public function papers(){
        return $this->hasMany(Paper::class,'treasury_id');
    }
}
