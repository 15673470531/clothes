<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 意见反馈：小程序「我的 → 意见反馈」提交的内容，Filament 后台可查看
     */
    public function up(): void
    {
        Schema::create('feedbacks', function (Blueprint $table) {
            $table->id();
            // 注意：CREATE TABLE 里不能写 ->after('id')（那是 ALTER TABLE 才支持的），
            // 字段顺序靠这里的书写顺序决定
            $table->foreignId('user_id')->nullable()
                ->constrained()->nullOnDelete()->comment('提交用户，未登录为空');
            $table->text('content')->comment('反馈内容');
            $table->string('contact', 100)->nullable()->comment('联系方式（可不填）');
            $table->timestamps();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feedbacks');
    }
};
