<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AssetLog extends Model
{
    use HasFactory;
        protected $table = 'asset_logs';

    protected $fillable = [
      'asset_id','total','type','depreciation_period','value_before',
      'depreciation_amount','processed_by_user_id',
    ];

    public function asset(){
        return $this->belongsTo(Asset::class);
    }

   
}
