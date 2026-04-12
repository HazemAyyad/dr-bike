<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Project extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'project_cost',
        'images',
        'payment_method',
        'notes',
        'partnership_papers',
        'achievement_percentage',
        'status',
        'payment_notes',

    ];

    protected $casts = [
        'images' => 'array', // Cast images JSON field to array
        'partnership_papers' => 'array', // Cast images JSON field to array

    ];

    // A project has many partners
    public function partnership()
    {
        return $this->hasOne(Partnership::class);
    }

    public function products(){
        return $this->hasMany(ProjectProduct::class);
    }

    public function expenses(){
        return $this->hasMany(ProjectExpense::class);
    }

    public function instantSales(){
        return $this->hasMany(InstantSale::class);
    }
}
