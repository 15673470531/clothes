<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 洗白底「自动跑」（2026-09 · 用户拍板口径）
 *
 * 用户选择：默认打开、每人每天 10 张、保存衣物后自动生成、生成好自动换成封面图；
 * 但他手动选过「用原图」的**不覆盖**（白底图照样留着，随时能切回去）。
 *
 * 所以要三列：
 *  - normalize_auto    这件衣物**自己**的"自动洗白底"勾选（默认开）。用户日额度用完后，
 *                      第二天打开衣橱时会按这一列惰性补洗 —— 所以标记必须落在每件衣物上，
 *                      不能只存一个全局开关。
 *  - normalize_status  自动流程的状态：'' 不用洗 / queued 排队中 / running 生成中 /
 *                      done 已生成 / failed 失败（可手动重试）/ skipped 今天次数用完（明天再补）
 *  - cover_choice      用户的展示图选择：'' 没选过（自动替换成白底图）/ 'orig' 明确选了原图（不覆盖）
 *
 * 幂等：三列都有默认值，老数据自动落成「开着自动 + 没状态 + 没选过」——
 * 也就是老衣物下次推上来会被自动补洗（符合用户"自动打开"的意图）。white 图不会重洗（见 NormalizeService 的缓存）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clothes_items', function (Blueprint $table) {
            $table->string('normalize_status', 12)->default('')->after('normalized_source');
            $table->boolean('normalize_auto')->default(true)->after('normalize_status');
            $table->string('cover_choice', 8)->default('')->after('normalize_auto');

            // 队列按「这件该洗但还没洗」扫表（第二天惰性补洗），给它一个索引
            $table->index(['user_id', 'normalize_auto', 'normalize_status'], 'items_normalize_scan');
        });
    }

    public function down(): void
    {
        Schema::table('clothes_items', function (Blueprint $table) {
            $table->dropIndex('items_normalize_scan');
            $table->dropColumn(['normalize_status', 'normalize_auto', 'cover_choice']);
        });
    }
};
