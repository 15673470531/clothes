<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo(fn (\Illuminate\Http\Request $request) => '/');
        $middleware->trustProxies(at: '*');
        $middleware->api(prepend: [
            // 小程序不带 Accept: application/json → 不强制的话校验失败会回 HTML 跳转页
            \App\Http\Middleware\ForceJsonResponse::class,
            \App\Http\Middleware\UpdateLastActiveAt::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // 参数校验失败也走统一格式：{"code":1,"msg":"第一条错误","data":{"errors":{...}}}
        $exceptions->render(function (\Illuminate\Validation\ValidationException $e, \Illuminate\Http\Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'code' => 1,
                    'msg'  => $e->validator->errors()->first() ?: '参数不合法',
                    'data' => ['errors' => $e->errors()],
                ], 422);
            }
        });

        $exceptions->render(function (\Illuminate\Auth\AuthenticationException $e, \Illuminate\Http\Request $request) {
            if ($request->is('api/*')) {
                \Illuminate\Support\Facades\Log::warning('API 401 未登录', [
                    'url'    => $request->fullUrl(),
                    'method' => $request->method(),
                    'has_bearer' => str_contains($request->header('Authorization', ''), 'Bearer '),
                    'bearer_prefix' => substr($request->header('Authorization', ''), 0, 30),
                    'headers' => $request->headers->all(),
                    'ip'     => $request->ip(),
                ]);
                return response()->json(['code' => 401, 'msg' => '登录已过期，请重新登录'], 401);
            }
        });
    })->create();
