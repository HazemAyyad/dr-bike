<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Paper extends Model
{
    use HasFactory;

       protected $fillable = [
        'name',
        'treasury_id',
        'file_box_id',
        'file_id',
        'img',
        'notes',
        'is_cancelled',
    ];

 
    protected $casts = ['img'=>'array'];
    public function treasury()
    {
        return $this->belongsTo(Treasury::class);
    }


    public function fileBox()
    {
        return $this->belongsTo(FileBox::class);
    }

    public function file()
    {
        return $this->belongsTo(File::class);
    }
}
