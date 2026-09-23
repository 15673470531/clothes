<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 赚衣架记录表（2026-09：每日签到 / 分享好友）
     *
     * 一行 = 领到的一次奖励。相当于**流水**，不是余额：
     * 余额仍然是 users.item_quota（领奖时加 1 笔），这张表只负责"谁在哪天领过几次"，
     * 用来防重复领、以及以后想看谁爱签到。
     *
     * 为什么加 seq（当天第几次）而不是直接 unique(user_id, type, date)：
     * 现在每天各领 1 次，但以后想把"分享"改成每天 3 次时**不用改表结构**，
     * 只改 config('quota.reward_share_daily') 就行（用户的铁律：数字不发版）。
     * 顺带这个唯一索引还能挡住并发重复领 —— 两个人同时点，第二条插入直接失败。
     *
     * 纯新增表，不动老表，线上可随时发。
     */
    public function up(): void
    {
        Schema::create('hanger_rewards', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('type', 16)->comment('checkin 签到 / share 分享好友');
            $table->date('reward_date')->comment('哪一天领的（按服务器时区）');
            $table->unsignedTinyInteger('seq')->default(1)->comment('当天第几次（配合 config 里的每日次数上限）');
            $table->unsignedInteger('amount')->comment('这次送了几个衣架（当时的数字，改配置也不影响历史）');
            $table->timestamps();

            // 一个用户 + 一种奖励 + 一天 + 序号 = 唯一：既防重复领，又留了"每天多次"的余地
            $table->unique(['user_id', 'type', 'reward_date', 'seq'], 'uk_hanger_reward_once');
            // 查"今天领过没" / 以后做排行榜
            $table->index(['user_id', 'reward_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hanger_rewards');
    }
};
