<?php

namespace Tests\Feature;

use App\Services\ImageStorage;
use App\Services\Tryon\TryonProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 归一化复跑命令（tryon:normalize · 2026-09）
 *
 * 被测：App\Console\Commands\TryonNormalize
 *
 * 为什么值得测：这个命令是"花钱的手"，它要保证三件事 ——
 *   ① 我传的模型/提示词**真的**进了请求体（不然对比半天是白比）
 *   ② 多模型对比时每个模型各发一次、各存一张、对比页里都有（不然白跑）
 *   ③ 失败不能把整趟带崩，原因要落到 result.json（不然钱花了还不知道为什么）
 *
 * 全部走 Http 桩 + 假存储：不碰网络、不碰 OSS、一分钱不花。
 * 真调用是命令的另一半用途（人自己跑，见 docs/AI试穿.md 的"复跑与换模型"）。
 */
class TryonNormalizeCommandTest extends TestCase
{
    /** 假存储：本地图"上传"后返回一个固定形状的公网地址 */
    private FakeNormalizeStorage $storage;

    /** 临时工作目录（输出目录和原图都在里面，跑完删掉，不污染 storage/app） */
    private string $tmp;

    /** 输出目录（每个用例一个临时目录，不污染 storage/app） */
    private string $out;

    /** 临时图片 */
    private string $photo;

    protected function setUp(): void
    {
        parent::setUp();

        // 临时目录唯一、文件名固定叫 jeans.jpg：命令用文件名给结果图命名，名字稳定断言才好写
        $this->tmp   = sys_get_temp_dir() . '/tryon-normalize-' . uniqid();
        $this->out   = $this->tmp . '/out';
        $this->photo = $this->tmp . '/jeans.jpg';
        @mkdir($this->tmp, 0755, true);
        file_put_contents($this->photo, 'JPEG-BYTES');

        // 只配"命令会读到的"那几个配置；模型/提示词故意用显眼的假值，方便断言有没有被原样发出去
        config([
            'tryon.api_key'         => 'test-key',
            'tryon.edit_model'      => 'qwen-image-edit',
            'tryon.normalize_prompt' => '把衣服抠出来放在纯白背景上',
            'tryon.model_image'     => 'https://cdn.example.com/tryon/model.png',
            'tryon.tryon_model'     => 'aitryon',
        ]);

        $this->storage = new FakeNormalizeStorage();
        $this->app->instance(ImageStorage::class, $this->storage);
    }

    protected function tearDown(): void
    {
        foreach ([$this->photo] as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }

        foreach ((array) glob($this->tmp . '/*/*') as $f) {
            @unlink($f);
        }

        foreach ((array) glob($this->tmp . '/*') as $f) {
            is_dir($f) ? @rmdir($f) : @unlink($f);
        }

        if (is_dir($this->tmp)) {
            @rmdir($this->tmp);
        }

        parent::tearDown();
    }

    /**
     * 桩：归一化返回图片地址，试衣走"提交任务 + 轮询"，下载任意地址都返回一段字节
     *
     * 按 URL 分流（真实链路就是打这三个端点），最后一个兜底分支负责"下载结果图"。
     */
    private function fakeDashScope(int $normalizeStatus = 200): void
    {
        Http::fake(function (Request $request) use ($normalizeStatus) {
            $url = $request->url();

            if (str_contains($url, 'multimodal-generation')) {
                if ($normalizeStatus != 200) {
                    return Http::response(['code' => 'InvalidParameter', 'message' => '照片不合规'], $normalizeStatus);
                }

                return Http::response(['output' => ['choices' => [['message' => ['content' => [['image' => 'https://tmp.example.com/norm.png']]]]]]], 200);
            }

            if (str_contains($url, 'image2image')) {
                return Http::response(['output' => ['task_id' => 'task-1']], 200);
            }

            if (str_contains($url, '/tasks/')) {
                return Http::response(['output' => ['task_status' => 'SUCCEEDED', 'image_url' => 'https://tmp.example.com/result.jpg']], 200);
            }

            return Http::response('IMAGE-BYTES', 200);
        });
    }

    /** 从桩里捞出"发给归一化接口"的请求体 */
    private function normalizeBodies(): array
    {
        $bodies = [];

        Http::assertSent(function (Request $r) use (&$bodies) {
            if (str_contains($r->url(), 'multimodal-generation')) {
                $bodies[] = $r->data();
            }

            return true;
        });

        return $bodies;
    }

