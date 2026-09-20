<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 穿搭日历：哪天穿了哪套（一天一条，用户 2026-09 定的口径）
     *
     * 只存搭配 client_id，不存快照：搭配改名/换封面，日历跟着变；
     * 搭配被删了就显示「这套已删除」（跟前端现在的行为一致）。
     */
    public function up(): void
    {
        Schema::create('clothes_wear_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('date')->comment('哪天穿的');
            $table->string('outfit_client_id', 32)->default('')->comment('搭配 client_id（空串=这天没记）');
            $table->timestamps();

            // 一天只记一套：同一天再记就是覆盖
            $table->unique(['user_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clothes_wear_logs');
    }
};
