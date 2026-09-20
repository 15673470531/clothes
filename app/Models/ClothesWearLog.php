<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 穿搭日历：一天一条（user_id + date 唯一）
 */
class ClothesWearLog extends Model
{
    protected $table = 'clothes_wear_logs';

    protected $fillable = ['user_id', 'date', 'outfit_client_id'];

    protected $casts = [
        'date' => 'date:Y-m-d',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
