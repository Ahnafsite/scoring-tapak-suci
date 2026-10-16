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

class FightMatchHardResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_operator_can_hard_reset_a_stuck_match_without_contacting_the_server(): void
    {
        Http::fake();

        $schedule = FightSchedule::create([
            'partai_id' => 'OLD-001',
            'status' => 'paused',
            'winner_corner' => 'yellow',
            'winner_status' => 'menang_angka',
        ]);
        $this->createStuckMatch($schedule, 'paused');

        $response = $this
            ->actingAs($this->userWithRole('Operator'))
            ->postJson('/api/partai/hard-reset');

        $response
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseCount('fight_matches', 0);
        $this->assertDatabaseCount('fight_recap_jury_points', 0);
        $this->assertDatabaseCount('fight_detail_jury_point_blues', 0);
        $this->assertDatabaseCount('fight_detail_jury_point_yellows', 0);
        $this->assertDatabaseHas('fight_schedules', [
            'id' => $schedule->id,
            'status' => 'not_started',
            'winner_corner' => null,
            'winner_status' => null,
        ]);

        Http::assertNothingSent();
    }

    public function test_hard_reset_keeps_the_result_of_a_finished_schedule(): void
    {
        Http::fake();

        $schedule = FightSchedule::create([
            'partai_id' => 'OLD-002',
            'status' => 'done',
            'winner_corner' => 'blue',
            'winner_status' => 'menang_angka',
        ]);
        $this->createStuckMatch($schedule, 'done');

        $this
            ->actingAs($this->userWithRole('Operator'))
            ->postJson('/api/partai/hard-reset')
            ->assertOk();

        $this->assertDatabaseCount('fight_matches', 0);
        $this->assertDatabaseHas('fight_schedules', [
            'id' => $schedule->id,
            'status' => 'done',
            'winner_corner' => 'blue',
            'winner_status' => 'menang_angka',
        ]);
    }

    public function test_hard_reset_clears_a_match_whose_schedule_no_longer_exists(): void
    {
        Http::fake();

        $this->createStuckMatch(null, 'ongoing');

        $this
            ->actingAs($this->userWithRole('Operator'))
            ->postJson('/api/partai/hard-reset')
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseCount('fight_matches', 0);
        $this->assertDatabaseCount('fight_recap_jury_points', 0);

        Http::assertNothingSent();
    }

    public function test_hard_reset_succeeds_when_there_is_no_active_match(): void
    {
        $this
            ->actingAs($this->userWithRole('Operator'))
            ->postJson('/api/partai/hard-reset')
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseCount('fight_matches', 0);
    }

    public function test_non_operator_cannot_hard_reset_the_match(): void
    {
        $match = $this->createStuckMatch(null, 'ongoing');

        $this
            ->actingAs($this->userWithRole('Juri'))
            ->postJson('/api/partai/hard-reset')
            ->assertForbidden();

        $this->assertDatabaseHas('fight_matches', ['id' => $match->id]);
        $this->assertDatabaseCount('fight_recap_jury_points', 1);
    }

    public function test_guest_cannot_hard_reset_the_match(): void
    {
        $match = $this->createStuckMatch(null, 'ongoing');

        $this
            ->postJson('/api/partai/hard-reset')
            ->assertUnauthorized();

        $this->assertDatabaseHas('fight_matches', ['id' => $match->id]);
    }

    private function userWithRole(string $roleName): User
    {
        $role = Role::create(['name' => $roleName]);

        return User::factory()->create(['role_id' => $role->id]);
    }

    private function createStuckMatch(?FightSchedule $schedule, string $status): FightMatch
    {
        $match = FightMatch::create([
            'fight_schedule_id' => $schedule?->id,
            'partai_id' => $schedule?->partai_id ?? 'OLD-999',
            'match_code' => 'A1',
            'status' => $status,
            'round_number' => 2,
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

        return $match;
    }
}
