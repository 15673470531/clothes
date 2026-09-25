<?php

namespace Tests\Feature;

use App\Jobs\RunPersonalTryon;
use App\Models\ClothesItem;
use App\Models\User;
use App\Services\Tryon\TryonProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PersonalTryonTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Queue::fake();
        config(['tryon.personal_enabled' => true, 'tryon.api_key' => 'test', 'tryon.personal_daily_limit' => 1, 'queue.default' => 'database', 'app.url' => 'https://clothes.example']);
    }

    private function user(): User
    {
        $u = User::factory()->create(['is_admin' => true]);
        Sanctum::actingAs($u);

        return $u;
    }

    private function portrait(): int
    {
        return $this->postJson('/api/personal-tryon/portraits', ['photo' => UploadedFile::fake()->image('person.jpg', 400, 600)])->assertOk()->json('data.id');
    }

    private function item($u, string $category = 'top'): ClothesItem
    {
        return ClothesItem::create(['user_id' => $u->id, 'client_id' => 'top1', 'name' => '白T', 'category' => $category, 'image_url' => 'https://example.com/shirt.jpg', 'client_created_at' => 1]);
    }

    public function test_gate_ownership_and_signed_private_media(): void
    {
        $this->getJson('/api/personal-tryon')->assertUnauthorized();
        $u = $this->user();
        $id = $this->portrait();
        $url = $this->getJson('/api/personal-tryon')->json('data.portraits.0.url');
        $this->get($url)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->get('/api/personal-tryon/media/portrait/'.$id)->assertForbidden();
        $other = User::factory()->create(['is_admin' => true]);
        Sanctum::actingAs($other);
        $this->deleteJson('/api/personal-tryon/portrait/'.$id)->assertNotFound();
        $this->item($other);
        $this->postJson('/api/personal-tryon/tasks', ['portraitId' => $id, 'itemId' => 'top1'])->assertNotFound();
        $normal = User::factory()->create(['is_admin' => false]);
        Sanctum::actingAs($normal);
        $this->getJson('/api/personal-tryon')->assertJsonPath('data.allowed', false);
        $this->postJson('/api/personal-tryon/portraits')->assertForbidden();
        config(['tryon.personal_test_users' => [(string) $normal->id]]);
        $this->getJson('/api/personal-tryon?capability=1')->assertJsonPath('data.allowed', true)->assertJsonMissingPath('data.portraits');
    }

    public function test_submission_deduplicates_and_failure_refunds_without_hanger_changes(): void
    {
        $u = $this->user();
        $portrait = $this->portrait();
        $item = $this->item($u, 'bottom');
        $body = ['portraitId' => $portrait, 'itemId' => 'top1'];
        $this->postJson('/api/personal-tryon/tasks', $body)->assertUnprocessable();
        $item->update(['category' => 'top']);
        $id = $this->postJson('/api/personal-tryon/tasks', $body)->assertOk()->json('data.task.id');
        $this->postJson('/api/personal-tryon/tasks', $body)->assertJsonPath('data.task.id', $id);
        Queue::assertPushed(RunPersonalTryon::class, 1);
        $this->getJson('/api/personal-tryon')->assertJsonPath('data.leftToday', 0);
        (new RunPersonalTryon($id))->failed(new \RuntimeException('secret'));
        $this->getJson('/api/personal-tryon')->assertJsonPath('data.leftToday', 1);
        $second = $this->postJson('/api/personal-tryon/tasks', $body)->assertOk()->json('data.task.id');
        DB::table('personal_tryons')->where('id', $second)->update(['status' => 'done']);
        $this->deleteJson('/api/personal-tryon/result/'.$second)->assertOk();
        $this->postJson('/api/personal-tryon/tasks', $body)->assertUnprocessable();
    }

    public function test_worker_result_is_private_and_portrait_deletion_revokes_links(): void
    {
        $u = $this->user();
        $portrait = $this->portrait();
        $this->item($u);
        $id = $this->postJson('/api/personal-tryon/tasks', ['portraitId' => $portrait, 'itemId' => 'top1'])->json('data.task.id');
        $provider = \Mockery::mock(TryonProvider::class);
        $provider->shouldReceive('tryOn')->once()->withArgs(fn ($p, $top, $bottom) => str_contains($p, 'signature=') && $top === 'https://example.com/shirt.jpg' && $bottom === null)->andReturn('https://provider.example/result.png');
        $image = UploadedFile::fake()->image('result.png', 400, 600);
        Http::fake(['*' => Http::response(file_get_contents($image->getRealPath()))]);
        $job = new RunPersonalTryon($id);
        $job->handle($provider);
        $job->handle($provider);
        $row = DB::table('personal_tryons')->find($id);
        $this->assertSame('done', $row->status);
        Storage::disk('local')->assertExists($row->result_path);
        $result = $this->getJson('/api/personal-tryon')->json('data.tasks.0.resultUrl');
        $this->get($result)->assertOk();
        $this->deleteJson('/api/personal-tryon/portrait/'.$portrait)->assertOk();
        Storage::disk('local')->assertMissing($row->result_path);
        $this->get($result)->assertNotFound();
    }

    public function test_delete_during_generation_does_not_resurrect_result(): void
    {
        $u = $this->user();
        $portrait = $this->portrait();
        $this->item($u);
        $id = $this->postJson('/api/personal-tryon/tasks', ['portraitId' => $portrait, 'itemId' => 'top1'])->json('data.task.id');
        $provider = \Mockery::mock(TryonProvider::class);
        $provider->shouldReceive('tryOn')->once()->andReturnUsing(function () use ($portrait) {
            $this->deleteJson('/api/personal-tryon/portrait/'.$portrait)->assertOk();

            return 'https://provider.example/result.png';
        });
        $image = UploadedFile::fake()->image('result.png', 400, 600);
        Http::fake(['*' => Http::response(file_get_contents($image->getRealPath()))]);
        (new RunPersonalTryon($id))->handle($provider);
        $this->assertSame('', DB::table('personal_tryons')->find($id)->result_path);
        $this->assertCount(0, Storage::disk('local')->allFiles('personal-tryon/results'));
    }

    public function test_validation_and_disabled_gate(): void
    {
        $u = $this->user();
        $this->postJson('/api/personal-tryon/portraits', ['photo' => UploadedFile::fake()->create('x.txt')])->assertUnprocessable();
        $p = $this->portrait();
        $this->item($u);
        config(['tryon.personal_enabled' => false]);
        $this->postJson('/api/personal-tryon/tasks',['portraitId' => $p, 'itemId' => 'top1'])->assertUnprocessable();
        Queue::assertNothingPushed();
    }
}
