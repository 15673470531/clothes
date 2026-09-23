<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 免费衣架额度：把列默认值和今日额度**同步成 config('quota.*') 的值**
     *
     * 背景：这个迁移最初是"200/50 上调到 500/200"用的，后来用户把口径定成 **50 / 100**，
     * 所以现在它就是"按配置对齐"：改配置（config/quota.php 或 .env 的 QUOTA_*）后，
     * 这个迁移负责把**数据库列默认值**和**老用户当天余额**也拉齐。
     *
     * 三件事：
     *  1 改列默认值 —— 跟 config 一致（新用户建号时还会按 config 再写一遍，双保险）
     *  2 老用户补差额：`max(0, 新标准 - 旧标准 200)`。现在的标准是 50 < 200 → **差额为 0，不补**
     *    （已经拿到 500 的老账号保持不动，不做回收）。以后再把标准调高时，这段才起作用
     *  3 今日额度直接给到配置值（每天也会重置成它，这里先给上，免得当天卡着人）
     *
     * 幂等性：迁移按名字记一次，不会重复补。
     */
    private const OLD_TOTAL = 200;   // 历史基准：只看"新标准比它高多少"来补差额（现在标准 50 → 不补）

    public function up(): void
    {
        $total = max(0, (int) config('quota.item_quota', 50));
        $daily = max(0, (int) config('quota.daily_quota', 100));

        Schema::table('users', function (Blueprint $table) use ($total, $daily) {
            $table->unsignedInteger('item_quota')->default($total)->change();
            $table->unsignedInteger('daily_quota')->default($daily)->change();
        });

        $diff = max(0, $total - self::OLD_TOTAL);

        // 用 ORM 逐条走（不写裸 SQL）；用户表规模不大，chunkById 分批足够
        User::query()->chunkById(200, function ($users) use ($diff, $daily) {
            foreach ($users as $user) {
                $user->item_quota = (int) $user->item_quota + $diff;   // 补差额，已消耗的不追溯
                $user->daily_quota = $daily;                           // 今日额度直接给到新值
                $user->save();
            }
        });
    }

    public function down(): void
    {
        // 回滚只把列默认值改回旧标准；余额不回退（已经发出去的额度没有理由收回来）
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedInteger('item_quota')->default(self::OLD_TOTAL)->change();
            $table->unsignedInteger('daily_quota')->default(50)->change();
        });
    }
};
