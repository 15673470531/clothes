<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 衣物（二期第二步：数据上云）
     *
     * id 沿用小程序本机生成的那套字符串 id（'i'+时间戳）当业务主键（client_id）——
     * 两套 id 互相映射是这类改造最容易出事的地方，直接省掉；
     * 自增 id 只作行主键，接口对外的 id 一律是 client_id。
     *
     * 软删（deleted_at）：多端同步时删除要留痕，否则另一台机器还把这条推回来。
     */
    public function up(): void
    {
        Schema::create('clothes_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('client_id', 32)->comment('小程序本机 id：i+时间戳');
            $table->string('name', 64)->default('')->comment('名称，可空');
            $table->string('category', 16)->default('')->comment('top上装/bottom下装/outer外套/dress裙装/shoes鞋/acc配饰');
            $table->string('sub', 24)->default('')->comment('二级细分 key，可空');
            $table->json('colors')->nullable()->comment('颜色 key 数组');
            $table->json('seasons')->nullable();
            $table->json('occasions')->nullable();
            $table->string('image_url', 255)->default('')->comment('OSS 地址；本机落盘路径不上云（跨设备无意义）');
            $table->unsignedBigInteger('client_created_at')->default(0)->comment('小程序里的创建时间(ms)，列表排序靠它');
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['user_id', 'client_id']);
            $table->index(['user_id', 'client_created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clothes_items');
    }
};