    /**
     * 场景：--dry-run
     * 预期：一个请求都不发、一个文件都不传（"先看不花钱的"），退出码 0
     */
    public function test_dry_run_sends_nothing(): void
    {
        Http::fake();

        $this->artisan('tryon:normalize', ['image' => [$this->photo], '--dry-run' => true])
            ->expectsOutputToContain('--dry-run')
            ->assertExitCode(0);

        Http::assertNothingSent();
        $this->assertSame(0, $this->storage->uploads, 'dry-run 不该传 OSS');
    }

    /**
     * 场景：不给 --model / --prompt，走默认配置
     * 预期：请求体里 model 和提示词就是 config 里那两个，图是传进去的那张（公网直链直接用）
     */
    public function test_uses_configured_model_and_prompt(): void
    {
        $this->fakeDashScope();

        $this->artisan('tryon:normalize', [
            'image'   => ['https://cdn.example.com/clothes/jeans.jpg'],
            '--out'   => $this->out,
            '--url-only' => true,
        ])->assertExitCode(0);

        $bodies = $this->normalizeBodies();
        $this->assertCount(1, $bodies, '应该只调一次归一化');
        $this->assertSame('qwen-image-edit', $bodies[0]['model']);
        $this->assertSame('把衣服抠出来放在纯白背景上', $bodies[0]['input']['messages'][0]['content'][1]['text']);
        $this->assertSame('https://cdn.example.com/clothes/jeans.jpg', $bodies[0]['input']['messages'][0]['content'][0]['image']);
        $this->assertSame(0, $this->storage->uploads, '公网直链不用再传一遍');
    }

    /**
     * 场景：--model 换成别的模型（用户说的"以后还要测别的模型"）
     * 预期：请求体里的 model 跟着换 —— 这是这个命令通用性的根本
     */
    public function test_model_option_replaces_model(): void
    {
        $this->fakeDashScope();

        $this->artisan('tryon:normalize', [
            'image'      => ['https://cdn.example.com/clothes/jeans.jpg'],
            '--model'    => ['qwen-image-edit-plus'],
            '--out'      => $this->out,
            '--url-only' => true,
        ])->assertExitCode(0);

        $bodies = $this->normalizeBodies();
        $this->assertCount(1, $bodies);
        $this->assertSame('qwen-image-edit-plus', $bodies[0]['model']);
    }

    /**
     * 场景：--prompt-file 给提示词（文件 > 命令行 > 配置）
     * 预期：请求体里的提示词等于文件内容，末尾换行被去掉
     */
    public function test_prompt_file_wins(): void
    {
        $this->fakeDashScope();

        $file = $this->out . '/prompt.txt';
        @mkdir($this->out, 0755, true);
        file_put_contents($file, "抠出这件衣服，正面平铺在纯白背景上\n去掉地板阴影杂物\n");

        $this->artisan('tryon:normalize', [
            'image'         => ['https://cdn.example.com/clothes/jeans.jpg'],
            '--prompt'      => '这条应该被文件盖掉',
            '--prompt-file' => $file,
            '--out'         => $this->out,
            '--url-only'    => true,
        ])->assertExitCode(0);

        $bodies = $this->normalizeBodies();
        $this->assertSame("抠出这件衣服，正面平铺在纯白背景上\n去掉地板阴影杂物", $bodies[0]['input']['messages'][0]['content'][1]['text']);
    }

    /**
     * 场景：传本地图片路径
     * 预期：先传到我们 OSS 换成公网 https 再喂给接口（阿里云只吃公网 https 直链）
     */
    public function test_local_file_is_uploaded_first(): void
    {
        $this->fakeDashScope();

        $this->artisan('tryon:normalize', [
            'image'      => [$this->photo],
            '--out'      => $this->out,
            '--url-only' => true,
        ])->assertExitCode(0);

        $this->assertSame(1, $this->storage->uploads, '本地图应该先传一次');
        $this->assertStringContainsString('tryon/test', (string) $this->storage->lastDir);

        $bodies = $this->normalizeBodies();
        $this->assertSame('https://cdn.example.com/oss/tryon/test/202609/fake.jpg', $bodies[0]['input']['messages'][0]['content'][0]['image']);
    }

