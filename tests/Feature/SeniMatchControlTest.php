<?php

namespace Tests\Feature;

use App\Models\Arena;
use App\Models\Role;
use App\Models\SeniPool;
use App\Models\SeniSingleMatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SeniMatchControlTest extends TestCase
{
    use RefreshDatabase;

    public function test_operator_can_view_seni_match_control_page(): void
    {
        $operator = Role::create(['name' => 'Operator']);
        $user = User::factory()->create(['role_id' => $operator->id]);

        $pool = SeniPool::create([
            'no_pool_babak_id' => 55,
            'round_match' => 'Final',
            'group' => 'Putra',
            'category' => 'Tunggal',
            'no_pool' => 'A',
        ]);
        SeniSingleMatch::create([
            'no_pool_babak_id' => $pool->no_pool_babak_id,
            'bkp_id' => 3410,
            'matches_code' => '135',
            'atletes' => 'Atlet A',
            'contingent' => 'Kontingen A',
            'type' => 'tunggal',
            'category' => 'Tunggal',
            'group' => 'Putra',
            'status' => 'done',
            'round_match' => 'Final',
            'no_order' => 1,
        ]);
        Arena::create(['sesi_seni_id' => 7]);

        $this->actingAs($user)
            ->get(route('seni-match-control'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('SeniMatchControl')
                ->has('pools', 1)
                ->has('matches', 1)
                ->where('activePool.id', $pool->id)
                ->has('arena')
            );
    }

    public function test_non_operator_cannot_view_seni_match_control_page(): void
    {
        $jury = Role::create(['name' => 'Juri']);
        $user = User::factory()->create(['role_id' => $jury->id]);

        $this->actingAs($user)
            ->get(route('seni-match-control'))
            ->assertForbidden();
    }

    public function test_seni_arena_setup_fetches_and_saves_pools(): void
    {
        Http::fake([
            '*/partai-seni/pools/7' => Http::response([
                'status' => 'success',
                'data' => [
                    [
                        'no_pool_babaks_id' => 55,
                        'round_match' => 'Final',
                        'group' => 'Putra',
                        'category' => 'Tunggal',
                        'no_pool' => 'A',
                    ],
                ],
            ], 200),
            '*' => Http::response([], 404),
        ]);

        $operator = Role::create(['name' => 'Operator']);
        $user = User::factory()->create(['role_id' => $operator->id]);

        $response = $this
            ->actingAs($user)
            ->postJson('/api/seni/arena/setup', [
                'gelanggang_id' => 3,
                'sesi_seni_id' => 7,
                'arena_name' => 'Gelanggang A',
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('pools_count', 1)
            ->assertJsonPath('data.0.no_pool_babak_id', 55);

        $this->assertDatabaseHas('arenas', [
            'id' => 1,
            'gelanggang_id' => 3,
            'sesi_seni_id' => 7,
            'arena_name' => 'Gelanggang A',
        ]);
        $this->assertDatabaseHas('seni_pools', [
            'no_pool_babak_id' => 55,
            'round_match' => 'Final',
            'group' => 'Putra',
            'category' => 'Tunggal',
            'no_pool' => 'A',
        ]);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/partai-seni/pools/7'));
    }

    public function test_sync_pool_matches_replaces_local_matches_as_inactive_rows(): void
    {
        Http::fake([
            '*/partai-seni/55' => Http::response([
                'status' => 'success',
                'message' => 'Partai seni data retrieved successfully',
                'data' => [
                    [
                        'partai_senis_id' => 1,
                        'bkp_id' => 3410,
                        'match_code' => '135',
                        'atlets' => [
                            'Atlet A',
                            'Atlet B',
                        ],
                        'contingent' => 'SDN MLANGSEN BLORA',
                        'match_number' => 1,
                        'status' => 'done',
                    ],
                ],
            ], 200),
        ]);

        $operator = Role::create(['name' => 'Operator']);
        $user = User::factory()->create(['role_id' => $operator->id]);
        $pool = SeniPool::create([
            'no_pool_babak_id' => 55,
            'round_match' => 'Final',
            'group' => 'Putra',
            'category' => 'Tunggal',
            'no_pool' => 'A',
        ]);
        SeniSingleMatch::create([
            'no_pool_babak_id' => 99,
            'bkp_id' => 999,
            'matches_code' => 'OLD',
            'atletes' => 'Old Atlet',
            'contingent' => 'Old Kontingen',
            'type' => 'tunggal',
            'category' => 'Tunggal',
            'group' => 'Putra',
            'status' => 'done',
            'round_match' => 'Final',
            'no_order' => 99,
        ]);

        $response = $this
            ->actingAs($user)
            ->postJson("/api/seni/pools/{$pool->id}/sync-matches");

        $response
            ->assertOk()
            ->assertJsonPath('data.0.no_pool_babak_id', 55)
            ->assertJsonPath('data.0.bkp_id', 3410)
            ->assertJsonPath('data.0.matches_code', '135')
            ->assertJsonPath('data.0.atletes', 'Atlet A, Atlet B')
            ->assertJsonPath('data.0.total_score', null)
            ->assertJsonPath('data.0.is_active', false);

        $this->assertDatabaseHas('seni_single_matches', [
            'no_pool_babak_id' => 55,
            'bkp_id' => 3410,
            'matches_code' => '135',
            'atletes' => 'Atlet A, Atlet B',
            'contingent' => 'SDN MLANGSEN BLORA',
            'status' => 'done',
            'is_active' => false,
        ]);
        $this->assertDatabaseMissing('seni_single_matches', [
            'bkp_id' => 999,
            'matches_code' => 'OLD',
        ]);
        $this->assertDatabaseCount('seni_single_matches', 1);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/partai-seni/55'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/partai-seni/detail-partai-seni-ts/3410'));
    }

    public function test_activate_match_fetches_detail_scores_and_saves_jury_scores(): void
    {
        Http::fake([
            '*/partai-seni/detail-partai-seni-ts/3410' => Http::response([
                'status' => 'success',
                'data' => [
                    'total_score' => '378.000',
                    'total_punishment' => null,
                    'rank' => null,
                    'is_passed' => 0,
                    'is_disqualified' => 0,
                    'time' => 140,
                    'tgr_jury_scores' => [
                        ['jury_number' => 1, 'score' => 56, 'ref_tgr_score' => 'wiraga'],
                        ['jury_number' => 1, 'score' => 56, 'ref_tgr_score' => 'wirasa'],
                        ['jury_number' => 1, 'score' => 32, 'ref_tgr_score' => 'wirama'],
                        ['jury_number' => 2, 'score' => 40, 'ref_tgr_score' => 'wiraga'],
                        ['jury_number' => 2, 'score' => 20, 'ref_tgr_score' => 'wirasa'],
                        ['jury_number' => 2, 'score' => 10, 'ref_tgr_score' => 'wirama'],
                    ],
                    'tgr_jury_total_scores' => [
                        ['jury_number' => 1, 'total_score' => 144, 'is_accepted' => 1],
                        ['jury_number' => 2, 'total_score' => 70, 'is_accepted' => 0],
                    ],
                    'total_wiraga' => 0,
                    'total_wirasa' => 0,
                    'total_wirama' => 0,
                ],
            ], 200),
        ]);

        $operator = Role::create(['name' => 'Operator']);
        $user = User::factory()->create(['role_id' => $operator->id]);
        SeniPool::create([
            'no_pool_babak_id' => 55,
            'round_match' => 'Final',
            'group' => 'Putra',
            'category' => 'Tunggal',
            'no_pool' => 'A',
        ]);
        $match = SeniSingleMatch::create([
            'no_pool_babak_id' => 55,
            'bkp_id' => 3410,
            'matches_code' => '135',
            'atletes' => 'Atlet A',
            'contingent' => 'Kontingen A',
            'type' => 'tunggal',
            'category' => 'Tunggal',
            'group' => 'Putra',
            'status' => 'not_started',
            'round_match' => 'Final',
            'no_order' => 1,
        ]);

        $response = $this
            ->actingAs($user)
            ->postJson("/api/seni/matches/{$match->id}/activate");

        $response
            ->assertOk()
            ->assertJsonPath('data.id', $match->id)
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.total_score', '378.000')
            ->assertJsonPath('data.time', 140)
            ->assertJsonPath('jury_scores.0.jury_number', 1);

        $this->assertDatabaseHas('seni_single_matches', [
            'id' => $match->id,
            'is_active' => true,
            'total_score' => '378.000',
            'time' => 140,
        ]);
        $this->assertDatabaseHas('seni_jury_scores', [
            'seni_single_match_id' => $match->id,
            'jury_number' => 1,
            'wiraga' => '56.000',
            'wirasa' => '56.000',
            'wirama' => '32.000',
            'total_score' => '144.000',
            'is_accepted' => true,
        ]);
        $this->assertDatabaseCount('seni_jury_scores', 2);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/partai-seni/detail-partai-seni-ts/3410'));
    }

    public function test_cannot_activate_another_match_when_a_match_is_locked(): void
    {
        Http::fake();

        $operator = Role::create(['name' => 'Operator']);
        $user = User::factory()->create(['role_id' => $operator->id]);
        SeniPool::create([
            'no_pool_babak_id' => 55,
            'round_match' => 'Final',
            'group' => 'Putra',
            'category' => 'Tunggal',
            'no_pool' => 'A',
        ]);
        SeniSingleMatch::create([
            'no_pool_babak_id' => 55,
            'bkp_id' => 3410,
            'matches_code' => '135',
            'atletes' => 'Atlet A',
            'contingent' => 'Kontingen A',
            'type' => 'tunggal',
            'category' => 'Tunggal',
            'group' => 'Putra',
            'status' => 'ongoing',
            'is_active' => true,
            'round_match' => 'Final',
            'no_order' => 1,
        ]);
        $nextMatch = SeniSingleMatch::create([
            'no_pool_babak_id' => 55,
            'bkp_id' => 3411,
            'matches_code' => '136',
            'atletes' => 'Atlet B',
            'contingent' => 'Kontingen B',
            'type' => 'tunggal',
            'category' => 'Tunggal',
            'group' => 'Putra',
            'status' => 'not_started',
            'round_match' => 'Final',
            'no_order' => 2,
        ]);

        $this
            ->actingAs($user)
            ->postJson("/api/seni/matches/{$nextMatch->id}/activate")
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        Http::assertNothingSent();
    }

    public function test_active_match_status_can_be_updated(): void
    {
        $operator = Role::create(['name' => 'Operator']);
        $user = User::factory()->create(['role_id' => $operator->id]);
        SeniPool::create([
            'no_pool_babak_id' => 55,
            'round_match' => 'Final',
            'group' => 'Putra',
            'category' => 'Tunggal',
            'no_pool' => 'A',
        ]);
        $match = SeniSingleMatch::create([
            'no_pool_babak_id' => 55,
            'bkp_id' => 3410,
            'matches_code' => '135',
            'atletes' => 'Atlet A',
            'contingent' => 'Kontingen A',
            'type' => 'tunggal',
            'category' => 'Tunggal',
            'group' => 'Putra',
            'status' => 'not_started',
            'is_active' => true,
            'round_match' => 'Final',
            'no_order' => 1,
        ]);

        $this
            ->actingAs($user)
            ->postJson("/api/seni/matches/{$match->id}/status", [
                'status' => 'ongoing',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'ongoing');

        $this->assertDatabaseHas('seni_single_matches', [
            'id' => $match->id,
            'status' => 'ongoing',
        ]);
    }
}
