<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 搭配（二期第二步：数据上云）
     *
     * item_ids / slots 用 JSON 列存，不建关联表：只有「这件衣服被哪些搭配引用」一个查询，
     * 前端本地算就够了，多一张表只会让同步逻辑更复杂。
     * slots = 长度 9 的位置表（每格是衣物 client_id 或 null），用户在预览页拖拽决定。
     *
     * 草稿（小程序里 draft:true 的半成品）**不上云**：只有预览页点「完成」才算真正保存。
     */
    public function up(): void
    {
        Schema::create('clothes_outfits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('client_id', 32)->comment('小程序本机 id：o+时间戳');
            $table->string('name', 64)->default('');
            $table->boolean('name_auto')->default(false)->comment('名字是自动生成的（场合·衣物名），不是用户手填');
            $table->json('occasions')->nullable();
            $table->json('item_ids')->nullable()->comment('选中的衣物 client_id 数组（顺序即展示顺序）');
            $table->json('slots')->nullable()->comment('9 格位置表');
            $table->string('cover_url', 255)->default('')->comment('canvas 出的穿搭封面（OSS 地址）');
            $table->unsignedBigInteger('client_created_at')->default(0);
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['user_id', 'client_id']);
            $table->index(['user_id', 'client_created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clothes_outfits');
    }
};
