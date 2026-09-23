<?php

namespace App\Services;

use App\Exceptions\QuotaExceededException;
use App\Models\ClothesItem;
use App\Models\ClothesOutfit;
use App\Models\User;

/**
 * 衣架（2026-09 第三版：把额度讲成"衣架"，用户听得懂）
 *
 * 一个衣架 = 能挂一样东西：**新增一件衣物占 1 个，新增一套搭配也占 1 个**（共用同一个架子）。
 * 衣架数是 users 表上的余额：
 *   users.item_quota        总共还剩几个衣架（新增时减 1；**删掉不想要的会还回来 1 个**）
 *   users.daily_quota       今天还能挂几个（每天重置回 config('quota.daily_quota')）
 *   users.daily_reset_date  上次重置的日期
 *
 * 为什么是余额而不是"实时数现有条数"（2026-09 用户定的）：
 * 删除要"实时还"，但那也只在删除动作发生的当下 +1 —— 余额制同样能做到，
 * 而且不用每次去 count 一堆记录，后台客服直接改数字就行（Filament 里那两个框）。
 *
 * 扣减只有一个入口：ClothesController::push 里新增的条数（编辑已有衣物/搭配不扣）。
 * 退还也只有一个入口：同一批里的 deleted（**真的是从没删变已删**才算，重复删不叠加）。
 */
class Quota
{
    /** 新用户的衣架总数（默认 100，2026-09 用户定稿） */
    public function totalDefault(): int
    {
        return max(0, (int) config('quota.item_quota', 100));
    }

    /** 每天最多挂几个（重置值） */
    public function dailyDefault(): int
    {
        return max(0, (int) config('quota.daily_quota', 100));
    }

    /** 衣架总数上限（加到这么多就不再往上加；2026-09 用户定：200） */
    public function itemMax(): int
    {
        return max(0, (int) config('quota.item_max', 200));
    }

    /**
     * 惰性重置：发现「上次重置日期」不是今天，就先把今天的衣架填满
     *
     * 为什么要有这一层：定时任务要靠服务器的 cron 或容器跑 schedule:run，
     * 万一没跑起来，用户第二天会发现今天的衣架还是 0、什么都挂不了。
     * 这里兜一道，定时任务只是「顺手重置 + 批量改 daily_reset_date」，不是唯一出路。
     */
    public function refreshDaily(User $user): User
    {
        $today = today()->toDateString();
        $last = $user->daily_reset_date ? $user->daily_reset_date->toDateString() : '';
        if ($last !== $today) {
            $user->daily_quota = $this->dailyDefault();
            $user->daily_reset_date = $today;
        }

        return $user;
    }

    /**
     * 扣衣架（在事务里调用，并且先把用户行 lockForUpdate 锁住，避免并发两批都通过）
     * 两种衣架有一个不够就抛 QuotaExceededException，外层转成 {code, msg}
     */
    public function consume(User $user, int $count): void
    {
        if ($count <= 0) {
            return;
        }

        $user = $this->refreshDaily($user);

        $items = (int) $user->item_quota;
        if ($items < $count) {
            throw new QuotaExceededException(
                'ITEM_LIMIT',
                $items > 0
                    ? "衣架不够了：还剩 {$items} 个，这次要占用 {$count} 个；删掉不想要的能腾出衣架，或联系客服"
                    : '衣架用完了；删掉不想要的能腾出衣架，也可以联系客服'
            );
        }

        $daily = (int) $user->daily_quota;
        if ($daily < $count) {
            throw new QuotaExceededException(
                'DAILY_LIMIT',
                // 只报"今天还剩几个"，不报每日上限（2026-09 用户定：界面不显示每日数量）
                $daily > 0
                    ? "今天还能挂 {$daily} 个衣架，明天再来"
                    : '今天的衣架用完了，明天再来；想今天继续挂可以联系客服'
            );
        }

        $user->item_quota = $items - $count;
        $user->daily_quota = $daily - $count;
        $user->save();
    }

