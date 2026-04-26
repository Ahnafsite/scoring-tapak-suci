<?php

namespace App\Http\Controllers\Api;

use App\Events\ActiveMatchUpdated;
use App\Events\JuryScoreUpdated;
use App\Http\Controllers\Controller;
use App\Models\FightDetailJuryPointBlue;
use App\Models\FightDetailJuryPointYellow;
use App\Models\FightMatch;
use App\Models\FightRecapJuryPoint;
use App\Models\FightSchedule;
use App\Models\RefPunishment;
use App\Models\RefScore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;

class MatchSyncController extends Controller
{
    public function updateStatus(Request $request)
    {
        $validated = $request->validate([
            'id' => 'required',
            'status' => 'required',
        ]);

        $match = FightMatch::findOrFail($validated['id']);

        $isStartingFirstRound = ($validated['status'] === 'ongoing' && $match->status !== 'ongoing' && $match->round_number == 1);
        $shouldSyncStatusToServer = $request->boolean('sync_server') || $isStartingFirstRound;

        $matchUpdates = ['status' => $validated['status']];
        $clearedRecap = null;

        if (in_array($validated['status'], ['ongoing', 'not_started'], true)) {
            $matchUpdates['winner_corner'] = null;
            $matchUpdates['winner_status'] = null;
        }

        if ($validated['status'] === 'ongoing') {
            $clearedRecap = FightRecapJuryPoint::where('round_number', $match->round_number)->first();
            $clearedRecap?->update(['winner' => null]);
        }

        $match->update($matchUpdates);

        // Also update related fight_schedule status
        if ($match->fight_schedule_id) {
            $scheduleUpdates = ['status' => $validated['status']];

            if (in_array($validated['status'], ['ongoing', 'not_started'], true)) {
                $scheduleUpdates['winner_corner'] = null;
                $scheduleUpdates['winner_status'] = null;
            }

            FightSchedule::where('id', $match->fight_schedule_id)
                ->update($scheduleUpdates);
        }

        // Broadcast real-time update
        try {
            broadcast(new ActiveMatchUpdated($match->fresh()))->toOthers();
        } catch (\Exception $e) {
            \Log::warning('Broadcasting ActiveMatchUpdated failed: '.$e->getMessage());
        }

        if ($clearedRecap) {
            try {
                broadcast(new JuryScoreUpdated(null, null, $match->round_number, null, null, $clearedRecap->fresh()))->toOthers();
            } catch (\Exception $e) {
                \Log::warning('Broadcasting JuryScoreUpdated failed: '.$e->getMessage());
            }
        }

        if ($shouldSyncStatusToServer) {
            try {
                $apiUrl = rtrim(env('API_URL'), '/');
                $apiKey = env('API_KEY');
                $serverStatus = $validated['status'] === 'not_started' ? 'not_started_yet' : $validated['status'];

                if (! preg_match('~^(?:f|ht)tps?://~i', $apiUrl)) {
                    $apiUrl = 'http://'.$apiUrl;
                }

                Http::withHeaders([
                    'X-API-KEY' => $apiKey,
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ])->post("{$apiUrl}/partai/partai-status/{$match->partai_id}", [
                    'status' => $serverStatus,
                ]);
            } catch (\Exception $e) {
                \Log::error('Failed to update status to server: '.$e->getMessage());
            }
        }

        return response()->json([
            'success' => true,
            'data' => $match->fresh(),
            'recap' => $clearedRecap?->fresh(),
        ]);
    }

    public function updateRound(Request $request)
    {
        $validated = $request->validate([
            'id' => 'required',
            'round_number' => 'required|integer|min:1|max:3',
        ]);

        $match = FightMatch::findOrFail($validated['id']);
        $match->update(['round_number' => $validated['round_number']]);

        // Broadcast real-time update
        try {
            broadcast(new ActiveMatchUpdated($match->fresh()))->toOthers();
        } catch (\Exception $e) {
            \Log::warning('Broadcasting ActiveMatchUpdated failed: '.$e->getMessage());
        }

        return response()->json(['success' => true, 'data' => $match]);
    }

