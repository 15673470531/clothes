<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 整套试穿的增量（2026-09 · P3）
     *
     * 两件事：
     *  1 新建 tryon_garments —— 归一化（洗白底图）的缓存从"任务"里拆出来，改为"一件衣物一行"。
     *    理由：洗图是**一件衣服**的属性（一次 15~20 秒、要花钱），不是某次任务的属性。
     *    单件试穿和整套试穿两条路径共用它，一件衣服永远只洗一次。
     *  2 tryon_tasks 加 garments —— 记下这次用了哪几件（{top, bottom} 的 client_id），
     *    整套试穿的缓存键就靠它 + source_hash 定位（同套 + 同模特 → 永远复用）。
     *
     * 为什么不改写上一版迁移：万一线上已经跑过 000001，改它不会重跑；增量迁移两边都安全。
     */
    public function up(): void
    {
        Schema::create('tryon_garments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('item_id', 32)->comment('衣物的 client_id');
            $table->string('source_hash', 32)->comment('原图地址的 md5（换照片就换一行）');
            $table->string('normalized_url', 255)->comment('洗好的白底商品图（我们 OSS）');
            $table->timestamps();

            // 一件衣服 + 一张原图 = 一行，重复洗的时候 updateOrCreate 覆盖
            $table->unique(['user_id', 'item_id', 'source_hash'], 'uniq_tryon_garment');
        });

        Schema::table('tryon_tasks', function (Blueprint $table) {
            $table->json('garments')->nullable()->after('slot')->comment('这次用的衣物：{top,bottom}');
        });
    }

    public function down(): void
    {
        Schema::table('tryon_tasks', function (Blueprint $table) {
            $table->dropColumn('garments');
        });

        Schema::dropIfExists('tryon_garments');
    }
};
