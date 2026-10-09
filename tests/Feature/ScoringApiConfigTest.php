<?php

namespace Tests\Feature;

use App\Models\FightMatch;
use App\Models\FightRecapJuryPoint;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ScoringApiConfigTest extends TestCase
{
    use RefreshDatabase;

    /**
     * When the config cache is built, Laravel stops reading .env, so any
     * controller calling env('API_URL') directly receives null. This test
     * simulates that by only populating config, never the environment.
     */
    public function test_save_partai_data_ts_posts_to_configured_scoring_url(): void
    {
        Http::fake([
            '*' => Http::response(['success' => true], 200),
        ]);

        config(['services.scoring.url' => 'http://scoring-under-test.local/api']);
        config(['services.scoring.key' => 'test-api-key']);

        $operator = Role::create(['name' => 'Operator']);
        $user = User::factory()->create(['role_id' => $operator->id]);
        FightMatch::create([
            'partai_id' => '1',
            'status' => 'ongoing',
            'round_number' => 1,
        ]);
        FightRecapJuryPoint::create([
            'round_number' => 1,
            'total_poin_yellow' => 10,
            'total_poin_blue' => 5,
            'winner' => 'yellow',
        ]);

        $response = $this
            ->actingAs($user)
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->postJson('/api/partai/save-partai-data-ts/1', [
                'status' => 'done',
                'winner_corner' => 'yellow',
                'winner_status' => 'menang_angka',
            ]);

        $response->assertOk();

        Http::assertSent(fn ($request) => $request->url() === 'http://scoring-under-test.local/api/partai/save-partai-data-ts/1'
            && $request->hasHeader('X-API-KEY', 'test-api-key'));
    }

    public function test_scoring_config_is_populated_from_env(): void
    {
        $this->assertNotEmpty(config('services.scoring.url'));
        $this->assertNotEmpty(config('services.scoring.key'));
        $this->assertSame(env('API_URL'), config('services.scoring.url'));
        $this->assertSame(env('API_KEY'), config('services.scoring.key'));
    }
}
