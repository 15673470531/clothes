<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personal_portraits', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('path');
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('personal_tryons', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('portrait_id')->constrained('personal_portraits');
            $t->string('item_id', 64);
            $t->text('garment_url');
            $t->string('item_name');
            $t->string('status', 24)->default('pending');
            $t->string('result_path')->default('');
            $t->string('error')->default('');
            $t->timestamps();
            $t->softDeletes();
            $t->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_tryons');
        Schema::dropIfExists('personal_portraits');
    }
};
