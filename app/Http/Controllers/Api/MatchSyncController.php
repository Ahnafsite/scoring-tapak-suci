<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use App\Models\FightMatch;
use App\Models\FightSchedule;
use App\Models\FightRecapJuryPoint;
use App\Models\FightDetailJuryPointBlue;
use App\Models\FightDetailJuryPointYellow;
use App\Events\ActiveMatchUpdated;

class MatchSyncController extends Controller
{
    public function updateStatus(Request $request)
    {
        $validated = $request->validate([
            'id' => 'required',
            'status' => 'required'
        ]);

        $match = FightMatch::findOrFail($validated['id']);
        $match->update(['status' => $validated['status']]);

        // Also update related fight_schedule status
        if ($match->fight_schedule_id) {
            FightSchedule::where('id', $match->fight_schedule_id)
                ->update(['status' => $validated['status']]);
        }

        // Broadcast real-time update
        broadcast(new ActiveMatchUpdated($match->fresh()))->toOthers();

        return response()->json(['success' => true, 'data' => $match]);
    }

    public function updateRound(Request $request)
    {
        $validated = $request->validate([
            'id' => 'required',
            'round_number' => 'required|integer|min:1|max:3'
        ]);

        $match = FightMatch::findOrFail($validated['id']);
        $match->update(['round_number' => $validated['round_number']]);

        // Broadcast real-time update
        broadcast(new ActiveMatchUpdated($match->fresh()))->toOthers();

        return response()->json(['success' => true, 'data' => $match]);
    }

    public function updateRoundWinner(Request $request)
    {
        $validated = $request->validate([
            'round_number' => 'required|integer|min:1|max:3',
            'winner' => 'required|in:yellow,blue,draw',
        ]);

        $recap = FightRecapJuryPoint::firstOrCreate(
            ['round_number' => $validated['round_number']],
            [
                'jury_one_total_poin_blue' => 0, 'jury_one_total_poin_yellow' => 0,
                'jury_two_total_poin_blue' => 0, 'jury_two_total_poin_yellow' => 0,
                'jury_three_total_poin_blue' => 0, 'jury_three_total_poin_yellow' => 0,
                'jury_four_total_poin_blue' => 0, 'jury_four_total_poin_yellow' => 0,
            ]
        );

        $recap->update(['winner' => $validated['winner']]);

        return response()->json(['success' => true, 'data' => $recap]);
    }

