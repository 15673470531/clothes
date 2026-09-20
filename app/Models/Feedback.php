<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 意见反馈
 */
class Feedback extends Model
{
    protected $table = 'feedbacks';

    protected $fillable = ['user_id', 'content', 'contact'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