    /**
     * 场景：一次跑两个模型做对比
     * 预期：各发一次请求、各存一张结果图、对比页里两个模型都在（用户就是靠这个页面挑模型）
     */
    public function test_multiple_models_produce_compare_page(): void
    {
        $this->fakeDashScope();

        $this->artisan('tryon:normalize', [
            'image'   => [$this->photo],
            '--model' => ['qwen-image-edit', 'qwen-image-edit-plus'],
            '--out'   => $this->out,
        ])->assertExitCode(0);

        $bodies = $this->normalizeBodies();
        $this->assertCount(2, $bodies, '两个模型应该各调一次');
        $this->assertSame(['qwen-image-edit', 'qwen-image-edit-plus'], array_column($bodies, 'model'));

        // 两张结果图 + 一张原图 + 对比页 + 结果 json
        $files = array_map('basename', (array) glob($this->out . '/*'));
        $this->assertContains('out-jeans__qwen-image-edit.png', $files);
        $this->assertContains('out-jeans__qwen-image-edit-plus.png', $files);
        $this->assertContains('source-jeans.jpg', $files, '原图要拷一份当对照');
        $this->assertContains('index.html', $files);
        $this->assertContains('result.json', $files);

        $html = (string) file_get_contents($this->out . '/index.html');
        $this->assertStringContainsString('qwen-image-edit-plus', $html);
        $this->assertStringContainsString('把衣服抠出来放在纯白背景上', $html, '对比页上要能看到用的是哪条提示词');
    }

    /**
     * 场景：--with-tryon（洗完之后接着试穿）
     * 预期：试衣请求带模特图 + 把白底图放进指定槽位，结果图也存下来
     */
    public function test_with_tryon_runs_second_call(): void
    {
        $this->fakeDashScope();

        $this->artisan('tryon:normalize', [
            'image'        => [$this->photo],
            '--out'        => $this->out,
            '--with-tryon' => true,
            '--slot'       => 'bottom',
        ])->assertExitCode(0);

        $tryonBody = null;
        Http::assertSent(function (Request $r) use (&$tryonBody) {
            if (str_contains($r->url(), 'image2image')) {
                $tryonBody = $r->data();
            }

            return true;
        });

        $this->assertNotEmpty($tryonBody, '应该发过一次试衣请求');
        $this->assertSame('aitryon', $tryonBody['model']);
        $this->assertSame('https://cdn.example.com/tryon/model.png', $tryonBody['input']['person_image_url']);
        $this->assertSame('https://tmp.example.com/norm.png', $tryonBody['input']['bottom_garment_url']);
        $this->assertArrayNotHasKey('top_garment_url', $tryonBody, '走 bottom 槽位就不该传上装');

        $files = array_map('basename', (array) glob($this->out . '/*'));
        $this->assertContains('tryon-jeans__qwen-image-edit.jpg', $files);
    }

    /**
     * 场景：供应商报错（比如照片不合规 / 限流后仍失败）
     * 预期：不崩、退出码非 0、原因写进 result.json —— 失败不计费，但要知道为什么
     */
    public function test_failure_is_recorded_and_exit_code_nonzero(): void
    {
        $this->fakeDashScope(500);

        $this->artisan('tryon:normalize', [
            'image' => [$this->photo],
            '--out' => $this->out,
        ])->assertExitCode(1);

        $meta = json_decode((string) file_get_contents($this->out . '/result.json'), true);
        $this->assertNotEmpty($meta['rows'][0]['cells'][0]['error'], '失败原因要记下来');
        $this->assertFalse($meta['rows'][0]['cells'][0]['ok']);
        $this->assertSame([], $meta['spent'], '没有成功调用就没有花费');
    }

    /**
     * 场景：没配 API key 就想真跑
     * 预期：直接拒绝并提示走不花钱的测试，不发请求
     */
    public function test_missing_api_key_refuses_to_run(): void
    {
        config(['tryon.api_key' => '']);
        Http::fake();

        $this->artisan('tryon:normalize', ['image' => [$this->photo]])
            ->expectsOutputToContain('DASHSCOPE_API_KEY')
            ->assertExitCode(1);

        Http::assertNothingSent();
    }
}

/**
 * 假存储：不碰 OSS，记下传了几次、传到哪个目录，返回固定形状的公网地址
 *
 * 为什么不用 TyronTest 里那个 FakeImageStorage：PHPUnit 按文件加载测试类，
 * 单独 --filter 跑本文件时那个类不存在，所以这里自带一份。
 */
class FakeNormalizeStorage extends ImageStorage
{
    public int $uploads = 0;
    public ?string $lastDir = null;

    public function putBinary(string $contents, string $ext, string $dir): array
    {
        $this->uploads++;
        $this->lastDir = $dir;

        return ['path' => $dir . '/202609/fake.jpg', 'url' => 'https://cdn.example.com/oss/' . $dir . '/202609/fake.jpg', 'driver' => 'oss'];
    }
}
