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
        'price' => 'float',
        'depreciation_rate' => 'float',
        'depreciation_price' => 'float',
        'months_number' => 'integer',
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
        $assets = Asset::query()->where('months_number', '>', 0)->get(['months_number']);
        if ($assets->isEmpty()) {
            return 0;
        }

        return $assets->avg(fn (Asset $asset) => 1 / (float) $asset->months_number);
    }
}
