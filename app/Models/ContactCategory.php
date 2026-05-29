<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContactCategory extends Model
{
    protected $table = 'contact_categories';

    protected $fillable = ['name', 'color'];

    public function assignments()
    {
        return $this->hasMany(ContactCategoryAssignment::class);
    }
}
