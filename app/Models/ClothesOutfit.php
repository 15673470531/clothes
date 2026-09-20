<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * 搭配（二期第二步：数据上云）
 */
class ClothesOutfit extends Model
{
    use SoftDeletes;

    protected $table = 'clothes_outfits';

    protected $fillable = [
        'user_id', 'client_id', 'name', 'name_auto', 'occasions',
        'item_ids', 'slots', 'cover_url', 'client_created_at',
    ];

    protected $casts = [
        'name_auto'   => 'boolean',
        'occasions'   => 'array',
        'item_ids'    => 'array',
        'slots'       => 'array',
        'client_created_at' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
