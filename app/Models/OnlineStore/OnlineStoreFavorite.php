<?php

namespace App\Models\OnlineStore;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class OnlineStoreFavorite extends Model
{
    protected $fillable = ['user_id', 'online_store_listing_id'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function listing()
    {
        return $this->belongsTo(OnlineStoreListing::class, 'online_store_listing_id');
    }
}