    public function updateRoundWinner(Request $request)
    {
        $validated = $request->validate([
            'round_number' => 'required|integer|min:1|max:3',
            'winner' => 'nullable|in:yellow,blue,draw',
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

        try {
            broadcast(new JuryScoreUpdated(null, null, $validated['round_number'], null, null, $recap))->toOthers();
        } catch (\Exception $e) {
            \Log::warning('Broadcasting JuryScoreUpdated failed: '.$e->getMessage());
        }

        return response()->json(['success' => true, 'data' => $recap]);
    }

    public function syncMatch(Request $request, $partai_id)
    {
        $apiUrl = rtrim(env('API_URL'), '/');
        $apiKey = env('API_KEY');

        // Note: the external API is supposed to be $apiUrl/partai/detail-tanding-ts/{partai_id}
        // Example: http://127.0.0.1:8000/api/partai/detail-tanding-ts/1
        if (! preg_match('~^(?:f|ht)tps?://~i', $apiUrl)) {
            $apiUrl = 'http://'.$apiUrl;
        }

        $response = Http::withHeaders([
            'X-API-KEY' => $apiKey,
            'Accept' => 'application/json',
        ])->get("{$apiUrl}/partai/detail-tanding-ts/{$partai_id}");

        if (! $response->successful()) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil data dari server scoring.',
                'error' => $response->json() ?? $response->body(),
            ], 400);
        }

        $resData = $response->json();

