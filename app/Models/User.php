<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens;
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'openid',
        'nickname',
        'avatar_url',
        'is_admin',
        'last_login_at',
        'last_active_at',
        'item_quota',
        'daily_quota',
        'daily_reset_date',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * 业务关联（管理端统计用 withCount 拉每个人的数据量）
     * 衣物/搭配有软删，withCount 会自动按 SoftDeletes 的全局作用域排除掉已删的
     */
    public function clothesItems(): HasMany
    {
        return $this->hasMany(ClothesItem::class, 'user_id');
    }

    public function clothesOutfits(): HasMany
    {
        return $this->hasMany(ClothesOutfit::class, 'user_id');
    }

    public function wearLogs(): HasMany
    {
        return $this->hasMany(ClothesWearLog::class, 'user_id');
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'last_login_at' => 'datetime',
            'last_active_at' => 'datetime',
            'item_quota' => 'integer',
            'daily_quota' => 'integer',
            'daily_reset_date' => 'date',
        ];
    }
}
