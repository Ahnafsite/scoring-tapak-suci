<?php

namespace Tests\Feature;

use App\Models\Arena;
use App\Models\FightMatch;
use App\Models\FightSchedule;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FightArenaSetupTest extends TestCase
{
    use RefreshDatabase;

    public function test_gelanggang_source_uses_configured_scoring_server(): void
    {
        config([
            'services.scoring.url' => 'http://configured-scoring.test/api',
            'services.scoring.key' => 'configured-scoring-key',
        ]);

        Http::fake([
            'http://configured-scoring.test/api/gelanggang' => Http::response([
                'data' => [
                    ['id' => 1, 'name' => 'Gelanggang A'],
                ],
            ]),
            '*' => Http::response([], 404),
        ]);

        $user = User::factory()->create();

        $this
            ->actingAs($user)
            ->getJson('/api/source/gelanggang')
            ->assertOk()
            ->assertJsonPath('data.0.id', 1);

        Http::assertSent(fn ($request): bool => $request->url() === 'http://configured-scoring.test/api/gelanggang'
            && $request->hasHeader('X-API-KEY', 'configured-scoring-key'));
    }

    public function test_arena_setup_refreshes_inserted_matches_without_replacing_existing_schedule_ids(): void
    {
        Http::fake([
            '*/partai/tanding/5' => Http::response([
                'success' => true,
                'data' => [
                    [
                        'id' => 1812,
                        'match_code' => '1',
                        'match_number' => 1,
                        'atlete_blue' => 'Biru Lama Update',
                        'atlete_red' => 'Kuning Lama Update',
                        'contingent_blue' => 'Kontingen Biru',
                        'contingent_red' => 'Kontingen Kuning',
                        'match_round' => 'penyisihan',
                        'category' => 'kelas D putra',
                        'group' => 'remaja',
                        'status' => 'not_started',
                        'winner_corner' => null,
                        'winner_status' => null,
                    ],
                    [
                        'id' => 1980,
                        'match_code' => '4A',
                        'match_number' => 7,
                        'atlete_blue' => 'Biru Sisipan',
                        'atlete_red' => 'Kuning Sisipan',
                        'contingent_blue' => 'Kontingen Biru Sisipan',
                        'contingent_red' => 'Kontingen Kuning Sisipan',
                        'match_round' => 'final',
                        'category' => 'kelas A putra',
                        'group' => 'usia dini',
                        'status' => 'not_started',
                        'winner_corner' => null,
                        'winner_status' => null,
                    ],
                ],
            ], 200),
            '*' => Http::response([], 404),
        ]);

        $operator = Role::create(['name' => 'Operator']);
        $user = User::factory()->create(['role_id' => $operator->id]);

        Arena::create([
            'id' => 1,
            'gelanggang_id' => 3,
            'sesi_tanding_id' => 4,
            'arena_name' => 'HARIMAU',
        ]);

        $existingSchedule = FightSchedule::create([
            'partai_id' => '1812',
            'match_code' => '1',
            'match_number' => 1,
            'athlete_yellow' => 'Kuning Lama',
            'athlete_blue' => 'Biru Lama',
            'status' => 'paused',
        ]);
        $obsoleteSchedule = FightSchedule::create([
            'partai_id' => '9999',
            'match_code' => 'OLD',
            'match_number' => 99,
            'status' => 'not_started',
        ]);
        $activeMatch = FightMatch::create([
            'partai_id' => '1812',
            'match_code' => '1',
            'fight_schedule_id' => $existingSchedule->id,
            'status' => 'paused',
            'round_number' => 1,
        ]);
        $obsoleteMatch = FightMatch::create([
            'partai_id' => '9999',
            'match_code' => 'OLD',
            'fight_schedule_id' => $obsoleteSchedule->id,
            'status' => 'not_started',
            'round_number' => 1,
        ]);

        $this
            ->actingAs($user)
            ->postJson('/api/arena/setup', [
                'gelanggang_id' => 3,
                'sesi_tanding_id' => 5,
                'arena_name' => 'HARIMAU',
            ])
            ->assertOk()
            ->assertJsonPath('matches_count', 2);

        $this->assertDatabaseHas('arenas', [
            'id' => 1,
            'gelanggang_id' => 3,
            'sesi_tanding_id' => 5,
            'arena_name' => 'HARIMAU',
        ]);

        $this->assertSame(
            $existingSchedule->id,
            FightSchedule::where('partai_id', '1812')->value('id'),
        );
        $this->assertDatabaseHas('fight_schedules', [
            'id' => $existingSchedule->id,
            'partai_id' => '1812',
            'athlete_yellow' => 'Kuning Lama Update',
            'athlete_blue' => 'Biru Lama Update',
            'status' => 'paused',
        ]);
        $this->assertDatabaseHas('fight_schedules', [
            'partai_id' => '1980',
            'match_code' => '4A',
            'athlete_yellow' => 'Kuning Sisipan',
            'athlete_blue' => 'Biru Sisipan',
        ]);
        $this->assertDatabaseMissing('fight_schedules', [
            'id' => $obsoleteSchedule->id,
        ]);

        $this->assertSame(
            $existingSchedule->id,
            $activeMatch->refresh()->fight_schedule_id,
        );
        $this->assertNull($obsoleteMatch->refresh()->fight_schedule_id);
        $this->assertSame(2, FightSchedule::count());
    }

    public function test_arena_setup_does_not_mutate_local_state_when_remote_sync_fails(): void
    {
        Http::fake([
            '*/partai/tanding/5' => Http::response([
                'message' => 'Server scoring sedang error.',
            ], 500),
            '*' => Http::response([], 404),
        ]);

        $operator = Role::create(['name' => 'Operator']);
        $user = User::factory()->create(['role_id' => $operator->id]);

        Arena::create([
            'id' => 1,
            'gelanggang_id' => 3,
            'sesi_tanding_id' => 4,
            'arena_name' => 'HARIMAU',
        ]);
        $schedule = FightSchedule::create([
            'partai_id' => '1812',
            'match_code' => '1',
            'status' => 'not_started',
        ]);

        $this
            ->actingAs($user)
            ->postJson('/api/arena/setup', [
                'gelanggang_id' => 8,
                'sesi_tanding_id' => 5,
                'arena_name' => 'MACAN',
            ])
            ->assertStatus(500)
            ->assertJsonPath('message', 'Failed to sync matches.');

        $this->assertDatabaseHas('arenas', [
            'id' => 1,
            'gelanggang_id' => 3,
            'sesi_tanding_id' => 4,
            'arena_name' => 'HARIMAU',
        ]);
        $this->assertDatabaseHas('fight_schedules', [
            'id' => $schedule->id,
            'partai_id' => '1812',
            'match_code' => '1',
        ]);
    }
}