    public function syncMatch(Request $request, $partai_id)
    {
        $apiUrl = rtrim(env('API_URL'), '/');
        $apiKey = env('API_KEY');

        // Note: the external API is supposed to be $apiUrl/partai/detail-tanding-ts/{partai_id}
        // Example: http://127.0.0.1:8000/api/partai/detail-tanding-ts/1
        if (!preg_match("~^(?:f|ht)tps?://~i", $apiUrl)) {
            $apiUrl = "http://" . $apiUrl;
        }

        $response = Http::withHeaders([
            'X-API-KEY' => $apiKey,
            'Accept' => 'application/json',
        ])->get("{$apiUrl}/partai/detail-tanding-ts/{$partai_id}");

        if (!$response->successful()) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil data dari server scoring.',
                'error' => $response->json() ?? $response->body()
            ], 400);
        }

        $resData = $response->json();
        
        if (!$resData['success'] || empty($resData['data'])) {
            return response()->json([
                'success' => false,
                'message' => 'Data tidak ditemukan di server scoring.'
            ], 404);
        }

        $data = $resData['data'];

        DB::beginTransaction();
        try {
            // Because fight_recap, fight_detail, and fight_matches acts as local transient state based on user's instruction
            FightDetailJuryPointBlue::query()->delete();
            FightDetailJuryPointYellow::query()->delete();
            FightRecapJuryPoint::query()->delete();
            FightMatch::query()->delete();

            // Insert new data for the selected match in fight_matches table.
            $match = FightMatch::create([
                'match_code' => $data['match_code'],
                'fight_schedule_id' => $request->input('fight_schedule_id'),
                'partai_id' => $partai_id,
                'match_number' => $data['match_number'],
                'atlete_yellow' => $data['atlete_yellow'],
                'atlete_blue' => $data['atlete_blue'],
                'contingent_yellow' => $data['contingent_yellow'],
                'contingent_blue' => $data['contingent_blue'],
                'match_round' => $data['match_round'],
                'category' => $data['category'],
                'group' => $data['group'],
                'status' => $data['status'],
                'round_number' => $data['round_number'] ?? 1,
                'winner_corner' => $data['winner_corner'] ?? null,
                'winner_status' => $data['winner_status'] ?? null,
                'weight_yellow' => $data['weight_yellow'],
                'weight_status_yellow' => $data['weight_status_yellow'],
                'weight_blue' => $data['weight_blue'],
                'weight_status_blue' => $data['weight_status_blue'],
            ]);

            $roundsRecapMap = [
                'recap_jury_poin_round_one' => 1,
                'recap_jury_poin_round_two' => 2,
                'recap_jury_poin_round_add' => 3
            ];

            foreach ($roundsRecapMap as $rKey => $rNum) {
                if (isset($data[$rKey]) && is_array($data[$rKey])) {
                    $recapObj = $data[$rKey];
                    
                    $recapData = [
                        'round_number' => $rNum,
                        'winner' => $recapObj['round_winner'] ?? null,
                    ];
                    
                    if (isset($recapObj['juri_one'])) {
                        $recapData['jury_one_total_poin_blue'] = $recapObj['juri_one']['total_poin_blue'] ?? 0;
                        $recapData['jury_one_total_poin_yellow'] = $recapObj['juri_one']['total_poin_yellow'] ?? 0;
                        $recapData['jury_one_winner'] = $recapObj['juri_one']['winner'] ?? null;
                    }
                    if (isset($recapObj['juri_two'])) {
                        $recapData['jury_two_total_poin_blue'] = $recapObj['juri_two']['total_poin_blue'] ?? 0;
                        $recapData['jury_two_total_poin_yellow'] = $recapObj['juri_two']['total_poin_yellow'] ?? 0;
                        $recapData['jury_two_winner'] = $recapObj['juri_two']['winner'] ?? null;
                    }
                    if (isset($recapObj['juri_three'])) {
                        $recapData['jury_three_total_poin_blue'] = $recapObj['juri_three']['total_poin_blue'] ?? 0;
                        $recapData['jury_three_total_poin_yellow'] = $recapObj['juri_three']['total_poin_yellow'] ?? 0;
                        $recapData['jury_three_winner'] = $recapObj['juri_three']['winner'] ?? null;
                    }
                    if (isset($recapObj['juri_four'])) {
                        $recapData['jury_four_total_poin_blue'] = $recapObj['juri_four']['total_poin_blue'] ?? 0;
                        $recapData['jury_four_total_poin_yellow'] = $recapObj['juri_four']['total_poin_yellow'] ?? 0;
                        $recapData['jury_four_winner'] = $recapObj['juri_four']['winner'] ?? null;
                    }
                    
                    FightRecapJuryPoint::create($recapData);
                }
            }

            // Map string scores from detail_score arrays for all rounds securely
            $refScores = \App\Models\RefScore::pluck('id', 'name')->toArray();
            $refPunishments = \App\Models\RefPunishment::pluck('id', 'name')->toArray();

            $roundsMap = [
                'recap_jury_poin_round_one' => 1,
                'recap_jury_poin_round_two' => 2,
                'recap_jury_poin_round_add' => 3
            ];
            $juryMap = [
                'juri_one' => 1,
                'juri_two' => 2,
                'juri_three' => 3,
                'juri_four' => 4
            ];

            foreach ($roundsMap as $rKey => $rNum) {
                if (isset($data[$rKey]) && is_array($data[$rKey])) {
                    foreach ($juryMap as $jKey => $jNum) {
                        if (isset($data[$rKey][$jKey])) {
                            $jData = $data[$rKey][$jKey];
                            
                            // Blue Points
                            if (isset($jData['detail_score_blue']) && is_array($jData['detail_score_blue'])) {
                                foreach ($jData['detail_score_blue'] as $scoreStr) {
                                    FightDetailJuryPointBlue::create([
                                        'jury_number' => $jNum,
                                        'round_number' => $rNum,
                                        'ref_score_id' => $refScores[$scoreStr] ?? null,
                                        'ref_punishment_id' => $refPunishments[$scoreStr] ?? null,
                                    ]);
                                }
                            }

                            // Yellow Points
                            if (isset($jData['detail_score_yellow']) && is_array($jData['detail_score_yellow'])) {
                                foreach ($jData['detail_score_yellow'] as $scoreStr) {
                                    FightDetailJuryPointYellow::create([
                                        'jury_number' => $jNum,
                                        'round_number' => $rNum,
                                        'ref_score_id' => $refScores[$scoreStr] ?? null,
                                        'ref_punishment_id' => $refPunishments[$scoreStr] ?? null,
                                    ]);
                                }
                            }
                        }
                    }
                }
            }

            $juryController = new \App\Http\Controllers\Api\JuryScoreController();
            foreach ($roundsMap as $rKey => $rNum) {
                 $rObj = FightRecapJuryPoint::where('round_number', $rNum)->first();
                 if ($rObj) {
                     $rObj->total_poin_yellow = $juryController->calculateSecretaryValidatedTotal('yellow', $rNum);
                     $rObj->total_poin_blue = $juryController->calculateSecretaryValidatedTotal('blue', $rNum);
                     $rObj->save();
                 }
            }

            DB::commit();

            // Broadcast real-time update for new match sync
            broadcast(new ActiveMatchUpdated($match->fresh()))->toOthers();

            return response()->json([
                'success' => true,
                'message' => 'Data partai berhasil disinkronkan',
                'data' => $match,
                'recap' => FightRecapJuryPoint::get()
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();
            \Log::error('MatchSyncController Error: ' . $e->getMessage());
            \Log::error($e->getTraceAsString());
            return response()->json([
                'success' => false,
                'message' => 'Gagal sinkronisasi data.',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