    /**
     * 还衣架：删掉一件衣物或一套搭配，那个衣架就回到架子上
     *
     * **只还总额，不还每日** —— 每日是速率限制（今天最多挂 50 个），
     * 如果也还，就变成「加满 50 → 删 50 → 再加 50」可以无限循环，限制形同虚设。
     *
     * 不会越还越多：只有「这次真的把一条从没删变成已删」才还 1 个（见 ClothesController），
     * 而那条记录当初也只扣过 1 个，一进一出正好抵平。
     */
    public function refund(User $user, int $count): void
    {
        if ($count <= 0) {
            return;
        }

        $user = $this->refreshDaily($user);
        $user->item_quota = (int) $user->item_quota + $count;
        $user->save();
    }

    /**
     * 加衣架（每日签到 / 分享群或好友 / 每月免费领取的奖励）
     *
     * 跟 refund() 的区别：refund 是"删掉东西把架子还回来"，这个是"白赚"。
     * 两者都只动总额、不动每日。
     *
     * **受衣架总数上限约束**（config('quota.item_max')，2026-09 用户定 200）：
     * 余额到 200 就不再累加 —— 少给的部分直接丢掉（不会存着等下次），返回**实际加了多少**，
     * 调用方拿它拼提示语（加了 0 个就说"已到上限"，别骗用户说 +2）。
     * 上限只拦这里：客服在后台手改余额、老账号早就超过 200 的，都不受影响。
     *
     * 注释里的历史口径：免费额度（config('quota.item_quota')）只是"免费进货量"，
     * 奖励本来可以超过它；真正封顶的是 item_max 这个 200。
     */
    public function addTotal(User $user, int $count): int
    {
        if ($count <= 0) {
            return 0;
        }

        $user = $this->refreshDaily($user);

        $current = (int) $user->item_quota;
        $added = max(0, min($count, $this->itemMax() - $current));   // 到上限就一点不加了
        if ($added <= 0) {
            return 0;
        }

        $user->item_quota = $current + $added;
        $user->save();

        return $added;
    }

    /**
     * 显示用量（「我的」页顶部那块衣架卡片读它；顺手做一次惰性重置）
     *
     * 出参是 **hanger 口径**；同时保留旧的 itemQuota/dailyQuota 字段 ——
     * 老版本小程序还在读它们，后端先发、小程序后发，中间不能炸。
     */
    public function summary(User $user): array
    {
        $user = $this->refreshDaily($user);
        if ($user->isDirty()) {
            $user->save();
        }

        $total = (int) $user->item_quota;
        $daily = (int) $user->daily_quota;
        $used  = $this->occupied($user);

        return [
            // 衣架口径（新）：今日剩几个 / 总共剩几个，都带上「分母」方便画进度
            'hangerTotal'      => $total,
            // 分母 = **账号总资产 = 余额 + 已占用**（2026-09 用户定）
            //   加一件衣物：余额 -1、占用 +1 → 分母不变，界面上只有左边那个数在动
            //   （旧口径取 max(免费标准, 余额)，余额超过 100 之后分母就等于余额，
            //    于是显示成「162 / 162」，加一件变「161 / 161」—— 两个数一起减，看着像没有上限）
            // 分母会变大的只有三件事：赚到衣架（签到/分享/每月免费领取）、客服在后台加量、
            // 以及删掉东西再挂新的（余额+1、占用-1 → 分母照样不变，一进一出正好抵平）
            'hangerTotalLimit' => $total + $used,
            'hangerDaily'      => $daily,
            'hangerDailyLimit' => $this->dailyDefault(),

            // 旧字段（老版本小程序兼容，别删）
            'itemQuota'       => $total,
            'dailyQuota'      => $daily,
            'dailyQuotaLimit' => $this->dailyDefault(),
        ];
    }

    /**
     * 已经占用的衣架数 = 云端挂着的（衣物 + 搭配，各 1 个）
     *
     * 为什么可以这么数：**「新增才占、删除会还」的账永远是平的** ——
     *   item_quota = 总共拿到过的衣架 − 已占用
     * 所以 `item_quota + occupied()` 就是"这个账号一共有多少衣架"，是个只要不赚不加就不变的数，
     * 正好当分母用（用户 2026-09 定：别出现"162 / 162"两个数一起减）。
     * 软删的记在 deleted_at 上，模型的全局作用域自动排除，跟"退还衣架"的口径一致。
     */
    private function occupied(User $user): int
    {
        return ClothesItem::where('user_id', $user->id)->count()
            + ClothesOutfit::where('user_id', $user->id)->count();
    }
}
