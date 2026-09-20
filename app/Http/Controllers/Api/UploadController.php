<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ImageStorage;
use Illuminate\Http\Request;

/**
 * 通用图片上传（2026-09 二期第一步，方案 A：小程序 → 本后端 → OSS）
 *
 *   POST /api/upload/image   multipart: file=<图片>   dir=clothes|outfit|avatar|feedback
 *   → data: { path, url, driver }   url 可直接给 <image src> 用
 *
 * 目录在这里收口（dir 只允许白名单里的几个），真实路径再拼上用户 id：
 *   clothes/12/202609/xxxxxxxx.jpg
 * 存储后端由 App\Services\ImageStorage 决定：配了 OSS 走 OSS，没配落本地 public 盘。
 */
class UploadController extends Controller
{
    public function __construct(private readonly ImageStorage $storage)
    {
    }

    public function image(Request $request)
    {
        $data = $request->validate([
            'file' => 'required|file|image|max:20480',
            'dir'  => 'nullable|string|in:clothes,outfit,avatar,feedback',
        ], [
            'file.required' => '没有收到图片文件',
            'file.image'    => '只能上传图片',
            'file.max'      => '图片不能超过 20MB',
            'dir.in'        => '目录不合法',
        ]);

        $user = $request->user();
        $dir = ($data['dir'] ?? 'misc') . '/' . ($user ? $user->id : 'anon');

        try {
            $out = $this->storage->put($request->file('file'), $dir);
        } catch (\Throwable $e) {
            // 失败要让小程序知道（那边保留本地图、下次补传），别回一个看起来成功的空地址
            report($e);

            return response()->json(['code' => 1, 'msg' => '图片上传失败，稍后重试', 'data' => null]);
        }

        return response()->json(['code' => 0, 'msg' => 'success', 'data' => $out]);
    }
}
