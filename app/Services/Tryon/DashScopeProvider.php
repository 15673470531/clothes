<?php

namespace App\Services\Tryon;

use App\Exceptions\TryonException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 阿里云百炼（通义万相）实现（2026-09 · P2）
 *
 * 两个模型（都是实测确认过的）：
 *   qwen-image-edit  指令式图像编辑 → 用来把实拍图洗成白底商品图（同步调用，约 15~20 秒）
 *   aitryon          AI 试衣 → 虚拟模特穿上（异步任务 + 轮询，约 5~10 秒）
 *
 * 三个坑（踩过，别改回去）：
 *   1 **图必须是公网 https 直链**：临时空间的 oss:// 地址会被数据检查拒掉
 *      （报 InvalidParameter.DataInspection，信息很有误导性）
 *   2 aitryon 必须带 X-DashScope-Async: enable，否则报"不支持同步调用"
 *   3 连续提交会 429（Throttling.RateQuota）→ 这里带指数退避重试，外面还有队列重试
 */
class DashScopeProvider implements TryonProvider
{
    private const BASE = 'https://dashscope.aliyuncs.com/api/v1';

    /** 归一化用哪个模型：新键 normalize_model 优先，老键 edit_model 兜底 */
    private function normalizeModel(): string
    {
        return (string) (config('tryon.normalize_model') ?: config('tryon.edit_model'));
    }

    public function __construct(private readonly string $apiKey)
    {
    }

    public function normalize(string $imageUrl): string
    {
        $body = [
            'model' => $this->normalizeModel(),
            'input' => [
                'messages' => [[
                    'role'    => 'user',
                    'content' => [
                        ['image' => $imageUrl],
                        ['text'  => (string) config('tryon.normalize_prompt')],
                    ],
                ]],
            ],
            'parameters' => (object) [],
        ];

        $data = $this->post('/services/aigc/multimodal-generation/generation', $body, '归一化');

        $url = $data['output']['choices'][0]['message']['content'][0]['image'] ?? '';
        if (empty($url)) {
            throw new TryonException(4008, '归一化没有返回图片');
        }

        return (string) $url;
    }

    public function tryOn(string $personUrl, ?string $topUrl, ?string $bottomUrl): string
    {
        // 只有上装 / 下装两个槽位；鞋和配饰压根没有槽位（配置表里就不给它们留位置）
        if (empty($topUrl) && empty($bottomUrl)) {
            throw new TryonException(4008, '没有要试的衣服');
        }

        $input = ['person_image_url' => $personUrl];
        if (!empty($topUrl)) {
            $input['top_garment_url'] = $topUrl;
        }
        if (!empty($bottomUrl)) {
            $input['bottom_garment_url'] = $bottomUrl;
        }

        $taskId = $this->submitAsync('/services/aigc/image2image/image-synthesis', [
            'model'      => config('tryon.tryon_model'),
            'input'      => $input,
            'parameters' => [
                'resolution'   => -1,
                'n'            => 1,
                'restore_face' => true,
            ],
        ], '试穿');

        return $this->waitTask($taskId);
    }

    // ===== 内部 =====

    /** 提交异步任务，返回 task_id */
    private function submitAsync(string $path, array $body, string $what): string
    {
        $data = $this->post($path, $body, $what, ['X-DashScope-Async' => 'enable']);

        $taskId = $data['output']['task_id'] ?? '';
        if (empty($taskId)) {
            throw new TryonException(4008, $what . '任务提交失败');
        }

        return (string) $taskId;
    }

    /** 轮询异步任务直到出图（超时可配） */
    private function waitTask(string $taskId): string
    {
        $deadline = time() + (int) config('tryon.timeout_seconds', 180);

        while (time() < $deadline) {
            $data = $this->get('/tasks/' . $taskId, '试穿');

            $out    = $data['output'] ?? [];
            $status = (string) ($out['task_status'] ?? '');

            if ($status == 'SUCCEEDED') {
                $url = (string) ($out['image_url'] ?? '');
                if (empty($url)) {
                    throw new TryonException(4008, '试穿完成但没有图');
                }

                return $url;
            }

            if (in_array($status, ['FAILED', 'CANCELED', 'UNKNOWN'], true)) {
                // 把供应商的原话带出来（记进任务的 error，方便后台排查）
                throw new TryonException(4008, '试穿失败：' . ($out['message'] ?? $status));
            }

            usleep((int) config('tryon.poll_interval_ms', 2000) * 1000);
        }

        throw new TryonException(4008, '试穿 timeout：等图超时');
    }

    /** POST + 限流退避；4xx 直接抛（把供应商的 code/message 带出来） */
    private function post(string $path, array $body, string $what, array $headers = []): array
    {
        $tries = 3;

        for ($i = 1; $i <= $tries; $i++) {
            $res = Http::withToken($this->apiKey)
                ->withHeaders($headers)
                ->timeout(90)
                ->post(self::BASE . $path, $body);

            if ($res->successful()) {
                return (array) $res->json();
            }

            $code = (string) ($res->json('code') ?? '');
            $msg  = (string) ($res->json('message') ?? $res->body());

            // 限流：等一会儿再试（指数退避）
            if ($res->status() == 429 || str_contains($code, 'Throttling')) {
                Log::warning('[tryon] 被限流，稍后重试', ['what' => $what, 'try' => $i]);
                sleep($i * 5);
                continue;
            }

            Log::error('[tryon] ' . $what . ' 调用失败', ['code' => $code, 'msg' => $msg]);
            throw new TryonException(4008, $what . '调用失败：' . $code . ' ' . $msg);
        }

        throw new TryonException(4008, $what . '一直限流，稍后再试');
    }

    private function get(string $path, string $what): array
    {
        $res = Http::withToken($this->apiKey)->timeout(30)->get(self::BASE . $path);

        if (!$res->successful()) {
            throw new TryonException(4008, $what . '查询失败：' . $res->body());
        }

        return (array) $res->json();
    }
}
