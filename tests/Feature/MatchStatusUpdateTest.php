<?php

namespace Tests\Feature;

use App\Models\FightDetailJuryPointBlue;
use App\Models\FightDetailJuryPointYellow;
use App\Models\FightMatch;
use App\Models\FightRecapJuryPoint;
use App\Models\FightSchedule;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MatchStatusUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_starting_match_clears_existing_winner_data(): void
    {
        Http::fake();

        $operator = Role::create(['name' => 'Operator']);
        $user = User::factory()->create(['role_id' => $operator->id]);
        $match = FightMatch::create([
            'status' => 'done',
            'round_number' => 1,
            'winner_corner' => 'yellow',
            'winner_status' => 'menang_angka',
        ]);

        $response = $this
            ->actingAs($user)
            ->postJson('/api/partai/update-status', [
                'id' => $match->id,
                'status' => 'ongoing',
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.status', 'ongoing')
            ->assertJsonPath('data.winner_corner', null)
            ->assertJsonPath('data.winner_status', null);

        $this->assertDatabaseHas('fight_matches', [
            'id' => $match->id,
            'status' => 'ongoing',
            'winner_corner' => null,
            'winner_status' => null,
        ]);
    }

    public function test_starting_match_clears_current_round_winner(): void
    {
        Http::fake();

        $operator = Role::create(['name' => 'Operator']);
        $user = User::factory()->create(['role_id' => $operator->id]);
        $match = FightMatch::create([
            'status' => 'paused',
            'round_number' => 2,
            'winner_corner' => 'blue',
            'winner_status' => 'menang_angka',
        ]);
        FightRecapJuryPoint::create([
            'round_number' => 1,
            'winner' => 'yellow',
        ]);
        $currentRoundRecap = FightRecapJuryPoint::create([
            'round_number' => 2,
            'winner' => 'blue',
        ]);

        $response = $this
            ->actingAs($user)
            ->postJson('/api/partai/update-status', [
                'id' => $match->id,
                'status' => 'ongoing',
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.winner_corner', null)
            ->assertJsonPath('data.winner_status', null)
            ->assertJsonPath('recap.round_number', 2)
            ->assertJsonPath('recap.winner', null);

        $this->assertDatabaseHas('fight_recap_jury_points', [
            'id' => $currentRoundRecap->id,
            'round_number' => 2,
            'winner' => null,
        ]);
        $this->assertDatabaseHas('fight_recap_jury_points', [
            'round_number' => 1,
            'winner' => 'yellow',
        ]);
    }

    public function test_save_partai_data_ts_can_reset_match_scores(): void
    {
        Http::fake([
            '*' => Http::response(['success' => true], 200),
        ]);

        $operator = Role::create(['name' => 'Operator']);
        $user = User::factory()->create(['role_id' => $operator->id]);
        $schedule = FightSchedule::create([
            'partai_id' => 'P-001',
            'status' => 'done',
            'winner_corner' => 'yellow',
            'winner_status' => 'menang_angka',
        ]);
        $match = FightMatch::create([
            'fight_schedule_id' => $schedule->id,
            'partai_id' => 'P-001',
            'status' => 'done',
            'round_number' => 2,
            'winner_corner' => 'yellow',
            'winner_status' => 'menang_angka',
        ]);
        FightRecapJuryPoint::create([
            'round_number' => 1,
            'total_poin_yellow' => 10,
            'total_poin_blue' => 5,
            'winner' => 'yellow',
        ]);
        FightDetailJuryPointBlue::create([
            'jury_number' => 1,
            'round_number' => 1,
        ]);
        FightDetailJuryPointYellow::create([
            'jury_number' => 1,
            'round_number' => 1,
        ]);

        $response = $this
            ->actingAs($user)
            ->postJson('/api/partai/save-partai-data-ts/P-001', [
                'status' => 'not_started',
                'winner_corner' => null,
                'winner_status' => null,
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.status', 'not_started')
            ->assertJsonPath('data.round_number', 1)
            ->assertJsonPath('data.winner_corner', null)
            ->assertJsonPath('data.winner_status', null);

        $this->assertDatabaseHas('fight_matches', [
            'id' => $match->id,
            'status' => 'not_started',
            'round_number' => 1,
            'winner_corner' => null,
            'winner_status' => null,
        ]);
        $this->assertDatabaseHas('fight_schedules', [
            'id' => $schedule->id,
            'status' => 'not_started',
            'winner_corner' => null,
            'winner_status' => null,
        ]);
        $this->assertDatabaseCount('fight_recap_jury_points', 0);
        $this->assertDatabaseCount('fight_detail_jury_point_blues', 0);
        $this->assertDatabaseCount('fight_detail_jury_point_yellows', 0);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/partai/save-partai-data-ts/P-001')
            && $request['status'] === 'not_started_yet'
            && $request['total_poin_blue'] === '0'
            && $request['total_poin_yellow'] === '0'
            && $request['round_number'] === 1
            && $request['winner_corner'] === null
            && $request['winner_status'] === null
            && $request['recap_jury_poin_round_one']['juri_one']['detail_score_blue'] === []);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/partai/partai-status/P-001')
            && $request['status'] === 'not_started_yet');
        Http::assertSentCount(2);
    }

    public function test_update_status_can_sync_not_started_to_server(): void
    {
        Http::fake([
            '*' => Http::response(['success' => true], 200),
        ]);

        $operator = Role::create(['name' => 'Operator']);
        $user = User::factory()->create(['role_id' => $operator->id]);
        $schedule = FightSchedule::create([
            'partai_id' => 'P-002',
            'status' => 'done',
            'winner_corner' => 'blue',
        ]);
        $match = FightMatch::create([
            'fight_schedule_id' => $schedule->id,
            'partai_id' => 'P-002',
            'status' => 'done',
            'winner_corner' => 'blue',
            'winner_status' => 'menang_angka',
        ]);

        $response = $this
            ->actingAs($user)
            ->postJson('/api/partai/update-status', [
                'id' => $match->id,
                'status' => 'not_started',
                'sync_server' => true,
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.status', 'not_started')
            ->assertJsonPath('data.winner_corner', null)
            ->assertJsonPath('data.winner_status', null);

        $this->assertDatabaseHas('fight_schedules', [
            'id' => $schedule->id,
            'status' => 'not_started',
            'winner_corner' => null,
            'winner_status' => null,
        ]);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/partai/partai-status/P-002')
            && $request['status'] === 'not_started_yet');
    }
}
