<?php
namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UsageTest extends TestCase
{
    use RefreshDatabase;

    private function event(string $name, int $sequence, string $session = 'visit1'): array {
        return ['event_id'=>$session.'_'.$sequence, 'session_id'=>$session, 'name'=>$name, 'page'=>'wardrobe',
            'sequence'=>$sequence, 'occurred_at'=>now()->getTimestampMs() - 10000 + $sequence];
    }

    public function test_collection_is_authenticated_idempotent_and_minimal(): void {
        $e=$this->event('session_start',1);
        $this->postJson('/api/usage/events',['events'=>[$e]])->assertUnauthorized();
        $user=User::factory()->create(); Sanctum::actingAs($user);
        $this->postJson('/api/usage/events',['events'=>[$e+['user_id'=>999,'photo'=>'secret','notes'=>'private']]])->assertOk();
        $this->postJson('/api/usage/events',['events'=>[$e]])->assertOk();
        $this->assertSame(1, DB::table('usage_events')->count());
        $this->assertSame($user->id, DB::table('usage_events')->first()->user_id);
        $this->assertStringNotContainsString('private', json_encode(DB::table('usage_events')->first()));
        $this->postJson('/api/usage/events',['events'=>[$this->event('unknown',2)]])->assertStatus(422);
        $this->getJson('/api/admin/usage')->assertForbidden();
    }

    public function test_funnel_order_deduplication_errors_and_admin_exclusion(): void {
        Sanctum::actingAs(User::factory()->create());
        $steps=['session_start','wardrobe_empty','add_click','photo_success','edit_open','save_click','save_success'];
        $events=[];
        foreach($steps as $i=>$name) $events[]=$this->event($name,$i+1);
        $events[]=$this->event('save_success',8);
        $events[]=$this->event('session_start',1,'visit2');
        $events[]=$this->event('save_success',2,'visit2'); // 无中间步骤，不应计入完整漏斗
        $events[]=$this->event('save_fail',3,'visit2')+['error_code'=>'4001','duration_ms'=>1200];
        $this->postJson('/api/usage/events',['events'=>$events])->assertOk();
        $admin=User::factory()->create(['is_admin'=>true]); Sanctum::actingAs($admin);
        $this->postJson('/api/usage/events',['events'=>[$this->event('session_start',1,'admin')]])->assertOk();
        $this->getJson('/api/admin/usage?days=7')->assertOk()
            ->assertJsonPath('data.sessions',2)->assertJsonPath('data.funnel.0.count',2)
            ->assertJsonPath('data.funnel.1.count',1)->assertJsonPath('data.funnel.6.count',1)
            ->assertJsonPath('data.errors.0.count',1)->assertJsonCount(2,'data.visits');
        $this->getJson('/api/admin/usage?days=999')->assertStatus(422);
    }

    public function test_validation_limits_and_user_scoped_event_ids(): void {
        $a=User::factory()->create(); Sanctum::actingAs($a);
        $e=$this->event('session_start',1);
        $this->postJson('/api/usage/events',['events'=>array_fill(0,31,$e)])->assertStatus(422);
        $this->postJson('/api/usage/events',['events'=>[array_merge($e,['duration_ms'=>-1])]])->assertStatus(422);
        $this->postJson('/api/usage/events',['events'=>[array_merge($e,['error_code'=>'full user message'])]])->assertStatus(422);
        $this->postJson('/api/usage/events',['events'=>[$e]])->assertOk();
        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/usage/events',['events'=>[$e]])->assertOk();
        $this->assertSame(2,DB::table('usage_events')->count());
    }
}
