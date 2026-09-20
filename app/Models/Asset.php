<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Asset extends Model
{
    use HasFactory;

    protected $table = 'assets';

    protected $fillable = [
        'name',
        'price',
        'notes',
        'depreciation_rate',
        'depreciation_price',
        'months_number',
        'box_id',
        'currency',
        'acquired_at',
        'media',
    ];

    protected $casts = [
        'media' => 'array',
        'acquired_at' => 'date',
    ];

    public function box()
    {
        return $this->belongsTo(Box::class);
    }

    public function logs()
    {
        return $this->hasMany(AssetLog::class);
    }

    public static function assetsTotalPrices()
    {
        return Asset::sum('price');
    }

    public static function assetsCurrentDepricationSum()
    {
        return Asset::sum('depreciation_price');
    }

    public static function depreciateAverage()
    {
        $depreciates = Asset::sum('depreciation_rate');
        $count = Asset::whereNotNull('depreciation_rate')->count();
        if ($count === 0) {
            return 0;
        }

        return $depreciates / $count;
    }
}
