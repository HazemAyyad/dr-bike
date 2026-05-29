<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContactCategoryAssignment extends Model
{
    protected $table = 'contact_category_assignments';

    protected $fillable = [
        'contact_category_id',
        'customer_id',
        'seller_id',
    ];

    public function category()
    {
        return $this->belongsTo(ContactCategory::class, 'contact_category_id');
    }
}
