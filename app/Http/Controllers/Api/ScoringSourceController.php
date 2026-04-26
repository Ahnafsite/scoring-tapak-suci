<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Arena;
use App\Models\FightMatch;
use App\Models\FightSchedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class ScoringSourceController extends Controller
{
    private function getHeaders()
    {
        return [
            'X-API-KEY' => env('API_KEY'),
            'Authorization' => 'Bearer '.env('API_KEY'),
            'Accept' => 'application/json',
        ];
    }

    private function getBaseUrl()
    {
        return env('API_URL', 'http://127.0.0.1:8000/api');
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
            'gelanggang_id' => 'required',
            'sesi_tanding_id' => 'required',
            'championship_name' => 'nullable|string',
            'arena_name' => 'nullable|string',
        ]);

        $arena = Arena::firstOrCreate(['id' => 1]);
        $arena->update($validated);

        // Fetch matches for the given session
        $response = Http::withHeaders($this->getHeaders())
            ->get($this->getBaseUrl().'/partai/tanding/'.$validated['sesi_tanding_id']);

        if ($response->successful()) {
            $data = $response->json();
            $matches = $data['data'] ?? $data;

            // Detach active fight matches before schedule refresh because fight_schedule_id cascades on delete.
            FightMatch::query()->update(['fight_schedule_id' => null]);

            // Per user request, delete old schedules to only keep the current session
            FightSchedule::query()->delete();

            foreach ($matches as $match) {
                $winnerCorner = $match['winner_corner'] ?? null;
                if ($winnerCorner === 'red') {
                    $winnerCorner = 'yellow';
                } elseif ($winnerCorner === 'red_draw') {
                    $winnerCorner = 'yellow_draw';
                }

                // Map the red corner into yellow corner per business logic requirement
                FightSchedule::updateOrCreate(
                    ['partai_id' => $match['id'] ?? null],
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

                    if ($schedule) {
                        $fightMatch->update(['fight_schedule_id' => $schedule->id]);
                    }
                });

            return response()->json(['message' => 'Arena setup successfully and matches synced.', 'matches_count' => count($matches)]);
        }

        return response()->json(['message' => 'Failed to sync matches.', 'error' => $response->json()], $response->status());
    }
}
