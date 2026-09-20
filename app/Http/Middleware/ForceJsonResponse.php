<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * /api/* 一律按 JSON 处理（2026-09 加图片上传时暴露的问题）
 *
 * 起因：Laravel 按请求头 `Accept` 决定「校验失败/异常」时回 JSON 还是 302 重定向到 HTML 页，
 * 而小程序的 wx.request / wx.uploadFile **不带 Accept: application/json** ——
 * 于是「图片不能超过 20MB」这类校验失败会回一个 HTML 跳转页，
 * 小程序 JSON.parse 直接失败，只能给用户一句兜底提示，看不到真正原因。
 *
 * 这里在进入路由之前把 Accept 头改成 application/json，配合 bootstrap/app.php 里的
 * ValidationException 渲染，接口的报错也统一成 {"code":..,"msg":..,"data":..}
 */
class ForceJsonResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}
