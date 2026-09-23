<?php

namespace Tests\Feature;

use App\Services\Assets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 插画资源表测试（2026-09 用户要的：给小程序加宣传语和插画）
 *
 * 背景：空态、关于页要配插画。图不放小程序包内（换图就得发版、还占包体积），
 * 而是放 OSS + 后端下发 URL：小程序启动拉一次 GET /api/assets，取不到就用本地缓存，
 * 缓存也没有就不显示插画（空态退回纯文字）。
 * 被测：App\Services\Assets（默认值 + 服务器文件覆盖）+ AssetsController + assets:export。
 *
 * 口径：
 *   - 免登录可访问（还没登录、还没录东西时的空态也要有图）
 *   - storage/app/assets.json **只写要改的条目**，其余自动用默认值
 *   - 空字符串是**有意义的值**：表示"这张不显示"（所以不能被过滤掉）
 *   - 文件坏了/不是字符串一律退回默认（图片问题不能把小程序搞崩）
 */
class AssetsTest extends TestCase
{
    use RefreshDatabase;

    private string $file;

    protected function setUp(): void
    {
        parent::setUp();
        $this->file = storage_path(Assets::FILE);
        $this->cleanupFile();
    }

    /** 每个用例跑完都要把临时资源文件删掉，否则会影响后面的用例 */
    protected function tearDown(): void
    {
        $this->cleanupFile();
        parent::tearDown();
    }

    private function cleanupFile(): void
    {
        if (isset($this->file) && is_file($this->file)) {
            @unlink($this->file);
        }
    }

    private function writeFile(string $raw): void
    {
        if (!is_dir(dirname($this->file))) {
            mkdir(dirname($this->file), 0755, true);
        }
        file_put_contents($this->file, $raw);
    }

    /**
     * 场景：小程序启动拉插画表（没登录也要能拉）
     *
     * 预期：code=0，items 里带上默认的几张（空态 3 张 + 关于页横幅），带 version 和 count
     */
    public function test_index_needs_no_login_and_returns_defaults(): void
    {
        $res = $this->getJson('/api/assets')->assertOk()->assertJson(['code' => 0]);

        $items = $res->json('data.items');
        foreach (['empty.wardrobe', 'empty.outfit', 'empty.calendar', 'about.hero'] as $key) {
            $this->assertArrayHasKey($key, $items, $key . ' 键位要在（值可以是空串）');
            // 默认空串 = 用小程序包内的本地图（真机踩坑后定的，见 Assets::DEFAULTS 的注释）；
            // 填了 URL 就必须是 https 的公网地址（小程序 image 直接加载，且不能是 webp）
            $this->assertTrue($items[$key] === '' || str_starts_with($items[$key], 'https://'));
        }

        $this->assertSame(count($items), $res->json('data.count'));
        $this->assertSame(12, strlen((string) $res->json('data.version')), 'version 是内容指纹（12 位）');
    }

    /**
     * 场景：服务器文件只覆盖它声明的那一条
     *
     * 预期：被覆盖的用新地址，其余仍是默认值
     */
    public function test_file_overrides_only_what_it_declares(): void
    {
        $this->writeFile(json_encode(['about.hero' => 'https://example.com/new-hero.webp'], JSON_UNESCAPED_SLASHES));

        $items = $this->getJson('/api/assets')->json('data.items');

        $this->assertSame('https://example.com/new-hero.webp', $items['about.hero']);
        $this->assertSame(Assets::DEFAULTS['empty.wardrobe'], $items['empty.wardrobe'], '没写的照旧');
    }

    /**
     * 场景：把某张插画撤掉（值写成空字符串）
     *
     * 预期：空值能下发出去（前端据此不显示那张图）—— 不能被 array_filter 顺手过滤掉
     */
    public function test_empty_string_turns_a_picture_off(): void
    {
        $this->writeFile(json_encode(['empty.outfit' => ''], JSON_UNESCAPED_SLASHES));

        $items = $this->getJson('/api/assets')->json('data.items');

        $this->assertArrayHasKey('empty.outfit', $items, 'key 还在（前端按空串处理）');
        $this->assertSame('', $items['empty.outfit']);
    }

    /**
     * 场景：服务器上的文件坏了（不是 json / 写了嵌套结构）
     *
     * 预期：退回默认值，接口照样 200（图片问题不能把小程序的空态搞崩）
     */
    public function test_broken_file_falls_back_to_defaults(): void
    {
        // 注意：key 里带点（empty.calendar），所以取值要先把 items 整个拿出来按数组下标取，
        // 不能用 json('data.items.empty.calendar') —— 那会被 data_get 当成三级路径，取到 null
        $this->writeFile('{ 这不是 json');
        $items = $this->getJson('/api/assets')->json('data.items');
        $this->assertSame(Assets::DEFAULTS['empty.calendar'], $items['empty.calendar']);

        $this->writeFile(json_encode(['about.hero' => ['nested' => 'array']]));
        $items = $this->getJson('/api/assets')->json('data.items');
        $this->assertSame(Assets::DEFAULTS['about.hero'], $items['about.hero'], '嵌套结构不认，退回默认');
    }

    /**
     * 场景：php artisan assets:export
     *
     * 预期：把当前生效的全量表落成文件（之后在服务器上改这个文件即可生效）
     */
    public function test_export_command_writes_full_file(): void
    {
        $this->artisan('assets:export')->assertSuccessful();

        $this->assertFileExists($this->file, '文件已生成');
        $json = json_decode((string) file_get_contents($this->file), true);
        $this->assertSame(Assets::DEFAULTS['empty.wardrobe'], $json['empty.wardrobe']);
        $this->assertCount(count(Assets::DEFAULTS), $json);
    }
}
