<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 「自动洗白底」默认关掉（2026-09-26 用户改口径）
     *
     * 原来：新衣物默认"自动洗"（保存后进队列、跑完自动换封面）。
     * 现在：**默认不开** —— 用户想洗就在记录衣物页自己勾上，或者在详情里手动生成一次。
     *
     * 为什么改：自动洗是花真钱的动作（0.2 元/张），而且刚出过"用户在页面上关掉了、
     * 前端却没把这两个字段传上去"的传输 bug（utils/cloud.js，已修）——
     * 花钱的事默认别替用户做主，让他自己点。
     *
     * 只改**默认值**：不动存量记录（那些 normalize_auto=1 是以前默认开时的产物，
     * 要不要一起关是单独一件事，得单独确认；本迁移一行数据都不碰）。
     *
     * ⚠️ 别顺手把 config('tryon.normalize_auto') 也关掉 —— 那是全局"允许自动洗"的闸门，
     * 关了就变成"用户勾了也不洗"；每件衣物自己的默认值在这一列上。
     */
    public function up(): void
    {
        Schema::table('clothes_items', function (Blueprint $table) {
            $table->boolean('normalize_auto')
                ->default(false)
                ->comment('这件要不要自动洗白底（2026-09-26 起默认关，用户自己勾）')
                ->change();
        });
    }

    public function down(): void
    {
        Schema::table('clothes_items', function (Blueprint $table) {
            $table->boolean('normalize_auto')->default(true)->change();
        });
    }
};
