<?php

namespace App\Models;

use App\Services\ProductCodeAllocator;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use HasFactory;
    use SoftDeletes;

    public $incrementing = false;

    protected static function booted(): void
    {
        static::creating(function (Product $product) {
            $code = $product->product_code ?? null;
            if ($code === null || $code === '') {
                $product->product_code = app(ProductCodeAllocator::class)->allocate();
            }
        });
    }

    protected $fillable = [
        'id',
        'category_id',
        'nameAr',
        'nameEng',
        'nameAbree',
        'isShow',
        'descriptionAr',
        'descriptionEng',
        'descriptionAbree',
        'videoUrl',
        'normailPrice',
        'wholesalePrice',
        'stock',
        'model',
        'isNewItem',
        'isMoreSales',
        'rate',
        'manufactureYear',
        'discount',
        'userIdAdd',
        'dateAdd',
        'userIdUpdate',
        'dateUpdate',
        'price',
        'is_sold_with_paper',
        'min_sale_price', 'rotation_date', 'min_stock',
        'project_id', // اسم الشروة فقط
    ];

    protected $hidden = ['wholesalePrice'];

    public function goals()
    {
        return $this->hasMany(Goal::class);
    }

    public function orders()
    {
        return $this->belongsToMany(Order::class)->withTimestamps()->withPivot('quantity');
    }

    public function instantBuyings()
    {
        return $this->belongsToMany(InstantBuying::class, 'instant_buying_product')
            ->withTimestamps()
            ->withPivot('quantity');
    }

    public function followups()
    {
        return $this->hasMany(Followup::class);
    }

    public function category()
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function subDepartment()
    {
        return $this->belongsTo(SubDepartment::class);
    }

    public function partnerships()
    {
        return $this->hasMany(Partnership::class);
    }

    public function instantSales()
    {
        return $this->hasMany(InstantSale::class);
    }

    public function normalImages()
    {
        return $this->hasMany(NormalImageProduct::class, 'itemId');
    }

    public function viewImages()
    {
        return $this->hasMany(ViewImageProduct::class, 'itemId');
    }

    public function image3d()
    {
        return $this->hasMany(Image3dProduct::class, 'itemId');
    }

    public function subCategories()
    {
        return $this->hasMany(SubCategoryProduct::class, 'product_id');
    }

    public function sizes()
    {
        return $this->hasMany(Size::class, 'itemId');
    }

    public function projects()
    {
        return $this->hasMany(ProjectProduct::class);
    }

    public function destructions()
    {
        return $this->hasMany(Destruction::class);
    }

    public function wholesales()
    {
        return $this->hasMany(WholesaleProduct::class);
    }

    public function closeout()
    {
        return $this->hasOne(Closeout::class);
    }

    public function combinations()
    {
        return $this->hasMany(Combination::class, 'main_product_id');
    }

    // الشروة كاسم فقط
    public function purchase()
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function bills()
    {
        return $this->hasMany(BillItem::class);
    }

    public function purchasePrices()
    {
        return $this->hasMany(PurchaseProduct::class);
    }

    public function billQuantities()
    {
        return $this->hasMany(BillQuantity::class);
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(
            ProductTag::class,
            'product_product_tag',
            'product_id',
            'product_tag_id'
        )->withTimestamps();
    }
}
