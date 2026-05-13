<?php

namespace Tests\Feature;

use App\Events\SeniJuryScoreUpdated;
use App\Events\SeniMatchUpdated;
use App\Models\Arena;
use App\Models\Role;
use App\Models\SeniPool;
use App\Models\SeniSingleMatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
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
                        ['jury_number' => 1, 'waktu' => 0, 'wiraga' => 56, 'wirasa' => 56, 'wirama' => 32],
                        ['jury_number' => 2, 'waktu' => 1, 'wiraga' => 40, 'wirasa' => 20, 'wirama' => 10],
                    ],
                    'tgr_jury_punishments' => [
                        [
                            'jury_number' => 1,
                            'waktu' => 0,
                            'keluar_garis' => 1,
                            'senjata_jatuh_atau_tidak_sesuai_deskripsi' => 0,
                            'akeseoris_jatuh' => 0,
                        ],
                        [
                            'jury_number' => 2,
                            'waktu' => 1,
                            'keluar_garis' => 0,
                            'senjata_jatuh_atau_tidak_sesuai_deskripsi' => 1,
                            'akeseoris_jatuh' => 0,
                        ],
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
            ->assertJsonPath('jury_scores.0.jury_number', 1)
            ->assertJsonPath('jury_punishments.0.jury_number', 1);

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
        $this->assertDatabaseHas('seni_jury_punishments', [
            'seni_single_match_id' => $match->id,
            'jury_number' => 1,
            'waktu' => '0.000',
            'keluar_garis' => '1.000',
            'senjata_jatuh_atau_tidak_sesuai_deskripsi' => '0.000',
            'akeseoris_jatuh' => '0.000',
        ]);
        $this->assertDatabaseCount('seni_jury_scores', 2);
        $this->assertDatabaseCount('seni_jury_punishments', 2);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/partai-seni/detail-partai-seni-ts/3410'));
    }

    public function test_activate_ganda_match_maps_technique_score_format(): void
    {
        Http::fake([
            '*/partai-seni/detail-partai-seni-ts/3412' => Http::response([
                'status' => 'success',
                'data' => [
                    'total_score' => '95.000',
                    'total_punishment' => '1.000',
                    'rank' => null,
                    'is_passed' => 0,
                    'is_disqualified' => 0,
                    'time' => 145,
                    'tgr_jury_scores' => [
                        [
                            'jury_number' => 1,
                            'kualitas_teknik' => 20,
                            'kuantitas_teknik' => 18,
                            'ketangkasan' => 15,
                            'stamina' => 14,
                            'kemantapan' => 13,
                            'musik' => 12,
                        ],
                    ],
                    'tgr_jury_punishments' => [
                        [
                            'jury_number' => 1,
                            'waktu' => 1,
                            'keluar garis' => 1,
                            'senjata_jatuh_atau_tidak_sesuai_deskripsi' => 0,
                            'senjata_tidak_jatuh_atau_tidak_sesuai_deskripsi' => 1,
                        ],
                    ],
                    'tgr_jury_total_scores' => [],
                    'total_kualitas_teknik' => 20,
                    'total_kuantitas_teknik' => 18,
                    'total_ketangkasan' => 15,
                    'total_stamina' => 14,
                    'total_kemantapan' => 13,
                    'total_musik' => 12,
                ],
            ], 200),
        ]);

        $operator = Role::create(['name' => 'Operator']);
        $user = User::factory()->create(['role_id' => $operator->id]);
        SeniPool::create([
            'no_pool_babak_id' => 56,
            'round_match' => 'Final',
            'group' => 'Putra',
            'category' => 'Ganda',
            'no_pool' => 'B',
        ]);
        $match = SeniSingleMatch::create([
            'no_pool_babak_id' => 56,
            'bkp_id' => 3412,
            'matches_code' => '137',
            'atletes' => 'Atlet C, Atlet D',
            'contingent' => 'Kontingen C',
            'type' => 'ganda',
            'category' => 'Ganda',
            'group' => 'Putra',
            'status' => 'not_started',
            'round_match' => 'Final',
            'no_order' => 1,
        ]);

        $this
            ->actingAs($user)
            ->postJson("/api/seni/matches/{$match->id}/activate")
            ->assertOk()
            ->assertJsonPath('data.total_kualitas_teknik', '20.000')
            ->assertJsonPath('jury_scores.0.kualitas_teknik', '20.000')
            ->assertJsonPath('jury_punishments.0.waktu', '1.000');

        $this->assertDatabaseHas('seni_jury_scores', [
            'seni_single_match_id' => $match->id,
            'jury_number' => 1,
            'kualitas_teknik' => '20.000',
            'kuantitas_teknik' => '18.000',
            'total_score' => '92.000',
        ]);
        $this->assertDatabaseHas('seni_jury_punishments', [
            'seni_single_match_id' => $match->id,
            'jury_number' => 1,
            'waktu' => '1.000',
            'keluar_garis' => '1.000',
            'senjata_jatuh_atau_tidak_sesuai_deskripsi' => '0.000',
            'senjata_tidak_jatuh_atau_tidak_sesuai_deskripsi' => '1.000',
        ]);
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
        Http::fake([
            '*/partai-seni/partai-status/3410' => Http::response([
                'status' => 'success',
                'data' => ['status' => 'ongoing'],
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
            'is_active' => true,
            'round_match' => 'Final',
            'no_order' => 1,
            'total_score' => 378,
            'total_wiraga' => 56,
            'total_wirama' => 54,
            'total_punishment' => 2,
            'time' => 140,
        ]);
        $match->juryScores()->create([
            'jury_number' => 1,
            'wiraga' => 56,
            'wirasa' => 56,
            'wirama' => 32,
            'total_score' => 144,
            'is_accepted' => true,
        ]);
        $match->juryPunishments()->create([
            'jury_number' => 1,
            'waktu' => 0,
            'keluar_garis' => 1,
            'senjata_jatuh_atau_tidak_sesuai_deskripsi' => 0,
            'akeseoris_jatuh' => 0,
        ]);

        $this
            ->actingAs($user)
            ->postJson("/api/seni/matches/{$match->id}/status", [
                'status' => 'ongoing',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'ongoing')
            ->assertJsonPath('data.total_score', '378.000')
            ->assertJsonPath('data.total_wiraga', '56.000')
            ->assertJsonPath('data.total_wirama', '54.000')
            ->assertJsonPath('data.total_punishment', '2.000')
            ->assertJsonPath('data.time', 140)
            ->assertJsonPath('data.jury_scores.0.wiraga', '56.000')
            ->assertJsonPath('data.jury_scores.0.wirasa', '56.000')
            ->assertJsonPath('data.jury_scores.0.wirama', '32.000')
            ->assertJsonPath('data.jury_scores.0.total_score', '144.000')
            ->assertJsonPath('data.jury_punishments.0.keluar_garis', '1.000');

        $this->assertDatabaseHas('seni_single_matches', [
            'id' => $match->id,
            'status' => 'ongoing',
        ]);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/partai-seni/partai-status/3410')
            && $request['status'] === 'ongoing');
    }

    public function test_active_match_status_can_save_time_without_syncing_source(): void
    {
        Http::fake();

        $operator = Role::create(['name' => 'Operator']);
        $user = User::factory()->create(['role_id' => $operator->id]);
        $match = SeniSingleMatch::create([
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

        $this
            ->actingAs($user)
            ->postJson("/api/seni/matches/{$match->id}/status", [
                'status' => 'paused',
                'time' => 125,
                'sync_source' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'paused')
            ->assertJsonPath('data.time', 125);

        $this->assertDatabaseHas('seni_single_matches', [
            'id' => $match->id,
            'status' => 'paused',
            'time' => 125,
        ]);

        Http::assertNothingSent();
    }

    public function test_pausing_active_match_recalculates_latest_accepted_scores_and_totals(): void
    {
        Http::fake();

        $operator = Role::create(['name' => 'Operator']);
        $user = User::factory()->create(['role_id' => $operator->id]);
        $match = SeniSingleMatch::create([
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

        foreach ([
            ['jury_number' => 1, 'wiraga' => 40, 'wirasa' => 20, 'wirama' => 10, 'total_score' => 70, 'is_accepted' => true],
            ['jury_number' => 2, 'wiraga' => 50, 'wirasa' => 20, 'wirama' => 10, 'total_score' => 80, 'is_accepted' => false],
            ['jury_number' => 3, 'wiraga' => 60, 'wirasa' => 20, 'wirama' => 10, 'total_score' => 90, 'is_accepted' => false],
            ['jury_number' => 4, 'wiraga' => 70, 'wirasa' => 20, 'wirama' => 10, 'total_score' => 100, 'is_accepted' => false],
            ['jury_number' => 5, 'wiraga' => 80, 'wirasa' => 20, 'wirama' => 10, 'total_score' => 110, 'is_accepted' => true],
        ] as $score) {
            $match->juryScores()->create($score);
        }

        foreach ([
            ['jury_number' => 1, 'keluar_garis' => 5],
            ['jury_number' => 2, 'keluar_garis' => 1],
            ['jury_number' => 3, 'waktu' => 2],
            ['jury_number' => 4, 'senjata_jatuh_atau_tidak_sesuai_deskripsi' => 3],
            ['jury_number' => 5, 'keluar_garis' => 9],
        ] as $punishment) {
            $match->juryPunishments()->create($punishment);
        }

        $this
            ->actingAs($user)
            ->postJson("/api/seni/matches/{$match->id}/status", [
                'status' => 'paused',
                'time' => 125,
                'sync_source' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'paused')
            ->assertJsonPath('data.time', 125)
            ->assertJsonPath('data.total_score', '270.000')
            ->assertJsonPath('data.total_wiraga', '180.000')
            ->assertJsonPath('data.total_wirasa', '60.000')
            ->assertJsonPath('data.total_wirama', '30.000')
            ->assertJsonPath('data.total_punishment', '6.000')
            ->assertJsonPath('data.jury_scores.0.is_accepted', false)
            ->assertJsonPath('data.jury_scores.1.is_accepted', true)
            ->assertJsonPath('data.jury_scores.2.is_accepted', true)
            ->assertJsonPath('data.jury_scores.3.is_accepted', true)
            ->assertJsonPath('data.jury_scores.4.is_accepted', false);

        $this->assertDatabaseHas('seni_single_matches', [
            'id' => $match->id,
            'status' => 'paused',
            'time' => 125,
            'total_score' => '270.000',
            'total_wiraga' => '180.000',
            'total_wirasa' => '60.000',
            'total_wirama' => '30.000',
            'total_punishment' => '6.000',
        ]);

        Http::assertNothingSent();
    }

    public function test_save_match_detail_sends_payload_and_marks_match_done(): void
    {
        Http::fake([
            '*/partai-seni/detail-partai-seni-ts/3410' => Http::response([
                'status' => 'success',
            ], 200),
            '*/partai-seni/partai-status/3410' => Http::response([
                'status' => 'success',
            ], 200),
        ]);

        $operator = Role::create(['name' => 'Operator']);
        $user = User::factory()->create(['role_id' => $operator->id]);
        $match = SeniSingleMatch::create([
            'no_pool_babak_id' => 55,
            'bkp_id' => 3410,
            'matches_code' => '135',
            'atletes' => 'Atlet A',
            'contingent' => 'Kontingen A',
            'type' => 'tunggal',
            'category' => 'Tunggal',
            'group' => 'Putra',
            'status' => 'paused',
            'is_disqualified' => true,
            'is_active' => true,
            'round_match' => 'Final',
            'no_order' => 1,
            'total_score' => '378.000',
            'total_wiraga' => '56.000',
            'time' => 140,
        ]);
        $match->juryScores()->create([
            'jury_number' => 1,
            'wiraga' => 56,
            'wirasa' => 55,
            'wirama' => 54,
            'total_score' => 56,
            'is_accepted' => true,
        ]);
        $match->juryPunishments()->create([
            'jury_number' => 1,
            'waktu' => 0,
            'keluar_garis' => 1,
            'senjata_jatuh_atau_tidak_sesuai_deskripsi' => 0,
            'akeseoris_jatuh' => 0,
        ]);

        $this
            ->actingAs($user)
            ->postJson("/api/seni/matches/{$match->id}/save-detail")
            ->assertOk()
            ->assertJsonPath('data.status', 'done');

        $this->assertDatabaseHas('seni_single_matches', [
            'id' => $match->id,
            'status' => 'done',
        ]);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/partai-seni/detail-partai-seni-ts/3410')
            && $request['total_score'] === '378.000'
            && $request['time'] === 140
            && collect($request['tgr_jury_scores'])->contains(fn (array $score): bool => $score['jury_number'] === 1
                && $score['ref_tgr_score'] === 'wiraga'
                && $score['score'] === '56.000')
            && collect($request['tgr_jury_scores'])->contains(fn (array $score): bool => $score['jury_number'] === 1
                && $score['ref_tgr_score'] === 'wirasa'
                && $score['score'] === '55.000')
            && collect($request['tgr_jury_scores'])->contains(fn (array $score): bool => $score['jury_number'] === 1
                && $score['ref_tgr_score'] === 'wirama'
                && $score['score'] === '54.000')
            && $request['tgr_jury_punishments'][0]['keluar_garis'] === '1.000');
        Http::assertSent(fn ($request) => str_contains($request->url(), '/partai-seni/partai-status/3410')
            && $request['status'] === 'done');
    }

    public function test_save_ganda_match_detail_sends_corrected_punishment_payload(): void
    {
        Http::fake([
            '*/partai-seni/detail-partai-seni-ts/3412' => Http::response([
                'status' => 'success',
            ], 200),
            '*/partai-seni/partai-status/3412' => Http::response([
                'status' => 'success',
            ], 200),
        ]);

        $operator = Role::create(['name' => 'Operator']);
        $user = User::factory()->create(['role_id' => $operator->id]);
        $match = SeniSingleMatch::create([
            'no_pool_babak_id' => 56,
            'bkp_id' => 3412,
            'matches_code' => '137',
            'atletes' => 'Atlet C, Atlet D',
            'contingent' => 'Kontingen C',
            'type' => 'ganda',
            'category' => 'Ganda',
            'group' => 'Putra',
            'status' => 'paused',
            'is_active' => true,
            'round_match' => 'Final',
            'no_order' => 1,
            'total_score' => '95.000',
            'total_kualitas_teknik' => '20.000',
            'time' => 145,
        ]);
        $match->juryScores()->create([
            'jury_number' => 1,
            'kualitas_teknik' => 20,
            'kuantitas_teknik' => 18,
            'ketangkasan' => 15,
            'stamina' => 14,
            'kemantapan' => 13,
            'musik' => 12,
            'total_score' => 92,
            'is_accepted' => false,
        ]);
        $match->juryPunishments()->create([
            'jury_number' => 1,
            'waktu' => 1,
            'keluar_garis' => 1,
            'senjata_jatuh_atau_tidak_sesuai_deskripsi' => 0,
            'senjata_tidak_jatuh_atau_tidak_sesuai_deskripsi' => 1,
        ]);

        $this
            ->actingAs($user)
            ->postJson("/api/seni/matches/{$match->id}/save-detail")
            ->assertOk()
            ->assertJsonPath('data.status', 'done');

        Http::assertSent(fn ($request) => str_contains($request->url(), '/partai-seni/detail-partai-seni-ts/3412')
            && collect($request['tgr_jury_scores'])->contains(fn (array $score): bool => $score['jury_number'] === 1
                && $score['ref_tgr_score'] === 'kualitas_teknik'
                && $score['score'] === '20.000')
            && $request['tgr_jury_punishments'][0]['waktu'] === '1.000'
            && $request['tgr_jury_punishments'][0]['keluar garis'] === '1.000'
            && $request['tgr_jury_punishments'][0]['senjata_jatuh_atau_tidak_sesuai_deskripsi'] === '0.000'
            && $request['tgr_jury_punishments'][0]['senjata_tidak_jatuh_atau_tidak_sesuai_deskripsi'] === '1.000'
            && ! array_key_exists('keluar_garis', $request['tgr_jury_punishments'][0]));
    }

    public function test_reset_match_deletes_jury_scores_and_clears_score_values(): void
    {
        Http::fake([
            '*/partai-seni/detail-partai-seni-ts/3410' => Http::response([
                'status' => 'success',
            ], 200),
            '*/partai-seni/partai-status/3410' => Http::response([
                'status' => 'success',
            ], 200),
        ]);

        $operator = Role::create(['name' => 'Operator']);
        $user = User::factory()->create(['role_id' => $operator->id]);
        $match = SeniSingleMatch::create([
            'no_pool_babak_id' => 55,
            'bkp_id' => 3410,
            'matches_code' => '135',
            'atletes' => 'Atlet A',
            'contingent' => 'Kontingen A',
            'type' => 'tunggal',
            'category' => 'Tunggal',
            'group' => 'Putra',
            'status' => 'paused',
            'is_disqualified' => true,
            'is_passed' => true,
            'is_active' => true,
            'round_match' => 'Final',
            'no_order' => 1,
            'total_score' => '378.000',
            'total_wiraga' => '56.000',
            'total_wirasa' => '55.000',
            'total_wirama' => '54.000',
            'total_kualitas_teknik' => '53.000',
            'total_kuantitas_teknik' => '52.000',
            'total_ketangkasan' => '51.000',
            'total_stamina' => '50.000',
            'total_kemantapan' => '49.000',
            'total_musik' => '48.000',
            'total_punishment' => '1.000',
            'time' => 140,
            'rank' => 1,
        ]);
        $match->juryScores()->create([
            'jury_number' => 1,
            'wiraga' => 56,
            'total_score' => 56,
            'is_accepted' => true,
        ]);
        $match->juryPunishments()->create([
            'jury_number' => 1,
            'waktu' => 1,
            'keluar_garis' => 1,
        ]);

        $this
            ->actingAs($user)
            ->postJson("/api/seni/matches/{$match->id}/reset")
            ->assertOk()
            ->assertJsonPath('data.status', 'not_started')
            ->assertJsonPath('data.is_disqualified', false)
            ->assertJsonPath('data.total_score', null)
            ->assertJsonPath('data.time', null);

        $this->assertDatabaseCount('seni_jury_scores', 0);
        $this->assertDatabaseCount('seni_jury_punishments', 0);
        $this->assertDatabaseHas('seni_single_matches', [
            'id' => $match->id,
            'status' => 'not_started',
            'is_disqualified' => false,
            'is_passed' => false,
            'total_score' => null,
            'total_wiraga' => null,
            'total_wirasa' => null,
            'total_wirama' => null,
            'total_kualitas_teknik' => null,
            'total_kuantitas_teknik' => null,
            'total_ketangkasan' => null,
            'total_stamina' => null,
            'total_kemantapan' => null,
            'total_musik' => null,
            'total_punishment' => null,
            'time' => null,
            'rank' => null,
        ]);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/partai-seni/detail-partai-seni-ts/3410')
            && $request['reset_scores'] === true
            && $request['delete_scores'] === true
            && $request['clear_scores'] === true
            && $request['total_score'] === '0.000'
            && $request['total_nilai'] === '0.000'
            && $request['total_punishment'] === '0.000'
            && $request['total_hukuman'] === '0.000'
            && $request['time'] === 0
            && $request['waktu_tampil'] === 0
            && $request['rank'] === 0
            && $request['ranking'] === 0
            && $request['is_passed'] === 0
            && $request['is_disqualified'] === 0
            && $request['is_disqualification'] === 0
            && $request['deviasi'] === '0.000'
            && $request['total_wiraga'] === '0.000'
            && $request['total_wirasa'] === '0.000'
            && $request['total_wirama'] === '0.000'
            && $request['total_kualitas_teknik'] === '0.000'
            && $request['total_kuantitas_teknik'] === '0.000'
            && $request['total_ketangkasan'] === '0.000'
            && $request['total_stamina'] === '0.000'
            && $request['total_kemantapan'] === '0.000'
            && $request['total_musik'] === '0.000'
            && $request['tgr_jury_scores'] === []
            && $request['tgr_jury_punishments'] === []
            && $request['tgr_jury_total_scores'] === []);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/partai-seni/partai-status/3410')
            && $request['status'] === 'not_started');
    }

    public function test_disqualify_match_marks_active_match_done_and_keeps_scores(): void
    {
        Event::fake([SeniMatchUpdated::class]);
        Http::fake([
            '*/partai-seni/detail-partai-seni-ts/3410' => Http::response([
                'status' => 'success',
            ], 200),
            '*/partai-seni/partai-status/3410' => Http::response([
                'status' => 'success',
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
        $match = SeniSingleMatch::create($this->seniMatchPayload([
            'status' => 'paused',
            'is_active' => true,
            'total_score' => '378.000',
            'total_wiraga' => '56.000',
            'total_wirasa' => '55.000',
            'total_wirama' => '54.000',
            'total_punishment' => '1.000',
            'time' => 140,
            'rank' => 1,
        ]));
        $match->juryScores()->create([
            'jury_number' => 1,
            'wiraga' => 56,
            'total_score' => 56,
            'is_accepted' => true,
        ]);
        $match->juryPunishments()->create([
            'jury_number' => 1,
            'waktu' => 1,
            'keluar_garis' => 1,
        ]);

        $this
            ->actingAs($user)
            ->postJson("/api/seni/matches/{$match->id}/disqualify")
            ->assertOk()
            ->assertJsonPath('data.status', 'done')
            ->assertJsonPath('data.is_disqualified', true)
            ->assertJsonPath('data.total_score', '378.000')
            ->assertJsonPath('data.time', 140);

        $this->assertDatabaseCount('seni_jury_scores', 1);
        $this->assertDatabaseCount('seni_jury_punishments', 1);
        $this->assertDatabaseHas('seni_single_matches', [
            'id' => $match->id,
            'status' => 'done',
            'is_disqualified' => true,
            'is_passed' => false,
            'total_score' => '378.000',
            'total_wiraga' => '56.000',
            'total_wirasa' => '55.000',
            'total_wirama' => '54.000',
            'total_punishment' => '1.000',
            'time' => 140,
            'rank' => 1,
        ]);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/partai-seni/detail-partai-seni-ts/3410')
            && ! isset($request['reset_scores'])
            && $request['total_score'] === '378.000'
            && $request['total_punishment'] === '1.000'
            && $request['is_disqualified'] === 1
            && $request['is_disqualification'] === 1
            && $request['is_passed'] === 0
            && $request['time'] === 140
            && $request['total_wiraga'] === '56.000'
            && $request['total_wirasa'] === '55.000'
            && $request['total_wirama'] === '54.000'
            && $request['tgr_jury_scores'] !== []
            && $request['tgr_jury_punishments'] !== []
            && $request['tgr_jury_total_scores'] !== []);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/partai-seni/partai-status/3410')
            && $request['status'] === 'done');
        Event::assertDispatched(SeniMatchUpdated::class);
    }

    public function test_cancel_disqualification_keeps_scores_and_saves_to_source(): void
    {
        Event::fake([SeniMatchUpdated::class]);
        Http::fake([
            '*/partai-seni/detail-partai-seni-ts/3410' => Http::response([
                'status' => 'success',
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
        $match = SeniSingleMatch::create($this->seniMatchPayload([
            'status' => 'done',
            'is_active' => true,
            'is_disqualified' => true,
            'total_score' => '378.000',
            'total_wiraga' => '56.000',
            'total_wirasa' => '55.000',
            'total_wirama' => '54.000',
            'total_punishment' => '1.000',
            'time' => 140,
            'rank' => 1,
        ]));
        $match->juryScores()->create([
            'jury_number' => 1,
            'wiraga' => 56,
            'total_score' => 56,
            'is_accepted' => true,
        ]);

        $this
            ->actingAs($user)
            ->postJson("/api/seni/matches/{$match->id}/cancel-disqualification")
            ->assertOk()
            ->assertJsonPath('data.status', 'done')
            ->assertJsonPath('data.is_disqualified', false)
            ->assertJsonPath('data.total_score', '378.000');

        $this->assertDatabaseCount('seni_jury_scores', 1);
        $this->assertDatabaseHas('seni_single_matches', [
            'id' => $match->id,
            'status' => 'done',
            'is_disqualified' => false,
            'total_score' => '378.000',
            'time' => 140,
        ]);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/partai-seni/detail-partai-seni-ts/3410')
            && $request['total_score'] === '378.000'
            && $request['is_disqualified'] === 0
            && $request['is_disqualification'] === 0
            && $request['time'] === 140
            && $request['tgr_jury_scores'] !== []);
        Event::assertDispatched(SeniMatchUpdated::class);
    }

    public function test_decide_winners_ranks_tgr_matches_with_tie_breakers(): void
    {
        Event::fake([SeniMatchUpdated::class]);
        Http::fake([
            '*/partai-seni/pool-result/55' => Http::response([
                'status' => 'success',
                'message' => 'Pool results processed',
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

        $matchA = SeniSingleMatch::create($this->seniMatchPayload([
            'atletes' => 'Bima',
            'total_score' => 100,
            'total_wiraga' => 60,
            'total_wirasa' => 20,
            'total_punishment' => 0,
            'no_order' => 1,
        ]));
        $matchB = SeniSingleMatch::create($this->seniMatchPayload([
            'atletes' => 'Ardi',
            'total_score' => 100,
            'total_wiraga' => 60,
            'total_wirasa' => 30,
            'total_punishment' => 2,
            'no_order' => 2,
            'bkp_id' => 3411,
            'matches_code' => '136',
        ]));
        $matchC = SeniSingleMatch::create($this->seniMatchPayload([
            'atletes' => 'Candra',
            'total_score' => 110,
            'total_wiraga' => 40,
            'total_wirasa' => 10,
            'total_punishment' => 5,
            'no_order' => 3,
            'bkp_id' => 3412,
            'matches_code' => '137',
        ]));
        $matchD = SeniSingleMatch::create($this->seniMatchPayload([
            'atletes' => 'Dani',
            'total_score' => 90,
            'total_wiraga' => 70,
            'total_wirasa' => 40,
            'total_punishment' => 0,
            'no_order' => 4,
            'bkp_id' => 3413,
            'matches_code' => '138',
        ]));

        $this
            ->actingAs($user)
            ->postJson('/api/seni/matches/decide-winners', [
                'passed_count' => 2,
            ])
            ->assertOk()
            ->assertJsonPath('matches.0.id', $matchC->id)
            ->assertJsonPath('matches.1.id', $matchB->id)
            ->assertJsonPath('matches.2.id', $matchA->id)
            ->assertJsonPath('matches.3.id', $matchD->id);

        $this->assertDatabaseHas('seni_single_matches', [
            'id' => $matchC->id,
            'rank' => 1,
            'is_passed' => true,
        ]);
        $this->assertDatabaseHas('seni_single_matches', [
            'id' => $matchB->id,
            'rank' => 2,
            'is_passed' => true,
        ]);
        $this->assertDatabaseHas('seni_single_matches', [
            'id' => $matchA->id,
            'rank' => 3,
            'is_passed' => false,
        ]);

        Http::assertSent(function ($request): bool {
            $payload = $request->data();

            return str_contains($request->url(), '/partai-seni/pool-result/55')
                && $payload[0] === ['bkp_id' => 3412, 'is_passed' => true, 'rank' => 1]
                && $payload[1] === ['bkp_id' => 3411, 'is_passed' => true, 'rank' => 2]
                && $payload[2] === ['bkp_id' => 3410, 'is_passed' => false, 'rank' => 3]
                && ! array_key_exists('total_kebenaran_gerak', $payload[0]);
        });
        Event::assertDispatched(SeniMatchUpdated::class);
    }

    public function test_decide_winners_places_disqualified_matches_last(): void
    {
        Event::fake([SeniMatchUpdated::class]);
        Http::fake([
            '*/partai-seni/pool-result/55' => Http::response([
                'status' => 'success',
                'message' => 'Pool results processed',
            ], 200),
        ]);

        $operator = Role::create(['name' => 'Operator']);
        $user = User::factory()->create(['role_id' => $operator->id]);

        $matchA = SeniSingleMatch::create($this->seniMatchPayload([
            'atletes' => 'Atlet A',
            'total_score' => 100,
            'no_order' => 1,
        ]));
        $matchB = SeniSingleMatch::create($this->seniMatchPayload([
            'atletes' => 'Atlet B',
            'total_score' => 90,
            'no_order' => 2,
            'bkp_id' => 3411,
            'matches_code' => '136',
        ]));
        $matchC = SeniSingleMatch::create($this->seniMatchPayload([
            'atletes' => 'Atlet C',
            'total_score' => 999,
            'is_disqualified' => true,
            'no_order' => 3,
            'bkp_id' => 3412,
            'matches_code' => '137',
        ]));

        $this
            ->actingAs($user)
            ->postJson('/api/seni/matches/decide-winners', [
                'passed_count' => 2,
            ])
            ->assertOk()
            ->assertJsonPath('matches.0.id', $matchA->id)
            ->assertJsonPath('matches.1.id', $matchB->id)
            ->assertJsonPath('matches.2.id', $matchC->id);

        $this->assertDatabaseHas('seni_single_matches', [
            'id' => $matchC->id,
            'rank' => 3,
            'is_passed' => false,
        ]);

        Http::assertSent(function ($request): bool {
            $payload = $request->data();

            return str_contains($request->url(), '/partai-seni/pool-result/55')
                && $payload[0] === ['bkp_id' => 3410, 'is_passed' => true, 'rank' => 1]
                && $payload[1] === ['bkp_id' => 3411, 'is_passed' => true, 'rank' => 2]
                && $payload[2] === ['bkp_id' => 3412, 'is_passed' => false, 'rank' => 3];
        });
        Event::assertDispatched(SeniMatchUpdated::class);
    }

    public function test_reorder_ranks_updates_rank_and_passed_from_manual_order(): void
    {
        Event::fake([SeniMatchUpdated::class]);
        Http::fake([
            '*/partai-seni/pool-result/55' => Http::response([
                'status' => 'success',
                'message' => 'Pool results processed',
            ], 200),
        ]);

        $operator = Role::create(['name' => 'Operator']);
        $user = User::factory()->create(['role_id' => $operator->id]);

        $matchA = SeniSingleMatch::create($this->seniMatchPayload([
            'atletes' => 'Atlet A',
            'rank' => 1,
            'is_passed' => true,
            'no_order' => 1,
        ]));
        $matchB = SeniSingleMatch::create($this->seniMatchPayload([
            'atletes' => 'Atlet B',
            'rank' => 2,
            'is_passed' => true,
            'no_order' => 2,
            'bkp_id' => 3411,
            'matches_code' => '136',
        ]));
        $matchC = SeniSingleMatch::create($this->seniMatchPayload([
            'atletes' => 'Atlet C',
            'rank' => 3,
            'is_passed' => false,
            'no_order' => 3,
            'bkp_id' => 3412,
            'matches_code' => '137',
        ]));

        $this
            ->actingAs($user)
            ->postJson('/api/seni/matches/reorder-ranks', [
                'ordered_match_ids' => [$matchC->id, $matchA->id, $matchB->id],
                'passed_count' => 1,
            ])
            ->assertOk()
            ->assertJsonPath('matches.0.id', $matchC->id)
            ->assertJsonPath('matches.1.id', $matchA->id)
            ->assertJsonPath('matches.2.id', $matchB->id);

        $this->assertDatabaseHas('seni_single_matches', [
            'id' => $matchC->id,
            'rank' => 1,
            'is_passed' => true,
        ]);
        $this->assertDatabaseHas('seni_single_matches', [
            'id' => $matchA->id,
            'rank' => 2,
            'is_passed' => false,
        ]);
        $this->assertDatabaseHas('seni_single_matches', [
            'id' => $matchB->id,
            'rank' => 3,
            'is_passed' => false,
        ]);

        Http::assertSent(function ($request): bool {
            $payload = $request->data();

            return str_contains($request->url(), '/partai-seni/pool-result/55')
                && $payload[0] === ['bkp_id' => 3412, 'is_passed' => true, 'rank' => 1]
                && $payload[1] === ['bkp_id' => 3410, 'is_passed' => false, 'rank' => 2]
                && $payload[2] === ['bkp_id' => 3411, 'is_passed' => false, 'rank' => 3]
                && ! array_key_exists('total_kebenaran_gerak', $payload[0]);
        });
        Event::assertDispatched(SeniMatchUpdated::class);
    }

    public function test_reorder_ranks_keeps_disqualified_matches_last(): void
    {
        Event::fake([SeniMatchUpdated::class]);
        Http::fake([
            '*/partai-seni/pool-result/55' => Http::response([
                'status' => 'success',
                'message' => 'Pool results processed',
            ], 200),
        ]);

        $operator = Role::create(['name' => 'Operator']);
        $user = User::factory()->create(['role_id' => $operator->id]);

        $matchA = SeniSingleMatch::create($this->seniMatchPayload([
            'atletes' => 'Atlet A',
            'rank' => 1,
            'is_passed' => true,
            'no_order' => 1,
        ]));
        $matchB = SeniSingleMatch::create($this->seniMatchPayload([
            'atletes' => 'Atlet B',
            'rank' => 2,
            'is_passed' => true,
            'no_order' => 2,
            'bkp_id' => 3411,
            'matches_code' => '136',
        ]));
        $matchC = SeniSingleMatch::create($this->seniMatchPayload([
            'atletes' => 'Atlet C',
            'rank' => 3,
            'is_disqualified' => true,
            'is_passed' => false,
            'no_order' => 3,
            'bkp_id' => 3412,
            'matches_code' => '137',
        ]));

        $this
            ->actingAs($user)
            ->postJson('/api/seni/matches/reorder-ranks', [
                'ordered_match_ids' => [$matchC->id, $matchA->id, $matchB->id],
                'passed_count' => 2,
            ])
            ->assertOk()
            ->assertJsonPath('matches.0.id', $matchA->id)
            ->assertJsonPath('matches.1.id', $matchB->id)
            ->assertJsonPath('matches.2.id', $matchC->id);

        $this->assertDatabaseHas('seni_single_matches', [
            'id' => $matchC->id,
            'rank' => 3,
            'is_passed' => false,
        ]);

        Http::assertSent(function ($request): bool {
            $payload = $request->data();

            return str_contains($request->url(), '/partai-seni/pool-result/55')
                && $payload[0] === ['bkp_id' => 3410, 'is_passed' => true, 'rank' => 1]
                && $payload[1] === ['bkp_id' => 3411, 'is_passed' => true, 'rank' => 2]
                && $payload[2] === ['bkp_id' => 3412, 'is_passed' => false, 'rank' => 3];
        });
        Event::assertDispatched(SeniMatchUpdated::class);
    }

    public function test_jury_can_save_seni_score_and_punishment_for_ongoing_match(): void
    {
        Event::fake([SeniJuryScoreUpdated::class]);

        $jury = Role::create(['name' => 'Juri']);
        $user = User::factory()->create([
            'name' => 'Juri 2',
            'role_id' => $jury->id,
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
            'status' => 'ongoing',
            'is_active' => true,
            'round_match' => 'Final',
            'no_order' => 1,
        ]);

        $this
            ->actingAs($user)
            ->postJson("/api/seni/matches/{$match->id}/jury-score", [
                'jury_number' => 2,
                'type' => 'score',
                'field' => 'wiraga',
                'value' => 56,
            ])
            ->assertOk()
            ->assertJsonPath('score.jury_number', 2)
            ->assertJsonPath('score.wiraga', '56.000')
            ->assertJsonPath('score.wirasa', '0.000')
            ->assertJsonPath('score.wirama', '0.000')
            ->assertJsonPath('score.total_score', '56.000');

        $this
            ->actingAs($user)
            ->postJson("/api/seni/matches/{$match->id}/jury-score", [
                'jury_number' => 2,
                'type' => 'punishment',
                'field' => 'keluar_garis',
                'value' => 5,
            ])
            ->assertOk()
            ->assertJsonPath('score.total_score', '51.000')
            ->assertJsonPath('punishment.keluar_garis', '5.000');

        $this->assertDatabaseHas('seni_jury_scores', [
            'seni_single_match_id' => $match->id,
            'jury_number' => 2,
            'wiraga' => '56.000',
            'wirasa' => '0.000',
            'wirama' => '0.000',
            'total_score' => '51.000',
        ]);
        $this->assertDatabaseHas('seni_jury_punishments', [
            'seni_single_match_id' => $match->id,
            'jury_number' => 2,
            'keluar_garis' => '5.000',
        ]);

        Event::assertDispatched(
            SeniJuryScoreUpdated::class,
            fn (SeniJuryScoreUpdated $event): bool => $event->match['id'] === $match->id
                && $event->juryNumber === 2
                && $event->field === 'keluar_garis'
                && $event->type === 'punishment'
                && $event->score['total_score'] === '51.000',
        );
    }

    public function test_jury_five_can_save_seni_score(): void
    {
        Event::fake([SeniJuryScoreUpdated::class]);

        $jury = Role::create(['name' => 'Juri']);
        $user = User::factory()->create([
            'name' => 'Juri 5',
            'role_id' => $jury->id,
        ]);
        $match = SeniSingleMatch::create([
            'no_pool_babak_id' => 55,
            'bkp_id' => 3415,
            'matches_code' => '140',
            'atletes' => 'Atlet E',
            'contingent' => 'Kontingen E',
            'type' => 'tunggal',
            'category' => 'Tunggal',
            'group' => 'Putra',
            'status' => 'ongoing',
            'is_active' => true,
            'round_match' => 'Final',
            'no_order' => 5,
        ]);

        $this
            ->actingAs($user)
            ->postJson("/api/seni/matches/{$match->id}/jury-score", [
                'jury_number' => 5,
                'type' => 'score',
                'field' => 'wiraga',
                'value' => 58,
            ])
            ->assertOk()
            ->assertJsonPath('score.jury_number', 5)
            ->assertJsonPath('score.wiraga', '58.000')
            ->assertJsonPath('score.total_score', '58.000');

        $this->assertDatabaseHas('seni_jury_scores', [
            'seni_single_match_id' => $match->id,
            'jury_number' => 5,
            'wiraga' => '58.000',
            'wirasa' => '0.000',
            'wirama' => '0.000',
            'total_score' => '58.000',
        ]);

        Event::assertDispatched(
            SeniJuryScoreUpdated::class,
            fn (SeniJuryScoreUpdated $event): bool => $event->match['id'] === $match->id
                && $event->juryNumber === 5
                && $event->field === 'wiraga'
                && $event->type === 'score'
                && $event->score['total_score'] === '58.000',
        );
    }

    public function test_jury_update_accepts_three_median_scores_and_updates_tunggal_match_totals(): void
    {
        Event::fake([SeniJuryScoreUpdated::class]);

        $jury = Role::create(['name' => 'Juri']);
        $user = User::factory()->create([
            'name' => 'Juri 5',
            'role_id' => $jury->id,
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
            'status' => 'ongoing',
            'is_active' => true,
            'round_match' => 'Final',
            'no_order' => 1,
        ]);

        foreach ([
            ['jury_number' => 1, 'wiraga' => 40, 'wirasa' => 20, 'wirama' => 10, 'total_score' => 70],
            ['jury_number' => 2, 'wiraga' => 50, 'wirasa' => 20, 'wirama' => 10, 'total_score' => 80],
            ['jury_number' => 3, 'wiraga' => 60, 'wirasa' => 20, 'wirama' => 10, 'total_score' => 90],
            ['jury_number' => 4, 'wiraga' => 70, 'wirasa' => 20, 'wirama' => 10, 'total_score' => 100],
        ] as $score) {
            $match->juryScores()->create($score);
        }

        foreach ([
            ['jury_number' => 1, 'keluar_garis' => 5],
            ['jury_number' => 2, 'keluar_garis' => 1],
            ['jury_number' => 3, 'waktu' => 2],
            ['jury_number' => 4, 'senjata_jatuh_atau_tidak_sesuai_deskripsi' => 3],
        ] as $punishment) {
            $match->juryPunishments()->create($punishment);
        }

        $this
            ->actingAs($user)
            ->postJson("/api/seni/matches/{$match->id}/jury-score", [
                'jury_number' => 5,
                'type' => 'score',
                'field' => 'wiraga',
                'value' => 80,
            ])
            ->assertOk()
            ->assertJsonPath('data.total_score', '250.000')
            ->assertJsonPath('data.total_wiraga', '190.000')
            ->assertJsonPath('data.total_wirasa', '40.000')
            ->assertJsonPath('data.total_wirama', '20.000')
            ->assertJsonPath('data.total_punishment', '3.000')
            ->assertJsonPath('data.jury_scores.0.is_accepted', false)
            ->assertJsonPath('data.jury_scores.1.is_accepted', true)
            ->assertJsonPath('data.jury_scores.2.is_accepted', true)
            ->assertJsonPath('data.jury_scores.3.is_accepted', false)
            ->assertJsonPath('data.jury_scores.4.is_accepted', true);

        $this->assertDatabaseHas('seni_single_matches', [
            'id' => $match->id,
            'total_score' => '250.000',
            'total_wiraga' => '190.000',
            'total_wirasa' => '40.000',
            'total_wirama' => '20.000',
            'total_punishment' => '3.000',
        ]);
        $this->assertDatabaseHas('seni_jury_scores', [
            'seni_single_match_id' => $match->id,
            'jury_number' => 1,
            'is_accepted' => false,
        ]);
        $this->assertDatabaseHas('seni_jury_scores', [
            'seni_single_match_id' => $match->id,
            'jury_number' => 2,
            'is_accepted' => true,
        ]);
        $this->assertDatabaseHas('seni_jury_scores', [
            'seni_single_match_id' => $match->id,
            'jury_number' => 5,
            'is_accepted' => true,
        ]);

        Event::assertDispatched(
            SeniJuryScoreUpdated::class,
            fn (SeniJuryScoreUpdated $event): bool => $event->match['total_score'] === '250.000'
                && $event->match['total_punishment'] === '3.000'
                && $event->match['jury_scores'][1]['is_accepted'] === true
                && $event->match['jury_scores'][4]['is_accepted'] === true,
        );
    }

    public function test_jury_update_accepts_three_median_scores_and_updates_ganda_match_totals(): void
    {
        Event::fake([SeniJuryScoreUpdated::class]);

        $jury = Role::create(['name' => 'Juri']);
        $user = User::factory()->create([
            'name' => 'Juri 5',
            'role_id' => $jury->id,
        ]);
        $match = SeniSingleMatch::create([
            'no_pool_babak_id' => 56,
            'bkp_id' => 3412,
            'matches_code' => '137',
            'atletes' => 'Atlet C, Atlet D',
            'contingent' => 'Kontingen C',
            'type' => 'ganda',
            'category' => 'Ganda',
            'group' => 'Putra',
            'status' => 'ongoing',
            'is_active' => true,
            'round_match' => 'Final',
            'no_order' => 1,
        ]);

        foreach ([
            ['jury_number' => 1, 'kualitas_teknik' => 10, 'kuantitas_teknik' => 20, 'ketangkasan' => 20, 'stamina' => 10, 'kemantapan' => 10, 'musik' => 10, 'total_score' => 80],
            ['jury_number' => 2, 'kualitas_teknik' => 20, 'kuantitas_teknik' => 20, 'ketangkasan' => 20, 'stamina' => 10, 'kemantapan' => 10, 'musik' => 10, 'total_score' => 90],
            ['jury_number' => 3, 'kualitas_teknik' => 30, 'kuantitas_teknik' => 20, 'ketangkasan' => 20, 'stamina' => 10, 'kemantapan' => 10, 'musik' => 10, 'total_score' => 100],
            ['jury_number' => 4, 'kualitas_teknik' => 40, 'kuantitas_teknik' => 20, 'ketangkasan' => 20, 'stamina' => 10, 'kemantapan' => 10, 'musik' => 10, 'total_score' => 110],
        ] as $score) {
            $match->juryScores()->create($score);
        }

        foreach ([
            ['jury_number' => 1, 'keluar_garis' => 5],
            ['jury_number' => 2, 'keluar_garis' => 1],
            ['jury_number' => 3, 'waktu' => 2],
            ['jury_number' => 4, 'senjata_tidak_jatuh_atau_tidak_sesuai_deskripsi' => 3],
        ] as $punishment) {
            $match->juryPunishments()->create($punishment);
        }

        $this
            ->actingAs($user)
            ->postJson("/api/seni/matches/{$match->id}/jury-score", [
                'jury_number' => 5,
                'type' => 'score',
                'field' => 'kualitas_teknik',
                'value' => 50,
            ])
            ->assertOk()
            ->assertJsonPath('data.total_score', '270.000')
            ->assertJsonPath('data.total_kualitas_teknik', '60.000')
            ->assertJsonPath('data.total_kuantitas_teknik', '60.000')
            ->assertJsonPath('data.total_ketangkasan', '60.000')
            ->assertJsonPath('data.total_stamina', '30.000')
            ->assertJsonPath('data.total_kemantapan', '30.000')
            ->assertJsonPath('data.total_musik', '30.000')
            ->assertJsonPath('data.total_punishment', '8.000')
            ->assertJsonPath('data.jury_scores.0.is_accepted', true)
            ->assertJsonPath('data.jury_scores.1.is_accepted', true)
            ->assertJsonPath('data.jury_scores.2.is_accepted', true)
            ->assertJsonPath('data.jury_scores.3.is_accepted', false)
            ->assertJsonPath('data.jury_scores.4.is_accepted', false);

        $this->assertDatabaseHas('seni_single_matches', [
            'id' => $match->id,
            'total_score' => '270.000',
            'total_kualitas_teknik' => '60.000',
            'total_kuantitas_teknik' => '60.000',
            'total_ketangkasan' => '60.000',
            'total_stamina' => '30.000',
            'total_kemantapan' => '30.000',
            'total_musik' => '30.000',
            'total_punishment' => '8.000',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function seniMatchPayload(array $overrides = []): array
    {
        return array_merge([
            'no_pool_babak_id' => 55,
            'bkp_id' => 3410,
            'matches_code' => '135',
            'atletes' => 'Atlet A',
            'contingent' => 'Kontingen A',
            'type' => 'tunggal',
            'category' => 'Tunggal',
            'group' => 'Putra',
            'status' => 'done',
            'is_active' => false,
            'round_match' => 'Final',
            'no_order' => 1,
        ], $overrides);
    }
}
