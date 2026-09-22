<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * AI 试穿任务表（2026-09 · P2）
     *
     * 这张表同时干三件事：
     *   1 任务状态：小程序提交 → pending → running → done/failed（前端轮询这个）
     *   2 归一化缓存：normalized_url = 这件衣服洗成白底商品图后的地址（同件衣服只洗一次）
     *   3 结果缓存：同一件衣服 + 同一个虚拟模特 = 同一张结果图，之后谁看都复用，不再花钱
     *
     * 缓存靠 (user_id, item_id, model_key, source_hash) 这四个字段定位：
     * source_hash 是提交时那张照片地址的 md5 —— 用户换了照片，缓存自然失效，不用手工清。
     *
     * 纯新增表，不动任何老表，线上可以随时发。
     */
    public function up(): void
    {
        Schema::create('tryon_tasks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->comment('谁的衣物');
            $table->string('item_id', 32)->comment('衣物的 client_id');
            $table->string('model_key', 32)->default('model-a')->comment('虚拟模特标识');
            $table->string('source_url', 255)->default('')->comment('提交时那件衣服的照片地址');
            $table->string('source_hash', 32)->default('')->comment('source_url 的 md5，用来判缓存是否失效');
            $table->string('slot', 16)->default('top')->comment('穿在哪个部位：top / bottom');
            $table->string('status', 16)->default('pending')->comment('pending/running/done/failed');
            $table->string('normalized_url', 255)->default('')->comment('洗好的白底商品图（缓存复用）');
            $table->string('result_url', 255)->default('')->comment('试穿结果图（存在我们自己 OSS）');
            $table->string('provider', 32)->default('')->comment('用的哪家：dashscope');
            $table->unsignedTinyInteger('attempts')->default(0)->comment('试了几次');
            $table->unsignedInteger('cost_ms')->default(0)->comment('耗时（毫秒，观测用）');
            $table->string('error', 255)->default('')->comment('失败原因（给用户看的是友好文案）');
            $table->timestamps();

            // 查缓存就靠它：同一个人 + 同一件 + 同一个模特 + 同一张照片
            $table->index(['user_id', 'item_id', 'model_key', 'source_hash'], 'idx_tryon_cache');
            // 每日限额统计 + 用户列表
            $table->index(['user_id', 'created_at']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tryon_tasks');
    }
};
