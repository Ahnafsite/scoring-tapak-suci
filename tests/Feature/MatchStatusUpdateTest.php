<?php

namespace Tests\Feature;

use App\Models\FightMatch;
use App\Models\FightRecapJuryPoint;
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
}
