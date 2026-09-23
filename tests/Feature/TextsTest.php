<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Texts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 文案表测试（2026-09：前端所有提示改由后端下发，随时能改不用发版）
 *
 * 背景：小程序里原本写死了 80 多处提示语，改一句话就得重新发版。
 * 现在统一走 GET /api/texts：后端下发、小程序启动拉一次存本地，取不到就用本地兜底。
 * 被测：App\Services\Texts（默认值 + 服务器文件覆盖）+ TextsController + texts:export。
 *
 * 口径：
 *   - 免登录可访问（登录前的提示也要有文案）
 *   - 服务器上的 storage/app/texts.json **只写要改的条目**，其余自动用默认值
 *   - 文件坏了/不是字符串一律退回默认值（文案问题不能把小程程搞崩）
 *   - 带数字的能后端拼就后端拼（reward 的 toast 就是这么来的）
 */
class TextsTest extends TestCase
{
    use RefreshDatabase;

    private string $file;

    protected function setUp(): void
    {
        parent::setUp();
        $this->file = storage_path(Texts::FILE);
        $this->cleanupFile();
    }

    /** 每个用例跑完都要把临时文案文件删掉，否则会影响后面的用例 */
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
     * 场景：小程序启动拉文案
     *
     * 预期：**不用登录**就能拿到（登录前的提示也要有），返回全量默认文案 + 内容指纹
     */
    public function test_index_needs_no_login_and_returns_defaults(): void
    {
        $res = $this->getJson('/api/texts')->assertOk()->assertJson(['code' => 0]);

        $data = $res->json('data');
        $this->assertArrayHasKey('texts', $data, '出参要带 texts');
        $this->assertSame('先登录微信再来', $data['texts']['reward.need_login'], '拿到默认文案');
        $this->assertSame(count(Texts::DEFAULTS), $data['count'], '默认是全量');
        $this->assertSame(md5(json_encode($data['texts'])), $data['version'], 'version 是内容指纹');
    }

    /**
     * 场景：在服务器上只改一条文案（storage/app/texts.json 里写一条）
     *
     * 预期：那条变成新文案，其它仍然用默认值（以后新增文案，老文件也不用补）
     */
    public function test_file_overrides_only_what_it_declares(): void
    {
        $this->writeFile(json_encode(['mine.logged_out' => '拜拜，下次再来'], JSON_UNESCAPED_UNICODE));

        $data = $this->getJson('/api/texts')->json('data.texts');

        $this->assertSame('拜拜，下次再来', $data['mine.logged_out'], '文件里的覆盖生效');
        $this->assertSame(Texts::DEFAULTS['mine.login_ok'], $data['mine.login_ok'], '没写的照旧用默认');
    }

    /**
     * 场景：文案文件被改坏了（不是合法 JSON / 值不是字符串）
     *
     * 预期：一律退回默认值 —— 改错文件最多是"文案没生效"，绝不能让小程序挂掉
     */
    public function test_broken_file_falls_back_to_defaults(): void
    {
        $this->writeFile('{ 这不是 JSON');
        $data = $this->getJson('/api/texts')->json('data.texts');
        $this->assertSame(Texts::DEFAULTS['mine.logged_out'], $data['mine.logged_out'], '坏 JSON → 默认值');

        // 值写成数组/数字：忽略这一条，别把对象塞给前端
        $this->writeFile('{"mine.logged_out": {"a":1}, "mine.login_ok": 123}');
        $data = $this->getJson('/api/texts')->json('data.texts');
        $this->assertSame(Texts::DEFAULTS['mine.logged_out'], $data['mine.logged_out'], '非字符串 → 忽略');
        $this->assertSame(Texts::DEFAULTS['mine.login_ok'], $data['mine.login_ok'], '数字也忽略');
    }

    /**
     * 场景：后端自己拼带数字的文案（用户定的"能拼就后端拼"）
     *
     * 预期：签到接口直接返回拼好的整句（+2 来自配置），前端拿到就能显示
     */
    public function test_reward_toast_is_composed_by_backend(): void
    {
        $user = User::factory()->create([
            'item_quota'       => 3,
            'daily_quota'      => 5,
            'daily_reset_date' => today()->toDateString(),
        ]);
        Sanctum::actingAs($user);

        $res = $this->postJson('/api/hanger/checkin')->assertOk()->assertJson(['code' => 0]);
        $this->assertSame('签到成功 +2 个衣架', $res->json('data.toast'), '后端拼好的提示语');

        // 改文案表 → 提示语跟着变（这就是"随时能改提示"的意义）
        $this->writeFile(json_encode(['reward.checkin_ok' => '打卡成功，+{n} 个衣架到手'], JSON_UNESCAPED_UNICODE));
        $res2 = $this->postJson('/api/hanger/share')->assertOk()->assertJson(['code' => 0]);
        $this->assertSame(
            '分享成功 +10 个衣架',
            $res2->json('data.toast'),
            '只改了签到那条，分享那条仍用默认'
        );
    }

    /**
     * 场景：php artisan texts:export
     *
     * 预期：把当前生效的全量文案落成文件，之后就能在那个文件上改
     */
    public function test_export_command_writes_full_file(): void
    {
        $this->artisan('texts:export')->assertSuccessful();

        $this->assertFileExists($this->file, '文件已生成');
        $json = json_decode((string) file_get_contents($this->file), true);
        $this->assertSame(count(Texts::DEFAULTS), count($json), '导出的是全量');
        $this->assertSame(Texts::DEFAULTS['mine.login_ok'], $json['mine.login_ok']);
    }
}
