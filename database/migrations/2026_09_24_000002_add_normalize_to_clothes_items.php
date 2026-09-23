<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 洗白底（归一化）落到衣物记录上（2026-09）
 *
 * 三列为什么都要：
 *  - original_image_url：用户实拍原图。用户把白底图设成封面后 image_url 会变成白底图，
 *    原图必须另存一份，否则「切回原图」没得切（原则：永远不毁用户的原图）
 *  - normalized_url：洗好的白底商品图（我们 OSS 的长期地址）
 *  - normalized_source：洗这张白底图时用的**原图地址 md5**。用户换了照片这仨就过期了，
 *    靠它判断「这张白底图还算不算数」，免得拿旧白底图当新的用、或者重复花钱洗同一张
 *
 * users 两列是「每天免费洗 N 次」的计数：跟 daily_quota 一个套路（惰性按天重置），
 * 不改列默认值就不用写数据迁移。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clothes_items', function (Blueprint $table) {
            $table->string('original_image_url', 255)->default('')->after('image_url')
                ->comment('用户实拍原图（设为封面后 image_url 换成白底图，原图留这一列）');
            $table->string('normalized_url', 255)->default('')->after('original_image_url')
                ->comment('洗好的白底商品图（我们 OSS）');
            $table->string('normalized_source', 32)->default('')->after('normalized_url')
                ->comment('洗白底时用的原图地址 md5（用户换照片就作废）');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->unsignedSmallInteger('normalize_used')->default(0)->after('daily_reset_date')
                ->comment('今天洗白底用了几次（免费额度内）');
            $table->date('normalize_date')->nullable()->after('normalize_used')
                ->comment('上面的计数属于哪一天（惰性重置）');
        });
    }

    public function down(): void
    {
        Schema::table('clothes_items', function (Blueprint $table) {
            $table->dropColumn(['original_image_url', 'normalized_url', 'normalized_source']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['normalize_used', 'normalize_date']);
        });
    }
};
