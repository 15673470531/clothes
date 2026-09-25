<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

/**
 * 更新用户最后活跃时间
 *
 * 在每次鉴权请求时更新 last_active_at，
 * 用 Schema::hasColumn 保证迁移未上线时不报错
 */
class UpdateLastActiveAt
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->is('api/usage/events')) return $response;

        if ($user = $request->user()) {
            if (Schema::hasColumn('users', 'last_active_at')) {
                $user->timestamps = false; // 不更新 updated_at
                $user->update(['last_active_at' => now()]);
            }
        }

        return $response;
    }
}
