<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * 衣物（二期第二步：数据上云）
 *
 * 字段口径见 migration；对外接口里的 id 一律用 client_id（小程序本机那套 id）
 */
class ClothesItem extends Model
{
    use SoftDeletes;

    protected $table = 'clothes_items';

    protected $fillable = [
        'user_id', 'client_id', 'name', 'category', 'sub',
        'colors', 'seasons', 'occasions', 'image_url', 'client_created_at',
    ];

    protected $casts = [
        'colors'    => 'array',
        'seasons'   => 'array',
        'occasions' => 'array',
        'client_created_at' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