        if (! $resData['success'] || empty($resData['data'])) {
            return response()->json([
                'success' => false,
                'message' => 'Data tidak ditemukan di server scoring.',
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

            if ($request->input('fight_schedule_id')) {
                FightSchedule::where('id', $request->input('fight_schedule_id'))
                    ->update([
                        'athlete_yellow' => $data['atlete_yellow'],
                        'athlete_blue' => $data['atlete_blue'],
                        'contingent_yellow' => $data['contingent_yellow'],
                        'contingent_blue' => $data['contingent_blue'],
                        'status' => $data['status'],
                        'winner_corner' => $data['winner_corner'] ?? null,
                    ]);
            }

            $roundsRecapMap = [
                'recap_jury_poin_round_one' => 1,
                'recap_jury_poin_round_two' => 2,
                'recap_jury_poin_round_add' => 3,
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
            $refScores = RefScore::pluck('id', 'name')->toArray();
            $refPunishments = RefPunishment::pluck('id', 'name')->toArray();

            $roundsMap = [
                'recap_jury_poin_round_one' => 1,
                'recap_jury_poin_round_two' => 2,
                'recap_jury_poin_round_add' => 3,
            ];
            $juryMap = [
                'juri_one' => 1,
                'juri_two' => 2,
                'juri_three' => 3,
                'juri_four' => 4,
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

            $juryController = new JuryScoreController;
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
            try {
                broadcast(new ActiveMatchUpdated($match->fresh()))->toOthers();
            } catch (\Exception $e) {
                \Log::warning('Broadcasting ActiveMatchUpdated failed: '.$e->getMessage());
            }

            return response()->json([
                'success' => true,
                'message' => 'Data partai berhasil disinkronkan',
                'data' => $match,
                'recap' => FightRecapJuryPoint::get(),
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();
            \Log::error('MatchSyncController Error: '.$e->getMessage());
            \Log::error($e->getTraceAsString());

            return response()->json([
                'success' => false,
                'message' => 'Gagal sinkronisasi data.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function savePartaiDataTs(Request $request, $partai_id)
    {
        $status = $request->input('status', 'done');

        $validated = $request->validate([
            'status' => ['sometimes', 'in:not_started,done'],
            'winner_corner' => [Rule::requiredIf($status === 'done'), 'nullable', 'in:yellow,blue,draw'],
            'winner_status' => [Rule::requiredIf($status === 'done'), 'nullable', 'string'],
        ]);

        $status = $validated['status'] ?? 'done';
        $serverStatus = $status === 'not_started' ? 'not_started_yet' : $status;
        $match = FightMatch::where('partai_id', $partai_id)->firstOrFail();

        // Prepare API Payload for External Tapak Suci Server
        $recaps = $status === 'not_started'
            ? collect()
            : FightRecapJuryPoint::orderBy('round_number')->get();
        $totalPoinYellow = $recaps->sum('total_poin_yellow');
        $totalPoinBlue = $recaps->sum('total_poin_blue');

        $payload = [
            'status' => $serverStatus,
            'total_poin_blue' => (string) $totalPoinBlue,
            'total_poin_yellow' => (string) $totalPoinYellow,
            'round_number' => $status === 'not_started' ? 1 : $match->round_number,
            'winner_corner' => $validated['winner_corner'] ?? null,
            'winner_status' => $validated['winner_status'] ?? null,
        ];

        // Format recap for rounds 1, 2, 3
        $roundsMap = [
            1 => 'recap_jury_poin_round_one',
            2 => 'recap_jury_poin_round_two',
            3 => 'recap_jury_poin_round_add', // Usually TS TBH round
        ];

        $refScores = RefScore::pluck('name', 'id')->toArray();
        $refPunishments = RefPunishment::pluck('name', 'id')->toArray();

        $juryKeys = [1 => 'juri_one', 2 => 'juri_two', 3 => 'juri_three', 4 => 'juri_four'];
        $words = ['one', 'two', 'three', 'four'];

        if ($status === 'not_started') {
            foreach ($roundsMap as $rKey) {
                $payload[$rKey] = [
                    'round_winner' => null,
                ];

                foreach ($juryKeys as $jKey) {
                    $payload[$rKey][$jKey] = [
                        'detail_score_blue' => [],
                        'detail_score_yellow' => [],
                        'total_poin_blue' => '0',
                        'total_poin_yellow' => '0',
                        'winner' => null,
                    ];
                }
            }
        } else {
            foreach ($recaps as $recap) {
                $rNum = $recap->round_number;
                if (! isset($roundsMap[$rNum])) {
                    continue;
                }
                $rKey = $roundsMap[$rNum];

                $payload[$rKey] = [
                    'round_winner' => $recap->winner,
                ];

                foreach ($juryKeys as $jNum => $jKey) {
                    // Get detail scores for Blue
                    $bluePoints = FightDetailJuryPointBlue::where('round_number', $rNum)
                        ->where('jury_number', $jNum)
                        ->orderBy('id')->get();
                    $arrBlue = [];
                    foreach ($bluePoints as $bp) {
                        if ($bp->ref_score_id) {
                            $arrBlue[] = $refScores[$bp->ref_score_id] ?? '';
                        } elseif ($bp->ref_punishment_id) {
                            $arrBlue[] = $refPunishments[$bp->ref_punishment_id] ?? '';
                        }
                    }

                    // Get detail scores for Yellow
                    $yellowPoints = FightDetailJuryPointYellow::where('round_number', $rNum)
                        ->where('jury_number', $jNum)
                        ->orderBy('id')->get();
                    $arrYellow = [];
                    foreach ($yellowPoints as $yp) {
                        if ($yp->ref_score_id) {
                            $arrYellow[] = $refScores[$yp->ref_score_id] ?? '';
                        } elseif ($yp->ref_punishment_id) {
                            $arrYellow[] = $refPunishments[$yp->ref_punishment_id] ?? '';
                        }
                    }

                    $word = $words[$jNum - 1];

                    $payload[$rKey][$jKey] = [
                        'detail_score_blue' => $arrBlue,
                        'detail_score_yellow' => $arrYellow,
                        'total_poin_blue' => (string) $recap->{"jury_{$word}_total_poin_blue"},
                        'total_poin_yellow' => (string) $recap->{"jury_{$word}_total_poin_yellow"},
                        'winner' => $recap->{"jury_{$word}_winner"},
                    ];
                }
            }
        }

        $apiUrl = rtrim(env('API_URL'), '/');
        $apiKey = env('API_KEY');

        if (! preg_match('~^(?:f|ht)tps?://~i', $apiUrl)) {
            $apiUrl = 'http://'.$apiUrl;
        }

        try {
            $response = Http::withHeaders([
                'X-API-KEY' => $apiKey,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ])->post("{$apiUrl}/partai/save-partai-data-ts/{$partai_id}", $payload);

            if (! $response->successful()) {
                \Log::error('Failed saving partai to server: '.$response->body());

                return response()->json([
                    'success' => false,
                    'message' => 'Gagal menyimpan data ke server scoring.',
                    'error' => $response->json() ?? $response->body(),
                    'payload' => $payload,
                ], 400);
            }

            if ($status === 'not_started') {
                $statusResponse = Http::withHeaders([
                    'X-API-KEY' => $apiKey,
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ])->post("{$apiUrl}/partai/partai-status/{$partai_id}", [
                    'status' => $serverStatus,
                ]);

                if (! $statusResponse->successful()) {
                    \Log::error('Failed resetting partai status on server: '.$statusResponse->body());

                    return response()->json([
                        'success' => false,
                        'message' => 'Gagal mengubah status partai ke belum mulai di server scoring.',
                        'error' => $statusResponse->json() ?? $statusResponse->body(),
                        'payload' => $payload,
                    ], 400);
                }
            }
        } catch (\Exception $e) {
            \Log::error('Exception saving partai to server: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Gagal menghubungi server.',
                'error' => $e->getMessage(),
                'payload' => $payload,
            ], 500);
        }

        $isDisqualification = $status === 'done'
            && ($validated['winner_status'] ?? null) === 'menang_diskualifikasi';

        if ($status === 'not_started') {
            DB::transaction(function () use ($match): void {
                FightDetailJuryPointBlue::query()->delete();
                FightDetailJuryPointYellow::query()->delete();
                FightRecapJuryPoint::query()->delete();

                $match->update([
                    'status' => 'not_started',
                    'round_number' => 1,
                    'winner_corner' => null,
                    'winner_status' => null,
                ]);

                if ($match->fight_schedule_id) {
                    FightSchedule::where('id', $match->fight_schedule_id)
                        ->update([
                            'status' => 'not_started',
                            'winner_corner' => null,
                            'winner_status' => null,
                        ]);
                }
            });
        } elseif ($isDisqualification) {
            DB::transaction(function () use ($match, $validated): void {
                FightDetailJuryPointBlue::query()->delete();
                FightDetailJuryPointYellow::query()->delete();
                FightRecapJuryPoint::query()->delete();

                $match->update([
                    'status' => 'done',
                    'winner_corner' => $validated['winner_corner'],
                    'winner_status' => $validated['winner_status'],
                ]);

                if ($match->fight_schedule_id) {
                    FightSchedule::where('id', $match->fight_schedule_id)
                        ->update([
                            'status' => 'done',
                            'winner_corner' => $validated['winner_corner'],
                            'winner_status' => $validated['winner_status'],
                        ]);
                }
            });
        } else {
            $match->update([
                'status' => 'done',
                'winner_corner' => $validated['winner_corner'],
                'winner_status' => $validated['winner_status'],
            ]);

            if ($match->fight_schedule_id) {
                FightSchedule::where('id', $match->fight_schedule_id)
                    ->update([
                        'status' => 'done',
                        'winner_corner' => $validated['winner_corner'],
                        'winner_status' => $validated['winner_status'],
                    ]);
            }
        }

        try {
            broadcast(new ActiveMatchUpdated($match->fresh()))->toOthers();
        } catch (\Exception $e) {
            \Log::warning('Broadcasting ActiveMatchUpdated failed: '.$e->getMessage());
        }

        return response()->json([
            'success' => true,
            'message' => 'Data pertandingan berhasil disimpan dan disinkronkan.',
            'data' => $match,
        ]);
    }
}
