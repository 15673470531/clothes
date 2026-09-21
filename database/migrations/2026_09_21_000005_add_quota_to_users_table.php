<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 额度改成「用户表上的余额」（2026-09 第二版）
     *
     * 第一版是实时数记录（count 衣物条数 / count 当天上传流水），不好开会员：
     * 想给谁加额度得改代码逻辑。改成余额字段后，加额度就是改这个数字（Filament 后台也能改）。
     *
     *   item_quota        一共还能录几件衣物（每成功录一件减 1；默认 200）
     *   daily_quota       今天还能录几件（每天重置成默认值 50）
     *   daily_reset_date  上次重置的日期（定时任务写；同时也是「定时任务没跑」时的兜底判断）
     *
     * 加列都有默认值：老用户迁移完就是「200 件 / 今天 50 件」，不用刷数据。
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedInteger('item_quota')->default(200)->after('is_admin')
                ->comment('还能上传的衣物总数（余额，成功录一件减 1）');
            $table->unsignedInteger('daily_quota')->default(50)->after('item_quota')
                ->comment('今天还能录几件（每天重置）');
            $table->date('daily_reset_date')->nullable()->after('daily_quota')
                ->comment('上次重置每日额度的时间；不是今天就说明该重置了');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['item_quota', 'daily_quota', 'daily_reset_date']);
        });
    }
};
