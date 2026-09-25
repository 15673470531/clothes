<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\ClothesItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ItemDetailsTest extends TestCase
{
    use RefreshDatabase;

    public function test_details_round_trip_legacy_update_and_clear(): void
    {
        Sanctum::actingAs(User::factory()->create(['item_quota' => 100, 'daily_quota' => 100, 'daily_reset_date' => today()]));
        $item = ['id' => 'detail-test', 'category' => 'top', 'name' => '衬衫'];
        $details = ['size' => 'M', 'brand' => '测试品牌', 'price' => '129.90', 'notes' => '手洗'];
        $this->postJson('/api/clothes/sync', ['items' => [$item + ['details' => $details]]])->assertOk()->assertJsonPath('code', 0);
        $this->getJson('/api/clothes/sync')->assertOk()->assertJsonPath('data.items.0.details', $details);
        $this->postJson('/api/clothes/sync', ['items' => [$item]])->assertOk();
        $this->assertSame($details, ClothesItem::first()->details);
        $this->postJson('/api/clothes/sync', ['items' => [$item + ['details' => []]]])->assertOk();
        $this->assertSame([], ClothesItem::first()->details);
    }

    public function test_invalid_details_are_rejected_without_creating_items(): void
    {
        Sanctum::actingAs(User::factory()->create());
        foreach ([['price' => '-1'], ['price' => '12.345'], ['size' => str_repeat('x', 25)], ['notes' => str_repeat('x', 501)], ['unknown' => 'x']] as $details) {
            $this->postJson('/api/clothes/sync', ['items' => [['id' => 'invalid', 'details' => $details]]])->assertStatus(422);
        }
        $this->assertSame(0, ClothesItem::count());
    }
}
