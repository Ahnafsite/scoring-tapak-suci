<?php

namespace App\Http\Controllers\Api;

use App\Events\SeniJuryScoreUpdated;
use App\Events\SeniMatchUpdated;
use App\Http\Controllers\Controller;
use App\Models\Arena;
use App\Models\SeniPool;
use App\Models\SeniSingleMatch;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SeniScoringController extends Controller
{
    /**
     * @return array<string, string|null>
     */
    private function headers(): array
    {
        return [
            'X-API-KEY' => env('API_KEY'),
            'Authorization' => 'Bearer '.env('API_KEY'),
            'Accept' => 'application/json',
        ];
    }

    private function baseUrl(): string
    {
        $apiUrl = rtrim((string) env('API_URL', 'http://127.0.0.1:8000/api'), '/');

        if (! preg_match('~^(?:f|ht)tps?://~i', $apiUrl)) {
            $apiUrl = 'http://'.$apiUrl;
        }

        return $apiUrl;
    }

    public function getSesi(string $gelanggangId)
    {
        $response = Http::withHeaders($this->headers())
            ->get($this->baseUrl().'/sesi/seni/'.$gelanggangId);

        return response()->json($response->json(), $response->status());
    }

    public function setupArena(Request $request)
    {
        $validated = $request->validate([
            'gelanggang_id' => ['required'],
            'sesi_seni_id' => ['required'],
            'championship_name' => ['nullable', 'string'],
            'arena_name' => ['nullable', 'string'],
        ]);

        $poolResponse = $this->fetchSeniPools((string) $validated['sesi_seni_id']);

        if (! $poolResponse instanceof Response) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil daftar pool seni dari server scoring.',
            ], 400);
        }

        $pools = $this->responseData($poolResponse);

        DB::transaction(function () use ($validated, $pools): void {
            $arena = Arena::firstOrCreate(['id' => 1]);
            $arena->update($validated);

            SeniSingleMatch::query()->delete();
            SeniPool::query()->delete();

            foreach ($pools as $pool) {
                $poolData = $this->mapPool($pool);

                if ($poolData === null) {
                    continue;
                }

                SeniPool::updateOrCreate(
                    ['no_pool_babak_id' => $poolData['no_pool_babak_id']],
                    $poolData
                );
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'Arena seni berhasil diset dan pool tersinkron.',
            'pools_count' => SeniPool::count(),
            'data' => SeniPool::orderBy('no_pool')->get(),
        ]);
    }

    public function syncPoolMatches(SeniPool $pool)
    {
        if ($this->hasLockedMatch()) {
            return response()->json([
                'success' => false,
                'message' => 'Tidak dapat mengganti pool saat ada partai seni yang berlangsung atau dijeda.',
            ], 422);
        }

        $response = Http::withHeaders($this->headers())
            ->get($this->baseUrl().'/partai-seni/'.$pool->no_pool_babak_id);

        if (! $response->successful()) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil partai seni dari server scoring.',
                'error' => $response->json() ?? $response->body(),
            ], $response->status());
        }

        $matches = $this->responseData($response);

        DB::transaction(function () use ($matches, $pool): void {
            SeniSingleMatch::query()->delete();

            foreach ($matches as $match) {
                SeniSingleMatch::create($this->mapSingleMatch($match, $pool, []));
            }
        });

        $matches = $pool->matches()->orderBy('no_order')->get();
        $this->broadcastSeniUpdate($matches->first(), $pool, 'pool_synced');

        return response()->json([
            'success' => true,
            'message' => 'Data partai seni pool berhasil disinkronkan.',
            'data' => $matches,
        ]);
    }

    public function activateMatch(SeniSingleMatch $match)
    {
        if ($this->hasLockedMatch($match)) {
            return response()->json([
                'success' => false,
                'message' => 'Tidak dapat mengganti partai saat ada partai seni yang berlangsung atau dijeda.',
            ], 422);
        }

        $detail = $this->fetchSeniMatchDetail($match->bkp_id);

        if ($detail === []) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil detail partai seni dari server scoring.',
            ], 400);
        }

        DB::transaction(function () use ($match, $detail): void {
            SeniSingleMatch::query()->update(['is_active' => false]);

            $match->update(array_merge(
                $this->mapDetailTotals($detail),
                ['is_active' => true]
            ));

            $match->juryScores()->delete();
            $match->juryPunishments()->delete();

            foreach ($this->mapJuryScores($detail) as $juryScore) {
                $match->juryScores()->create($juryScore);
            }

            foreach ($this->mapJuryPunishments($detail) as $juryPunishment) {
                $match->juryPunishments()->create($juryPunishment);
            }
        });

        $freshMatch = $this->freshMatchWithSecretaryScores($match);
        $pool = $this->poolForMatch($freshMatch);
        $this->broadcastSeniUpdate($freshMatch, $pool, 'match_activated');

        return response()->json([
            'success' => true,
            'message' => 'Data partai seni berhasil dimuat.',
            'data' => $freshMatch,
            'matches' => SeniSingleMatch::orderBy('no_order')->get(),
            'jury_scores' => $freshMatch->juryScores,
            'jury_punishments' => $freshMatch->juryPunishments,
            'pool' => $pool,
        ]);
    }

    public function updateMatchStatus(Request $request, SeniSingleMatch $match)
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(['not_started', 'ongoing', 'paused', 'done'])],
            'sync_source' => ['sometimes', 'boolean'],
            'time' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ]);

        if (! $match->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'Pilih partai aktif terlebih dahulu.',
            ], 422);
        }

        $updates = ['status' => $validated['status']];

        if (array_key_exists('time', $validated)) {
            $updates['time'] = $validated['time'];
        }

        DB::transaction(function () use ($match, $updates, $validated): void {
            $lockedMatch = SeniSingleMatch::query()
                ->whereKey($match->id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedMatch->update($updates);

            if ($validated['status'] === 'paused') {
                $this->recalculateAcceptedJuryScoresAndMatchTotals($lockedMatch);
            }
        });

        $freshMatch = $this->freshMatchWithSecretaryScores($match);
        $pool = $this->poolForMatch($freshMatch);
        $this->broadcastSeniUpdate($freshMatch, $pool, 'status_updated');

        if ($validated['sync_source'] ?? true) {
            $statusResponse = $this->updatePartaiStatusOnSource($freshMatch, $validated['status']);

            if (! $statusResponse->successful()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Status lokal diperbarui, tetapi gagal mengirim status ke server scoring.',
                    'error' => $statusResponse->json() ?? $statusResponse->body(),
                    'data' => $freshMatch,
                    'matches' => SeniSingleMatch::orderBy('no_order')->get(),
                    'pool' => $pool,
                ], $statusResponse->status());
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Status partai seni berhasil diperbarui.',
            'data' => $freshMatch,
            'matches' => SeniSingleMatch::orderBy('no_order')->get(),
            'pool' => $pool,
        ]);
    }

    public function saveMatchDetail(SeniSingleMatch $match)
    {
        if (! $match->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'Pilih partai aktif terlebih dahulu.',
            ], 422);
        }

        $detailResponse = $this->saveSeniMatchDetailToSource($match->fresh(['juryScores', 'juryPunishments']));

        if (! $detailResponse->successful()) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal menyimpan detail partai seni ke server scoring.',
                'error' => $detailResponse->json() ?? $detailResponse->body(),
                'payload' => $this->sourceDetailPayload($match->fresh(['juryScores', 'juryPunishments'])),
            ], $detailResponse->status());
        }

        $match->update(['status' => 'done']);

        $freshMatch = $this->freshMatchWithSecretaryScores($match);
        $pool = $this->poolForMatch($freshMatch);
        $this->broadcastSeniUpdate($freshMatch, $pool, 'status_updated');

        $statusResponse = $this->updatePartaiStatusOnSource($freshMatch, 'done');

        if (! $statusResponse->successful()) {
            return response()->json([
                'success' => false,
                'message' => 'Detail tersimpan dan status lokal selesai, tetapi gagal mengirim status selesai ke server scoring.',
                'error' => $statusResponse->json() ?? $statusResponse->body(),
                'data' => $freshMatch,
                'matches' => SeniSingleMatch::orderBy('no_order')->get(),
                'pool' => $pool,
            ], $statusResponse->status());
        }

        return response()->json([
            'success' => true,
            'message' => 'Detail partai seni berhasil disimpan.',
            'data' => $freshMatch,
            'matches' => SeniSingleMatch::orderBy('no_order')->get(),
            'pool' => $pool,
        ]);
    }

    public function disqualifyMatch(SeniSingleMatch $match)
    {
        if (! $match->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'Pilih partai aktif terlebih dahulu.',
            ], 422);
        }

        DB::transaction(function () use ($match): void {
            $match->forceFill([
                'status' => 'done',
                'is_disqualified' => true,
                'is_passed' => false,
            ])->save();
        });

        $freshMatch = $this->freshMatchWithSecretaryScores($match);
        $pool = $this->poolForMatch($freshMatch);

        $detailResponse = $this->saveSeniMatchDetailToSource($freshMatch);

        if (! $detailResponse->successful()) {
            return response()->json([
                'success' => false,
                'message' => 'Partai seni lokal sudah didiskualifikasi, tetapi gagal menyimpan diskualifikasi ke server scoring.',
                'error' => $detailResponse->json() ?? $detailResponse->body(),
                'payload' => $this->sourceDetailPayload($freshMatch),
                'data' => $freshMatch,
                'matches' => SeniSingleMatch::orderBy('no_order')->get(),
                'pool' => $pool,
            ], $detailResponse->status());
        }

        $this->broadcastSeniUpdate($freshMatch, $pool, 'status_updated');

        $statusResponse = $this->updatePartaiStatusOnSource($freshMatch, 'done');

        if (! $statusResponse->successful()) {
            return response()->json([
                'success' => false,
                'message' => 'Diskualifikasi lokal tersimpan, tetapi gagal mengirim status selesai ke server scoring.',
                'error' => $statusResponse->json() ?? $statusResponse->body(),
                'data' => $freshMatch,
                'matches' => SeniSingleMatch::orderBy('no_order')->get(),
                'pool' => $pool,
            ], $statusResponse->status());
        }

        return response()->json([
            'success' => true,
            'message' => 'Partai seni berhasil didiskualifikasi.',
            'data' => $freshMatch,
            'matches' => SeniSingleMatch::orderBy('no_order')->get(),
            'pool' => $pool,
        ]);
    }

    public function cancelDisqualification(SeniSingleMatch $match)
    {
        if (! $match->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'Pilih partai aktif terlebih dahulu.',
            ], 422);
        }

        $match->forceFill([
            'is_disqualified' => false,
        ])->save();

        $freshMatch = $this->freshMatchWithSecretaryScores($match);
        $pool = $this->poolForMatch($freshMatch);

        $detailResponse = $this->saveSeniMatchDetailToSource($freshMatch);

        if (! $detailResponse->successful()) {
            return response()->json([
                'success' => false,
                'message' => 'Pembatalan diskualifikasi lokal sudah tersimpan, tetapi gagal menyimpan ke server scoring.',
                'error' => $detailResponse->json() ?? $detailResponse->body(),
                'payload' => $this->sourceDetailPayload($freshMatch),
                'data' => $freshMatch,
                'matches' => SeniSingleMatch::orderBy('no_order')->get(),
                'pool' => $pool,
            ], $detailResponse->status());
        }

        $this->broadcastSeniUpdate($freshMatch, $pool, 'status_updated');

        return response()->json([
            'success' => true,
            'message' => 'Diskualifikasi partai seni berhasil dibatalkan.',
            'data' => $freshMatch,
            'matches' => SeniSingleMatch::orderBy('no_order')->get(),
            'pool' => $pool,
        ]);
    }

    public function resetMatch(SeniSingleMatch $match)
    {
        DB::transaction(function () use ($match): void {
            $match->juryScores()->delete();
            $match->juryPunishments()->delete();
            $match->update([
                'status' => 'not_started',
                'is_disqualified' => false,
                'is_passed' => false,
                'total_score' => null,
                'total_wiraga' => null,
                'total_wirasa' => null,
                'total_wirama' => null,
                'total_kualitas_teknik' => null,
                'total_kuantitas_teknik' => null,
                'total_ketangkasan' => null,
                'total_stamina' => null,
                'total_kemantapan' => null,
                'total_musik' => null,
                'total_punishment' => null,
                'time' => null,
                'rank' => null,
            ]);
        });

        $freshMatch = $this->freshMatchWithSecretaryScores($match);
        $pool = $this->poolForMatch($freshMatch);

        $detailResponse = $this->saveSeniMatchDetailToSource($freshMatch, true);

        if (! $detailResponse->successful()) {
            return response()->json([
                'success' => false,
                'message' => 'Partai seni lokal sudah direset, tetapi gagal menyimpan reset ke server scoring.',
                'error' => $detailResponse->json() ?? $detailResponse->body(),
                'payload' => $this->sourceDetailPayload($freshMatch, true),
                'data' => $freshMatch,
                'matches' => SeniSingleMatch::orderBy('no_order')->get(),
                'pool' => $pool,
            ], $detailResponse->status());
        }

        $statusResponse = $this->updatePartaiStatusOnSource($freshMatch, 'not_started');

        if (! $statusResponse->successful()) {
            return response()->json([
                'success' => false,
                'message' => 'Partai seni lokal sudah direset, tetapi gagal mengirim status reset ke server scoring.',
                'error' => $statusResponse->json() ?? $statusResponse->body(),
                'data' => $freshMatch,
                'matches' => SeniSingleMatch::orderBy('no_order')->get(),
                'pool' => $pool,
            ], $statusResponse->status());
        }

        $this->broadcastSeniUpdate($freshMatch, $pool, 'match_reset');

        return response()->json([
            'success' => true,
            'message' => 'Partai seni berhasil direset.',
            'data' => $freshMatch,
            'matches' => SeniSingleMatch::orderBy('no_order')->get(),
            'pool' => $pool,
        ]);
    }

    public function storeJuryScore(Request $request, SeniSingleMatch $match)
    {
        $validated = $request->validate([
            'jury_number' => ['required', 'integer', 'min:1', 'max:5'],
            'type' => ['required', Rule::in(['score', 'punishment'])],
            'field' => ['required', 'string'],
            'value' => ['required', 'numeric', 'min:0'],
        ]);

        $field = $validated['field'];
        $juryNumber = (int) $validated['jury_number'];

        if ($validated['type'] === 'score' && ! in_array($field, $this->scoreColumnsForMatch($match), true)) {
            return response()->json([
                'success' => false,
                'message' => 'Kriteria nilai tidak tersedia untuk partai ini.',
            ], 422);
        }

        if ($validated['type'] === 'punishment' && ! in_array($field, $this->punishmentColumnsForMatch($match), true)) {
            return response()->json([
                'success' => false,
                'message' => 'Hukuman tidak tersedia untuk partai ini.',
            ], 422);
        }

        $result = DB::transaction(function () use ($match, $validated, $field, $juryNumber): array {
            $lockedMatch = SeniSingleMatch::query()
                ->whereKey($match->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $lockedMatch->is_active || $lockedMatch->status !== 'ongoing') {
                throw ValidationException::withMessages([
                    'match' => 'Penilaian hanya dapat disimpan saat partai sedang berlangsung.',
                ]);
            }

            $score = $lockedMatch->juryScores()->firstOrNew([
                'jury_number' => $juryNumber,
            ]);
            $punishment = $lockedMatch->juryPunishments()->firstOrNew([
                'jury_number' => $juryNumber,
            ]);

            if (! $score->exists) {
                $score->fill($this->defaultScoreValuesForMatch($lockedMatch));
            }

            if ($validated['type'] === 'score') {
                $score->{$field} = $validated['value'];
            } else {
                $punishment->{$field} = $validated['value'];
                $punishment->save();
            }

            $score->total_score = $this->calculateJuryTotalScore($lockedMatch, $score, $punishment);
            $score->save();

            $this->recalculateAcceptedJuryScoresAndMatchTotals($lockedMatch);

            $freshMatch = $this->freshMatchWithSecretaryScores($lockedMatch);
            $freshScore = $score->fresh();
            $freshPunishment = $punishment->exists ? $punishment->fresh() : null;
            $pool = $this->poolForMatch($freshMatch);

            return [
                'match' => $freshMatch,
                'score' => $freshScore,
                'punishment' => $freshPunishment,
                'pool' => $pool,
            ];
        });

        $this->broadcastSeniJuryScoreUpdate(
            $result['match'],
            $result['score'],
            $result['punishment'],
            $validated['type'],
            $field,
            $juryNumber
        );

        return response()->json([
            'success' => true,
            'message' => 'Nilai juri seni berhasil disimpan.',
            'data' => $result['match'],
            'score' => $result['score'],
            'punishment' => $result['punishment'],
            'matches' => SeniSingleMatch::orderBy('no_order')->get(),
            'pool' => $result['pool'],
        ]);
    }

    public function decideWinners(Request $request)
    {
        $validated = $request->validate([
            'passed_count' => ['required', 'integer', 'min:0'],
        ]);

        $matches = SeniSingleMatch::query()->orderBy('no_order')->get();

        if ($matches->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Belum ada partai seni untuk diputuskan.',
            ], 422);
        }

        if ($matches->contains(fn (SeniSingleMatch $match): bool => $match->status !== 'done')) {
            return response()->json([
                'success' => false,
                'message' => 'Keputusan hanya dapat dibuat setelah semua partai selesai.',
            ], 422);
        }

        $passedCount = min((int) $validated['passed_count'], $matches->count());
        $rankedMatches = $this->rankSeniMatches($matches);

        DB::transaction(function () use ($rankedMatches, $passedCount): void {
            $rankedMatches->values()->each(function (SeniSingleMatch $match, int $index) use ($passedCount): void {
                $rank = $index + 1;

                $match->forceFill([
                    'rank' => $rank,
                    'is_passed' => ! $match->is_disqualified && $rank <= $passedCount,
                ])->save();
            });
        });

        $matches = $this->rankedSeniMatches();
        $pool = $this->poolForMatch($matches->first());
        $this->broadcastSeniUpdate($matches->first(), $pool, 'rank_updated');

        $poolResultResponse = $this->savePoolResultToSource($matches);

        if (! $poolResultResponse->successful()) {
            return response()->json([
                'success' => false,
                'message' => 'Keputusan lokal sudah tersimpan, tetapi gagal menyimpan hasil pool ke server scoring.',
                'error' => $poolResultResponse->json() ?? $poolResultResponse->body(),
                'payload' => $this->sourcePoolResultPayload($matches),
                'data' => $matches,
                'matches' => $matches,
                'pool' => $pool,
            ], $poolResultResponse->status());
        }

        return response()->json([
            'success' => true,
            'message' => 'Keputusan pemenang seni berhasil dibuat.',
            'data' => $matches,
            'matches' => $matches,
            'pool' => $pool,
        ]);
    }

    public function reorderRanks(Request $request)
    {
        $validated = $request->validate([
            'ordered_match_ids' => ['required', 'array', 'min:1'],
            'ordered_match_ids.*' => ['required', 'integer', 'distinct', 'exists:seni_single_matches,id'],
            'passed_count' => ['sometimes', 'integer', 'min:0'],
        ]);

        $matches = SeniSingleMatch::query()
            ->whereIn('id', $validated['ordered_match_ids'])
            ->get()
            ->keyBy('id');

        if ($matches->count() !== SeniSingleMatch::count()) {
            return response()->json([
                'success' => false,
                'message' => 'Urutan rank harus berisi semua partai seni.',
            ], 422);
        }

        if ($matches->contains(fn (SeniSingleMatch $match): bool => $match->status !== 'done')) {
            return response()->json([
                'success' => false,
                'message' => 'Rank hanya dapat diubah setelah semua partai selesai.',
            ], 422);
        }

        $passedCount = array_key_exists('passed_count', $validated)
            ? min((int) $validated['passed_count'], $matches->count())
            : SeniSingleMatch::where('is_passed', true)->count();

        $orderedMatches = collect($validated['ordered_match_ids'])
            ->map(fn (int $matchId): SeniSingleMatch => $matches->get($matchId))
            ->sortBy(fn (SeniSingleMatch $match): int => $match->is_disqualified ? 1 : 0)
            ->values();

        DB::transaction(function () use ($orderedMatches, $passedCount): void {
            $orderedMatches->each(function (SeniSingleMatch $match, int $index) use ($passedCount): void {
                $rank = $index + 1;

                $match->forceFill([
                    'rank' => $rank,
                    'is_passed' => ! $match->is_disqualified && $rank <= $passedCount,
                ])->save();
            });
        });

        $matches = $this->rankedSeniMatches();
        $pool = $this->poolForMatch($matches->first());
        $this->broadcastSeniUpdate($matches->first(), $pool, 'rank_updated');

        $poolResultResponse = $this->savePoolResultToSource($matches);

        if (! $poolResultResponse->successful()) {
            return response()->json([
                'success' => false,
                'message' => 'Urutan rank lokal sudah tersimpan, tetapi gagal menyimpan hasil pool ke server scoring.',
                'error' => $poolResultResponse->json() ?? $poolResultResponse->body(),
                'payload' => $this->sourcePoolResultPayload($matches),
                'data' => $matches,
                'matches' => $matches,
                'pool' => $pool,
            ], $poolResultResponse->status());
        }

        return response()->json([
            'success' => true,
            'message' => 'Urutan rank seni berhasil diperbarui.',
            'data' => $matches,
            'matches' => $matches,
            'pool' => $pool,
        ]);
    }

    private function fetchSeniPools(string $sesiSeniId): ?Response
    {
        $paths = [
            "/partai-seni/pools/{$sesiSeniId}",
            "/partai-seni/pool/{$sesiSeniId}",
            "/pool-seni/{$sesiSeniId}",
            "/partai-seni/list-pool/{$sesiSeniId}",
            "/partai-seni/{$sesiSeniId}",
        ];

        foreach ($paths as $path) {
            $response = Http::withHeaders($this->headers())->get($this->baseUrl().$path);

            if (! $response->successful()) {
                continue;
            }

            $mappedPools = collect($this->responseData($response))
                ->map(fn (array $pool): ?array => $this->mapPool($pool))
                ->filter();

            if ($mappedPools->isNotEmpty()) {
                return $response;
            }
        }

        return null;
    }

    private function hasLockedMatch(?SeniSingleMatch $allowedMatch = null): bool
    {
        return SeniSingleMatch::query()
            ->whereIn('status', ['ongoing', 'paused'])
            ->when(
                $allowedMatch,
                fn ($query) => $query->whereKeyNot($allowedMatch->id)
            )
            ->exists();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function responseData(Response $response): array
    {
        $json = $response->json();
        $data = $json['data'] ?? $json;

        return is_array($data) ? $data : [];
    }

    /**
     * @param  array<string, mixed>  $pool
     * @return array<string, mixed>|null
     */
    private function mapPool(array $pool): ?array
    {
        if (array_key_exists('bkp_id', $pool) || array_key_exists('partai_senis_id', $pool)) {
            return null;
        }

        $noPoolBabakId = $pool['no_pool_babak_id']
            ?? $pool['no_pool_babaks_id']
            ?? $pool['no_pool_babak']
            ?? $pool['id']
            ?? null;

        if ($noPoolBabakId === null) {
            return null;
        }

        return [
            'no_pool_babak_id' => (int) $noPoolBabakId,
            'round_match' => $pool['round_match'] ?? $pool['match_round'] ?? $pool['babak'] ?? null,
            'group' => $pool['group'] ?? $pool['golongan'] ?? $pool['kelas'] ?? null,
            'category' => $pool['category'] ?? $pool['kategori'] ?? null,
            'no_pool' => $pool['no_pool'] ?? $pool['pool'] ?? $pool['pool_number'] ?? null,
        ];
    }

    private function fetchSeniMatchDetail(mixed $bkpId): array
    {
        if (! $bkpId) {
            return [];
        }

        $response = Http::withHeaders($this->headers())
            ->get($this->baseUrl().'/partai-seni/detail-partai-seni-ts/'.$bkpId);

        if (! $response->successful()) {
            return [];
        }

        $data = $response->json('data');

        return is_array($data) ? $data : [];
    }

    private function updatePartaiStatusOnSource(SeniSingleMatch $match, string $status): Response
    {
        return Http::withHeaders($this->headers())
            ->post($this->baseUrl().'/partai-seni/partai-status/'.$match->bkp_id, [
                'status' => $status,
            ]);
    }

    private function saveSeniMatchDetailToSource(SeniSingleMatch $match, bool $isReset = false): Response
    {
        return Http::withHeaders($this->headers())
            ->post($this->baseUrl().'/partai-seni/detail-partai-seni-ts/'.$match->bkp_id, $this->sourceDetailPayload($match, $isReset));
    }

    private function savePoolResultToSource(Collection $matches): Response
    {
        return Http::withHeaders($this->headers())
            ->post(
                $this->baseUrl().'/partai-seni/pool-result/'.$matches->first()?->no_pool_babak_id,
                $this->sourcePoolResultPayload($matches)
            );
    }

    /**
     * @return array<int, array{bkp_id: int, is_passed: bool, rank: int|null}>
     */
    private function sourcePoolResultPayload(Collection $matches): array
    {
        return $matches
            ->sortBy('rank')
            ->values()
            ->map(fn (SeniSingleMatch $match): array => [
                'bkp_id' => (int) $match->bkp_id,
                'is_passed' => (bool) $match->is_passed,
                'rank' => $match->rank === null ? null : (int) $match->rank,
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function sourceDetailPayload(SeniSingleMatch $match, bool $isReset = false): array
    {
        $match->loadMissing(['juryScores', 'juryPunishments']);

        if ($isReset) {
            return $this->sourceResetDetailPayload();
        }

        return [
            'total_score' => $match->total_score,
            'total_punishment' => $match->total_punishment,
            'rank' => $match->rank,
            'is_passed' => $match->is_passed ? 1 : 0,
            'is_disqualified' => $match->is_disqualified ? 1 : 0,
            'is_disqualification' => $match->is_disqualified ? 1 : 0,
            'time' => $match->time,
            'total_wiraga' => $match->total_wiraga,
            'total_wirasa' => $match->total_wirasa,
            'total_wirama' => $match->total_wirama,
            'total_kualitas_teknik' => $match->total_kualitas_teknik,
            'total_kuantitas_teknik' => $match->total_kuantitas_teknik,
            'total_ketangkasan' => $match->total_ketangkasan,
            'total_stamina' => $match->total_stamina,
            'total_kemantapan' => $match->total_kemantapan,
            'total_musik' => $match->total_musik,
            'tgr_jury_scores' => $this->sourceJuryScores($match),
            'tgr_jury_punishments' => $this->sourceJuryPunishments($match),
            'tgr_jury_total_scores' => $match->juryScores
                ->map(fn ($score): array => [
                    'jury_number' => $score->jury_number,
                    'total_score' => $score->total_score,
                    'is_accepted' => $score->is_accepted ? 1 : 0,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sourceResetDetailPayload(): array
    {
        return [
            'reset_scores' => true,
            'delete_scores' => true,
            'clear_scores' => true,
            'total_score' => '0.000',
            'total_nilai' => '0.000',
            'total_punishment' => '0.000',
            'total_hukuman' => '0.000',
            'rank' => 0,
            'ranking' => 0,
            'is_passed' => 0,
            'lanjut' => 0,
            'is_disqualified' => 0,
            'is_disqualification' => 0,
            'time' => 0,
            'waktu_tampil' => 0,
            'deviasi' => '0.000',
            'total_wiraga' => '0.000',
            'total_wirasa' => '0.000',
            'total_wirama' => '0.000',
            'total_kualitas_teknik' => '0.000',
            'total_kuantitas_teknik' => '0.000',
            'total_ketangkasan' => '0.000',
            'total_stamina' => '0.000',
            'total_kemantapan' => '0.000',
            'total_musik' => '0.000',
            'tgr_jury_scores' => [],
            'tgr_jury_punishments' => [],
            'tgr_jury_total_scores' => [],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function sourceJuryScores(SeniSingleMatch $match): array
    {
        $scoreColumns = $this->scoreColumnsForMatch($match);

        return $match->juryScores
            ->flatMap(function ($juryScore) use ($scoreColumns): array {
                return collect($scoreColumns)
                    ->filter(fn (string $column): bool => $juryScore->{$column} !== null)
                    ->map(fn (string $column): array => [
                        'jury_number' => $juryScore->jury_number,
                        'score' => $juryScore->{$column},
                        'ref_tgr_score' => $column,
                    ])
                    ->values()
                    ->all();
            })
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function sourceJuryPunishments(SeniSingleMatch $match): array
    {
        $punishmentColumns = $this->punishmentColumnsForMatch($match);

        return $match->juryPunishments
            ->map(function ($juryPunishment) use ($match, $punishmentColumns): array {
                $row = ['jury_number' => $juryPunishment->jury_number];

                foreach ($punishmentColumns as $column) {
                    if ($juryPunishment->{$column} !== null) {
                        $row[$this->sourcePunishmentKey($match, $column)] = $juryPunishment->{$column};
                    }
                }

                return $row;
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $match
     */
    private function athleteNames(array $match): string
    {
        $athletes = $match['atlets']
            ?? $match['atletes']
            ?? $match['athletes']
            ?? $match['athlete']
            ?? $match['participant_name']
            ?? $match['pesilat']
            ?? null;

        if (is_array($athletes)) {
            $athletes = collect($athletes)
                ->map(function (mixed $athlete): ?string {
                    if (is_array($athlete)) {
                        return $athlete['name']
                            ?? $athlete['nama']
                            ?? $athlete['atlet']
                            ?? implode(' ', Arr::flatten($athlete));
                    }

                    return is_scalar($athlete) ? (string) $athlete : null;
                })
                ->filter(fn (?string $athlete): bool => filled($athlete))
                ->implode(', ');
        }

        return filled($athletes) ? (string) $athletes : '-';
    }

    /**
     * @param  array<string, mixed>  $detail
     * @return array<string, mixed>
     */
    private function mapDetailTotals(array $detail): array
    {
        return [
            'total_score' => $detail['total_score'] ?? null,
            'total_wiraga' => $detail['total_wiraga'] ?? null,
            'total_wirasa' => $detail['total_wirasa'] ?? null,
            'total_wirama' => $detail['total_wirama'] ?? null,
            'total_kualitas_teknik' => $detail['total_kualitas_teknik'] ?? null,
            'total_kuantitas_teknik' => $detail['total_kuantitas_teknik'] ?? null,
            'total_ketangkasan' => $detail['total_ketangkasan'] ?? $detail['total_ketangkatasan'] ?? null,
            'total_stamina' => $detail['total_stamina'] ?? null,
            'total_kemantapan' => $detail['total_kemantapan'] ?? null,
            'total_musik' => $detail['total_musik'] ?? null,
            'total_punishment' => $detail['total_punishment'] ?? null,
            'is_passed' => (bool) ($detail['is_passed'] ?? false),
            'is_disqualified' => (bool) ($detail['is_disqualified'] ?? false),
            'time' => $detail['time'] ?? null,
            'rank' => $detail['rank'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $detail
     * @return array<int, array<string, mixed>>
     */
    private function mapJuryScores(array $detail): array
    {
        $scores = collect($detail['tgr_jury_scores'] ?? $detail['seni_jury_scores'] ?? $detail['jury_scores'] ?? []);
        $totals = collect($detail['tgr_jury_total_scores'] ?? $detail['seni_jury_total_scores'] ?? $detail['jury_total_scores'] ?? [])
            ->keyBy('jury_number');

        $juryNumbers = $scores
            ->pluck('jury_number')
            ->merge($totals->keys())
            ->filter()
            ->unique()
            ->values();

        $scoreColumns = $this->scoreColumns();

        return $juryNumbers
            ->map(function (mixed $juryNumber) use ($scores, $totals, $scoreColumns): array {
                $juryScores = $scores->where('jury_number', $juryNumber);
                $total = $totals->get($juryNumber, []);

                $data = [
                    'jury_number' => (int) $juryNumber,
                    'is_accepted' => (bool) ($total['is_accepted'] ?? false),
                ];

                foreach ($juryScores as $score) {
                    $column = $this->scoreColumn($score['ref_tgr_score'] ?? $score['ref_seni_score'] ?? $score['ref_score'] ?? null);

                    if ($column) {
                        $data[$column] = $score['score'] ?? null;

                        continue;
                    }

                    foreach ($scoreColumns as $scoreColumn) {
                        if (array_key_exists($scoreColumn, $score)) {
                            $data[$scoreColumn] = $score[$scoreColumn];
                        }
                    }
                }

                $data['total_score'] = $total['total_score'] ?? collect($scoreColumns)
                    ->sum(fn (string $scoreColumn): float => (float) ($data[$scoreColumn] ?? 0));

                return $data;
            })
            ->all();
    }

    /**
     * @param  array<string, mixed>  $detail
     * @return array<int, array<string, mixed>>
     */
    private function mapJuryPunishments(array $detail): array
    {
        $punishments = collect($detail['tgr_jury_punishments'] ?? $detail['seni_jury_punishments'] ?? $detail['jury_punishments'] ?? []);
        $scores = collect($detail['tgr_jury_scores'] ?? $detail['seni_jury_scores'] ?? $detail['jury_scores'] ?? []);
        $punishmentColumns = $this->punishmentColumns();
        $sourceRows = $scores
            ->filter(fn (array $score): bool => collect($punishmentColumns)->contains(
                fn (string $column): bool => $this->punishmentValue($score, $column) !== null
            ))
            ->merge($punishments);

        $data = [];

        foreach ($sourceRows as $punishment) {
            $juryNumber = $punishment['jury_number'] ?? null;

            if (! $juryNumber) {
                continue;
            }

            $data[$juryNumber] ??= ['jury_number' => (int) $juryNumber];

            foreach ($punishmentColumns as $column) {
                $value = $this->punishmentValue($punishment, $column);

                if ($value !== null) {
                    $data[$juryNumber][$column] = $value;
                }
            }
        }

        ksort($data);

        return array_values($data);
    }

    /**
     * @return array<int, string>
     */
    private function scoreColumns(): array
    {
        return [
            'wiraga',
            'wirasa',
            'wirama',
            'kualitas_teknik',
            'kuantitas_teknik',
            'ketangkasan',
            'stamina',
            'kemantapan',
            'musik',
        ];
    }

    /**
     * @return array<int, string>
     */
    private function scoreColumnsForMatch(SeniSingleMatch $match): array
    {
        if ($this->isTechniqueMatch($match)) {
            return [
                'kualitas_teknik',
                'kuantitas_teknik',
                'ketangkasan',
                'stamina',
                'kemantapan',
                'musik',
            ];
        }

        return [
            'wiraga',
            'wirasa',
            'wirama',
        ];
    }

    /**
     * @return array<int, string>
     */
    private function punishmentColumns(): array
    {
        return [
            'waktu',
            'keluar_garis',
            'senjata_jatuh_atau_tidak_sesuai_deskripsi',
            'senjata_tidak_jatuh_atau_tidak_sesuai_deskripsi',
            'akeseoris_jatuh',
        ];
    }

    /**
     * @return array<int, string>
     */
    private function punishmentColumnsForMatch(SeniSingleMatch $match): array
    {
        if ($this->isTechniqueMatch($match)) {
            return [
                'waktu',
                'keluar_garis',
                'senjata_jatuh_atau_tidak_sesuai_deskripsi',
                'senjata_tidak_jatuh_atau_tidak_sesuai_deskripsi',
            ];
        }

        return [
            'waktu',
            'keluar_garis',
            'senjata_jatuh_atau_tidak_sesuai_deskripsi',
            'akeseoris_jatuh',
        ];
    }

    /**
     * @return array<string, int>
     */
    private function defaultScoreValuesForMatch(SeniSingleMatch $match): array
    {
        if ($this->isTechniqueMatch($match)) {
            return [
                'kualitas_teknik' => 0,
                'kuantitas_teknik' => 0,
                'ketangkasan' => 0,
                'stamina' => 0,
                'kemantapan' => 0,
                'musik' => 0,
            ];
        }

        return [
            'wiraga' => 0,
            'wirasa' => 0,
            'wirama' => 0,
        ];
    }

    private function calculateJuryTotalScore(SeniSingleMatch $match, $score, $punishment): float
    {
        $scoreTotal = collect($this->scoreColumnsForMatch($match))
            ->sum(fn (string $column): float => (float) ($score->{$column} ?? 0));

        $punishmentTotal = collect($this->punishmentColumnsForMatch($match))
            ->sum(fn (string $column): float => (float) ($punishment->{$column} ?? 0));

        return $scoreTotal - $punishmentTotal;
    }

    private function recalculateAcceptedJuryScoresAndMatchTotals(SeniSingleMatch $match): void
    {
        $scores = $match->juryScores()->get();

        if ($scores->isEmpty()) {
            $this->updateMatchScoreTotals($match, collect(), collect());

            return;
        }

        $orderedScores = $scores
            ->sortBy([
                ['total_score', 'asc'],
                ['jury_number', 'asc'],
            ])
            ->values();
        $acceptedScores = $orderedScores;

        if ($orderedScores->count() > 3) {
            $acceptedScores = $orderedScores
                ->slice(1, $orderedScores->count() - 2)
                ->values();
        }

        $acceptedScoreIds = $acceptedScores->pluck('id');

        foreach ($scores as $score) {
            $isAccepted = $acceptedScoreIds->contains($score->id);

            if ((bool) $score->is_accepted !== $isAccepted) {
                $score->forceFill(['is_accepted' => $isAccepted])->save();
            }
        }

        $acceptedJuryNumbers = $acceptedScores->pluck('jury_number');
        $acceptedPunishments = $match->juryPunishments()
            ->whereIn('jury_number', $acceptedJuryNumbers)
            ->get();

        $this->updateMatchScoreTotals($match, $acceptedScores, $acceptedPunishments);
    }

    private function updateMatchScoreTotals(SeniSingleMatch $match, $acceptedScores, $acceptedPunishments): void
    {
        $updates = [
            'total_score' => $acceptedScores->sum(fn ($score): float => (float) ($score->total_score ?? 0)),
            'total_wiraga' => null,
            'total_wirasa' => null,
            'total_wirama' => null,
            'total_kualitas_teknik' => null,
            'total_kuantitas_teknik' => null,
            'total_ketangkasan' => null,
            'total_stamina' => null,
            'total_kemantapan' => null,
            'total_musik' => null,
            'total_punishment' => $acceptedPunishments->sum(function ($punishment) use ($match): float {
                return collect($this->punishmentColumnsForMatch($match))
                    ->sum(fn (string $column): float => (float) ($punishment->{$column} ?? 0));
            }),
        ];

        foreach ($this->scoreColumnsForMatch($match) as $column) {
            $updates["total_{$column}"] = $acceptedScores
                ->sum(fn ($score): float => (float) ($score->{$column} ?? 0));
        }

        $match->forceFill($updates)->save();
    }

    private function rankedSeniMatches()
    {
        return SeniSingleMatch::query()
            ->orderByRaw('rank is null')
            ->orderBy('rank')
            ->orderBy('atletes')
            ->get();
    }

    private function rankSeniMatches($matches)
    {
        return $matches
            ->sort(function (SeniSingleMatch $first, SeniSingleMatch $second): int {
                foreach ($this->rankingComparisons($first, $second) as [$firstValue, $secondValue, $direction]) {
                    $comparison = $direction === 'asc'
                        ? $firstValue <=> $secondValue
                        : $secondValue <=> $firstValue;

                    if ($comparison !== 0) {
                        return $comparison;
                    }
                }

                return str($first->atletes ?? '')->lower()->toString()
                    <=> str($second->atletes ?? '')->lower()->toString();
            })
            ->values();
    }

    /**
     * @return array<int, array{0: float, 1: float, 2: string}>
     */
    private function rankingComparisons(SeniSingleMatch $first, SeniSingleMatch $second): array
    {
        $comparisons = [
            [(int) $first->is_disqualified, (int) $second->is_disqualified, 'asc'],
            [$this->rankValue($first, 'total_score'), $this->rankValue($second, 'total_score'), 'desc'],
        ];

        if ($this->isTechniqueMatch($first) || $this->isTechniqueMatch($second)) {
            $comparisons[] = [$this->rankValue($first, 'total_kualitas_teknik'), $this->rankValue($second, 'total_kualitas_teknik'), 'desc'];
            $comparisons[] = [$this->rankValue($first, 'total_kuantitas_teknik'), $this->rankValue($second, 'total_kuantitas_teknik'), 'desc'];
        } else {
            $comparisons[] = [$this->rankValue($first, 'total_wiraga'), $this->rankValue($second, 'total_wiraga'), 'desc'];
            $comparisons[] = [$this->rankValue($first, 'total_wirasa'), $this->rankValue($second, 'total_wirasa'), 'desc'];
        }

        $comparisons[] = [$this->rankValue($first, 'total_punishment'), $this->rankValue($second, 'total_punishment'), 'asc'];

        return $comparisons;
    }

    private function rankValue(SeniSingleMatch $match, string $column): float
    {
        return (float) ($match->{$column} ?? 0);
    }

    /**
     * @param  array<string, mixed>  $punishment
     */
    private function punishmentValue(array $punishment, string $column): mixed
    {
        $sourceKeys = match ($column) {
            'keluar_garis' => ['keluar_garis', 'keluar garis'],
            default => [$column],
        };

        foreach ($sourceKeys as $sourceKey) {
            if (array_key_exists($sourceKey, $punishment)) {
                return $punishment[$sourceKey];
            }
        }

        return null;
    }

    private function sourcePunishmentKey(SeniSingleMatch $match, string $column): string
    {
        if ($column === 'keluar_garis' && $this->isTechniqueMatch($match)) {
            return 'keluar garis';
        }

        return $column;
    }

    private function isTechniqueMatch(SeniSingleMatch $match): bool
    {
        $matchText = collect([
            $match->type,
            $match->category,
            $match->group,
        ])
            ->filter()
            ->implode(' ');

        return str($matchText)->lower()->contains(['ganda', 'trio']);
    }

    private function scoreColumn(?string $scoreName): ?string
    {
        if (! $scoreName) {
            return null;
        }

        $normalized = str($scoreName)
            ->lower()
            ->replace([' ', '-'], '_')
            ->toString();

        return match ($normalized) {
            'wiraga' => 'wiraga',
            'wirasa' => 'wirasa',
            'wirama' => 'wirama',
            'kualitas_teknik', 'kualitas' => 'kualitas_teknik',
            'kuantitas_teknik', 'kuantitas' => 'kuantitas_teknik',
            'ketangkasan', 'ketangkatasan' => 'ketangkasan',
            'stamina' => 'stamina',
            'kemantapan' => 'kemantapan',
            'musik' => 'musik',
            default => null,
        };
    }

    private function poolForMatch(?SeniSingleMatch $match): ?SeniPool
    {
        if (! $match) {
            return null;
        }

        return SeniPool::where('no_pool_babak_id', $match->no_pool_babak_id)->first();
    }

    private function freshMatchWithSecretaryScores(SeniSingleMatch $match): SeniSingleMatch
    {
        return SeniSingleMatch::with(['juryScores', 'juryPunishments'])->findOrFail($match->id);
    }

    private function broadcastSeniUpdate(?SeniSingleMatch $match, ?SeniPool $pool, string $status): void
    {
        try {
            broadcast(new SeniMatchUpdated($match, $pool, $status))->toOthers();
        } catch (\Throwable $e) {
            \Log::warning('Broadcasting SeniMatchUpdated failed: '.$e->getMessage());
        }
    }

    private function broadcastSeniJuryScoreUpdate($match, $score, $punishment, string $type, string $field, int $juryNumber): void
    {
        try {
            broadcast(new SeniJuryScoreUpdated(
                $match,
                $score,
                $punishment,
                $type,
                $field,
                $juryNumber
            ))->toOthers();
        } catch (\Throwable $e) {
            \Log::warning('Broadcasting SeniJuryScoreUpdated failed: '.$e->getMessage());
        }
    }

    /**
     * @param  array<string, mixed>  $match
     * @param  array<string, mixed>  $detail
     * @return array<string, mixed>
     */
    private function mapSingleMatch(array $match, SeniPool $pool, array $detail): array
    {
        $bkpId = $match['bkp_id'] ?? null;
        $status = $match['status'] ?? 'not_started';
        $order = $match['no_order'] ?? $match['match_number'] ?? $match['order'] ?? 0;

        return [
            'no_pool_babak_id' => $pool->no_pool_babak_id,
            'bkp_id' => $bkpId ? (int) $bkpId : (int) $order,
            'matches_code' => $match['matches_code'] ?? $match['match_code'] ?? $match['kode_partai'] ?? ($bkpId ? 'SENI-'.$bkpId : 'SENI-'.$order),
            'atletes' => $this->athleteNames($match),
            'contingent' => $match['contingent'] ?? $match['kontingen'] ?? $match['participant_contingent'] ?? '-',
            'type' => $match['type'] ?? $match['jenis'] ?? $match['jenis_seni'] ?? 'single',
            'category' => $match['category'] ?? $pool->category ?? '-',
            'group' => $match['group'] ?? $pool->group ?? '-',
            'status' => $status,
            'is_active' => (bool) ($match['is_active'] ?? false),
            'is_disqualified' => (bool) ($detail['is_disqualified'] ?? $match['is_disqualified'] ?? false),
            'is_passed' => (bool) ($detail['is_passed'] ?? $match['is_passed'] ?? false),
            'round_match' => $match['round_match'] ?? $pool->round_match ?? '-',
            'no_order' => (int) $order,
            'total_score' => $detail['total_score'] ?? $match['total_score'] ?? null,
            'total_wiraga' => $detail['total_wiraga'] ?? $match['total_wiraga'] ?? null,
            'total_wirasa' => $detail['total_wirasa'] ?? $match['total_wirasa'] ?? null,
            'total_wirama' => $detail['total_wirama'] ?? $match['total_wirama'] ?? null,
            'total_kualitas_teknik' => $detail['total_kualitas_teknik'] ?? $match['total_kualitas_teknik'] ?? null,
            'total_kuantitas_teknik' => $detail['total_kuantitas_teknik'] ?? $match['total_kuantitas_teknik'] ?? null,
            'total_ketangkasan' => $detail['total_ketangkasan'] ?? $detail['total_ketangkatasan'] ?? $match['total_ketangkasan'] ?? null,
            'total_stamina' => $detail['total_stamina'] ?? $match['total_stamina'] ?? null,
            'total_kemantapan' => $detail['total_kemantapan'] ?? $match['total_kemantapan'] ?? null,
            'total_musik' => $detail['total_musik'] ?? $match['total_musik'] ?? null,
            'total_punishment' => $detail['total_punishment'] ?? $match['total_punishment'] ?? null,
            'time' => $detail['time'] ?? $match['time'] ?? null,
            'rank' => $detail['rank'] ?? $match['rank'] ?? null,
        ];
    }
}
