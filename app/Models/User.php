<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\PersonalAccessToken;
use App\Notifications\ResetPasswordNotification as CustomResetPasswordNotification;


class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    /** اسم صلاحية عرض/تعديل سعر التكلفة (name_en في جدول permissions). */
    public const COST_PRICE_PERMISSION = 'Cost Price';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'sub_phone',
        'city',
        'address',
        'type',
        'is_blocked',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'is_blocked' => 'boolean',
    ];

   



    public function employee(){
        return $this->hasOne(EmployeeDetail::class);
    }

    /**
     * هل يحق لهذا المستخدم رؤية/تعديل سعر التكلفة؟
     * الأدمن دائماً مسموح، والموظف فقط إذا منحه الأدمن صلاحية "Cost Price".
     */
    public function canViewCostPrice(): bool
    {
        if ($this->type === 'admin') {
            return true;
        }

        if ($this->type === 'employee' && $this->employee) {
            return $this->employee->permissions()
                ->whereHas('permission', function ($q) {
                    $q->where('name_en', self::COST_PRICE_PERMISSION);
                })
                ->exists();
        }

        return false;
    }

    public function adminDeviceTokens()
    {
        return $this->hasMany(AdminDeviceToken::class);
    }

    /** رموز Sanctum غير منتهية (للعرض في إدارة الجلسات). */
    public function activeSanctumTokens()
    {
        return $this->morphMany(PersonalAccessToken::class, 'tokenable')
            ->where(function ($query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            });
    }
    






    //overwrite reset password link to make it show the token
    public function sendPasswordResetNotification($token)
{
    $this->notify(new CustomResetPasswordNotification($token));
}

}