<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 赚衣架流水：一行 = 领到的一次奖励（签到 / 分享好友）
 *
 * 只记"领过没、领了几个"，余额还是 users.item_quota（Service 里加）。
 *
 * type 有四种（2026-09）：
 *   checkin  每日签到（按天）
 *   share    分享给好友（按天）
 *   newcomer 新用户每月免费领取（按自然月，**手动点领取** —— 目前页面上真正用的那条）
 *   monthly  每月系统赠送（按自然月自动到账，老口径；配置默认 0，已停用，历史流水还在）
 */
class HangerReward extends Model
{
    protected $table = 'hanger_rewards';

    /** 每日签到 */
    public const TYPE_CHECKIN = 'checkin';

    /** 分享给好友 */
    public const TYPE_SHARE = 'share';

    /** 每月系统免费赠送（按自然月自动到账）—— 2026-09 起默认关，见 config('quota.reward_monthly') */
    public const TYPE_MONTHLY = 'monthly';

    /** 新用户每月免费领取（按月 1 次，但**要用户点一下「领取」**；2026-09 取代「每月系统赠送」） */
    public const TYPE_NEWCOMER = 'newcomer';

    protected $fillable = ['user_id', 'type', 'reward_date', 'seq', 'amount'];

    protected $casts = [
        'reward_date' => 'date:Y-m-d',
        'seq'         => 'integer',
        'amount'      => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
