<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Arena;
use App\Models\FightMatch;
use App\Models\FightSchedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ScoringSourceController extends Controller
{
    /**
     * @return array<string, string|null>
     */
    private function getHeaders(): array
    {
        $apiKey = config('services.scoring.key');

        return [
            'X-API-KEY' => $apiKey,
            'Authorization' => 'Bearer '.$apiKey,
            'Accept' => 'application/json',
        ];
    }

    private function getBaseUrl(): string
    {
        $apiUrl = rtrim((string) config('services.scoring.url'), '/');

        if (! preg_match('~^(?:f|ht)tps?://~i', $apiUrl)) {
            return 'http://'.$apiUrl;
        }

        return $apiUrl;
    }

    public function getGelanggang()
    {
        $response = Http::withHeaders($this->getHeaders())
            ->get($this->getBaseUrl().'/gelanggang');

        return response()->json($response->json(), $response->status());
    }

    public function getSesi($gelanggang_id)
    {
        $response = Http::withHeaders($this->getHeaders())
            ->get($this->getBaseUrl().'/sesi/tanding/'.$gelanggang_id);

        return response()->json($response->json(), $response->status());
    }

    public function setupArena(Request $request)
    {
        $validated = $request->validate([
            'gelanggang_id' => ['required', 'integer'],
            'sesi_tanding_id' => ['required', 'integer'],
            'championship_name' => ['nullable', 'string'],
            'arena_name' => ['nullable', 'string'],
        ]);

        try {
            $response = Http::timeout(10)
                ->connectTimeout(3)
                ->withHeaders($this->getHeaders())
                ->get($this->getBaseUrl().'/partai/tanding/'.$validated['sesi_tanding_id']);
        } catch (\Throwable $e) {
            Log::error('Failed to contact scoring server while syncing matches.', [
                'sesi_tanding_id' => $validated['sesi_tanding_id'],
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to sync matches.',
                'error' => 'Unable to contact scoring server.',
            ], 502);
        }

        if ($response->successful()) {
            $data = $response->json();
            $matches = $data['data'] ?? $data;

            if (! is_array($matches)) {
                Log::error('Failed to sync matches: malformed response.', [
                    'sesi_tanding_id' => $validated['sesi_tanding_id'],
                    'response' => $data,
                ]);

                return response()->json([
                    'message' => 'Failed to sync matches.',
                    'error' => 'Malformed response from scoring server.',
                ], 502);
            }

            $matches = collect($matches);
            $partaiIds = $matches
                ->pluck('id')
                ->filter(fn ($partaiId): bool => filled($partaiId))
                ->map(fn ($partaiId): string => (string) $partaiId)
                ->values();

            DB::transaction(function () use ($validated, $matches, $partaiIds): void {
                $arena = Arena::firstOrCreate(['id' => 1]);
                $arena->update($validated);

                $obsoleteScheduleIds = $partaiIds->isEmpty()
                    ? FightSchedule::query()->pluck('id')
                    : FightSchedule::query()
                        ->whereNotIn('partai_id', $partaiIds->all())
                        ->pluck('id');

                if ($obsoleteScheduleIds->isNotEmpty()) {
                    FightMatch::query()
                        ->whereIn('fight_schedule_id', $obsoleteScheduleIds->all())
                        ->update(['fight_schedule_id' => null]);

                    FightSchedule::query()
                        ->whereIn('id', $obsoleteScheduleIds->all())
                        ->delete();
                }

                foreach ($matches as $match) {
                    $partaiId = $match['id'] ?? null;

                    if (! filled($partaiId)) {
                        continue;
                    }

                    $winnerCorner = $match['winner_corner'] ?? null;
                    if ($winnerCorner === 'red') {
                        $winnerCorner = 'yellow';
                    } elseif ($winnerCorner === 'red_draw') {
                        $winnerCorner = 'yellow_draw';
                    }

                    FightSchedule::updateOrCreate(
                        ['partai_id' => (string) $partaiId],
                        [
                            'match_code' => $match['match_code'] ?? $match['kode_partai'] ?? $match['kode'] ?? null,
                            'match_number' => (int) ($match['match_number'] ?? $match['partai'] ?? $match['nomor_partai'] ?? 0),
                            'athlete_yellow' => $match['atlete_red'] ?? $match['pesilat_merah'] ?? $match['merah_nama'] ?? $match['athlete_red'] ?? null,
                            'athlete_blue' => $match['atlete_blue'] ?? $match['pesilat_biru'] ?? $match['biru_nama'] ?? $match['athlete_blue'] ?? null,
                            'contingent_yellow' => $match['contingent_red'] ?? $match['kontingen_merah'] ?? $match['merah_kontingen'] ?? null,
                            'contingent_blue' => $match['contingent_blue'] ?? $match['kontingen_biru'] ?? $match['biru_kontingen'] ?? null,
                            'match_round' => $match['match_round'] ?? $match['babak_int'] ?? null,
                            'category' => $match['category'] ?? $match['kategori'] ?? null,
                            'group' => $match['group'] ?? $match['kelas'] ?? $match['golongan'] ?? null,
                            'status' => $match['status'] ?? 'not_started',
                            'winner_corner' => $winnerCorner,
                            'winner_status' => $match['winner_status'] ?? null,
                        ]
                    );
                }

                FightMatch::query()
                    ->whereNotNull('partai_id')
                    ->get()
                    ->each(function (FightMatch $fightMatch): void {
                        $schedule = FightSchedule::where('partai_id', $fightMatch->partai_id)->first();

                        $fightMatch->update([
                            'fight_schedule_id' => $schedule?->id,
                        ]);

                        $schedule?->update([
                            'status' => $fightMatch->status,
                            'winner_corner' => $fightMatch->winner_corner,
                            'winner_status' => $fightMatch->winner_status,
                        ]);
                    });
            });

            return response()->json(['message' => 'Arena setup successfully and matches synced.', 'matches_count' => count($matches)]);
        }

        Log::error('Failed to sync matches from scoring server.', [
            'sesi_tanding_id' => $validated['sesi_tanding_id'],
            'status' => $response->status(),
            'response' => $response->json() ?? $response->body(),
        ]);

        return response()->json(['message' => 'Failed to sync matches.', 'error' => $response->json()], $response->status());
    }
}
