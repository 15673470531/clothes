<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('openid')->unique()->nullable()->after('id');
            $table->string('nickname')->nullable()->after('name');
            $table->string('avatar_url')->nullable()->after('nickname');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['openid', 'nickname', 'avatar_url']);
        });
    }
};
