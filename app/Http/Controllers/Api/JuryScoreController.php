<?php

namespace App\Http\Controllers\Api;

use App\Events\JuryScoreUpdated;
use App\Http\Controllers\Controller;
use App\Models\FightDetailJuryPointBlue;
use App\Models\FightDetailJuryPointYellow;
use App\Models\FightRecapJuryPoint;
use App\Models\RefPunishment;
use App\Models\RefScore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\FightMatch;
use App\Events\ActiveMatchUpdated;

class JuryScoreController extends Controller
{
    /**
     * Store a new score or punishment
     */
    public function storeScore(Request $request)
    {
        $validated = $request->validate([
            'partai_id' => 'required|integer',
            'corner' => 'required|in:blue,yellow',
            'round_number' => 'required|integer|min:1|max:3',
            'jury_number' => 'required|integer|min:1|max:4',
            'type' => 'required|in:score,punishment',
            'ref_id' => 'required|integer' // ID of either ref_scores or ref_punishments
        ]);

        $scoreValue = 0;
        $insertData = [
            'jury_number' => $validated['jury_number'],
            'round_number' => $validated['round_number'],
        ];

        // Fetch score value from reference tables and prepare insertion data
        if ($validated['type'] === 'score') {
            $refScore = RefScore::findOrFail($validated['ref_id']);
            $scoreValue = $refScore->score;
            $insertData['ref_score_id'] = $refScore->id;
        } else {
            $refPunish = RefPunishment::findOrFail($validated['ref_id']);
            $scoreValue = -$refPunish->score; // Make it negative to subtract from total
            $insertData['ref_punishment_id'] = $refPunish->id;
        }

        DB::beginTransaction();
        try {
            // 1. Insert detail
            $detailModel = null;
            if ($validated['corner'] === 'blue') {
                $detailModel = FightDetailJuryPointBlue::create($insertData);
            } else {
                $detailModel = FightDetailJuryPointYellow::create($insertData);
            }

            // Load relations to broadcast
            $detailModel->load(['score', 'punishment']);

            // 2. Update recap — use firstOrCreate so TBH (round 3) gets a row even if the
            //    API sync didn't return recap_jury_poin_round_add data.
            $recap = FightRecapJuryPoint::firstOrCreate(
                ['round_number' => $validated['round_number']],
                [
                    'jury_one_total_poin_blue' => 0, 'jury_one_total_poin_yellow' => 0,
                    'jury_two_total_poin_blue' => 0, 'jury_two_total_poin_yellow' => 0,
                    'jury_three_total_poin_blue' => 0, 'jury_three_total_poin_yellow' => 0,
                    'jury_four_total_poin_blue' => 0, 'jury_four_total_poin_yellow' => 0,
                ]
            );

            // Map jury_number to string word
            $juryMap = [1 => 'one', 2 => 'two', 3 => 'three', 4 => 'four'];
            $juryWord = $juryMap[$validated['jury_number']];
            $targetColumn = "jury_{$juryWord}_total_poin_{$validated['corner']}";

            $recap->$targetColumn += $scoreValue;

            $this->updateRecapWinner($recap, $juryWord, $validated['round_number'], $validated['jury_number']);

            $recap->save();

            // 3. Re-calculate Match's Total System Score and Update FightMatch
            $match = FightMatch::find($validated['partai_id']);
            if ($match) {
                $match->update([
                    'total_poin_yellow' => $this->calculateSecretaryValidatedTotal('yellow'),
                    'total_poin_blue' => $this->calculateSecretaryValidatedTotal('blue'),
                ]);
                broadcast(new ActiveMatchUpdated($match))->toOthers();
            }

            DB::commit();

            // 4. Broadcast Jury Recap Event
            broadcast(new JuryScoreUpdated(
                $validated['partai_id'],
                $validated['corner'],
                $validated['round_number'],
                $validated['jury_number'],
                $detailModel->toArray(),
                $recap ? $recap->toArray() : null
            ))->toOthers();

            return response()->json([
                'success' => true,
                'detail' => $detailModel,
                'recap' => $recap
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Delete an existing score or punishment
     */
    public function deleteScore(Request $request, $id)
    {
        $validated = $request->validate([
            'partai_id' => 'required|integer',
            'corner' => 'required|in:blue,yellow',
            'round_number' => 'required|integer|min:1|max:3',
            'jury_number' => 'required|integer|min:1|max:4',
        ]);

        DB::beginTransaction();
        try {
            $scoreValue = 0;
            $detailModel = null;

            if ($validated['corner'] === 'blue') {
                $detailModel = FightDetailJuryPointBlue::with(['score', 'punishment'])->findOrFail($id);
            } else {
                $detailModel = FightDetailJuryPointYellow::with(['score', 'punishment'])->findOrFail($id);
            }

            // Determine the value to subtract
            if ($detailModel->ref_score_id) {
                $scoreValue = $detailModel->score->score;
            } elseif ($detailModel->ref_punishment_id) {
                $scoreValue = -$detailModel->punishment->score; // Negative so -= reverses it
            }

            $detailModel->delete();

            // Update recap — use firstOrCreate so TBH (round 3) always has a row
            $recap = FightRecapJuryPoint::firstOrCreate(
                ['round_number' => $validated['round_number']],
                [
                    'jury_one_total_poin_blue' => 0, 'jury_one_total_poin_yellow' => 0,
                    'jury_two_total_poin_blue' => 0, 'jury_two_total_poin_yellow' => 0,
                    'jury_three_total_poin_blue' => 0, 'jury_three_total_poin_yellow' => 0,
                    'jury_four_total_poin_blue' => 0, 'jury_four_total_poin_yellow' => 0,
                ]
            );

            $juryMap = [1 => 'one', 2 => 'two', 3 => 'three', 4 => 'four'];
            $juryWord = $juryMap[$validated['jury_number']];
            $targetColumn = "jury_{$juryWord}_total_poin_{$validated['corner']}";

            $recap->$targetColumn -= $scoreValue;

            $this->updateRecapWinner($recap, $juryWord, $validated['round_number'], $validated['jury_number']);

            $recap->save();

            // Re-calculate Match's Total System Score and Update FightMatch
            $match = FightMatch::find($validated['partai_id']);
            if ($match) {
                $match->update([
                    'total_poin_yellow' => $this->calculateSecretaryValidatedTotal('yellow'),
                    'total_poin_blue' => $this->calculateSecretaryValidatedTotal('blue'),
                ]);
                broadcast(new ActiveMatchUpdated($match))->toOthers();
            }

            DB::commit();

            // Broadcast Event with null detail to signify deletion
            broadcast(new JuryScoreUpdated(
                $validated['partai_id'],
                $validated['corner'],
                $validated['round_number'],
                $validated['jury_number'],
                ['id' => $id, 'deleted' => true],
                $recap ? $recap->toArray() : null
            ))->toOthers();

            return response()->json([
                'success' => true,
                'recap' => $recap
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }
    private function updateRecapWinner($recap, $juryWord, $roundNumber, $juryNumber)
    {
        $blueTotal  = $recap->{"jury_{$juryWord}_total_poin_blue"};
        $yellowTotal = $recap->{"jury_{$juryWord}_total_poin_yellow"};

        if ($yellowTotal > $blueTotal) {
            $recap->{"jury_{$juryWord}_winner"} = 'yellow';
        } elseif ($blueTotal > $yellowTotal) {
            $recap->{"jury_{$juryWord}_winner"} = 'blue';
        } else {
            // Draw, check punishments
            $yellowPunishments = FightDetailJuryPointYellow::where('jury_number', $juryNumber)
                ->where('round_number', $roundNumber)
                ->whereNotNull('ref_punishment_id')
                ->with('punishment')
                ->get();
            $yellowPunishmentScore = $yellowPunishments->sum(function($item) {
                return $item->punishment ? $item->punishment->score : 0;
            });

            $bluePunishments = FightDetailJuryPointBlue::where('jury_number', $juryNumber)
                ->where('round_number', $roundNumber)
                ->whereNotNull('ref_punishment_id')
                ->with('punishment')
                ->get();
            $bluePunishmentScore = $bluePunishments->sum(function($item) {
                return $item->punishment ? $item->punishment->score : 0;
            });

            if ($yellowPunishmentScore > $bluePunishmentScore) {
                $recap->{"jury_{$juryWord}_winner"} = 'blue';
            } elseif ($bluePunishmentScore > $yellowPunishmentScore) {
                $recap->{"jury_{$juryWord}_winner"} = 'yellow';
            } else {
                $recap->{"jury_{$juryWord}_winner"} = 'draw';
            }
        }
    }

    /**
     * Calculates the validated total points for a given corner across all rounds.
     * The rule requires identical score inputs by at least 3 juries at the exact chronological insertion index.
     */
    private function calculateSecretaryValidatedTotal($corner)
    {
        $totalValidated = 0;

        // Loop over rounds 1 to 3
        for ($round = 1; $round <= 3; $round++) {
            $juryInputs = [1 => [], 2 => [], 3 => [], 4 => []];

            if ($corner === 'yellow') {
                $details = FightDetailJuryPointYellow::where('round_number', $round)
                    ->orderBy('id', 'asc')
                    ->with(['score', 'punishment'])
                    ->get();
            } else {
                $details = FightDetailJuryPointBlue::where('round_number', $round)
                    ->orderBy('id', 'asc')
                    ->with(['score', 'punishment'])
                    ->get();
            }

            // Distribute into 4 jury arrays based on insertion history order
            foreach ($details as $detail) {
                $jn = $detail->jury_number;
                if ($jn >= 1 && $jn <= 4) {
                    $identifier = $detail->ref_score_id ? "s:{$detail->ref_score_id}" : "p:{$detail->ref_punishment_id}";
                    
                    $val = 0;
                    if ($detail->ref_score_id && $detail->score) {
                        $val = $detail->score->score;
                    } elseif ($detail->ref_punishment_id && $detail->punishment) {
                        $val = -$detail->punishment->score; // Subtract punishment points
                    }
                    
                    $juryInputs[$jn][] = [
                        'id' => $identifier,
                        'val' => $val
                    ];
                }
            }

            // Evaluate dynamically index-by-index
            $maxLen = max(count($juryInputs[1]), count($juryInputs[2]), count($juryInputs[3]), count($juryInputs[4]));
            for ($i = 0; $i < $maxLen; $i++) {
                $freq = [];
                $valueMap = [];
                
                for ($j = 1; $j <= 4; $j++) {
                    if (isset($juryInputs[$j][$i])) {
                        $item = $juryInputs[$j][$i];
                        $id = $item['id'];
                        if (!isset($freq[$id])) {
                            $freq[$id] = 0;
                            $valueMap[$id] = $item['val'];
                        }
                        $freq[$id]++;
                    }
                }
                
                // If any button was identically pressed by >=3 juries at this chronological step
                foreach ($freq as $id => $count) {
                    if ($count >= 3) {
                        $totalValidated += $valueMap[$id];
                        break; 
                    }
                }
            }
        }

        return $totalValidated;
    }
}
