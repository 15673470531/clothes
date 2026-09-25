<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('usage_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('event_id', 64);
            $t->string('session_id', 64);
            $t->string('name', 40);
            $t->string('page', 24);
            $t->string('source', 24)->default('');
            $t->string('result', 24)->default('');
            $t->string('error_code', 32)->default('');
            $t->unsignedInteger('duration_ms')->default(0);
            $t->unsignedSmallInteger('count')->default(0);
            $t->unsignedBigInteger('occurred_at');
            $t->unsignedInteger('sequence')->default(0);
            $t->timestamp('created_at');
            $t->unique(['user_id', 'event_id']);
            $t->index(['user_id', 'session_id']);
            $t->index(['occurred_at', 'name']);
        });
    }
    public function down(): void { Schema::dropIfExists('usage_events'); }
};
