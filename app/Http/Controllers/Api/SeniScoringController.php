<?php

namespace App\Http\Controllers\Api;

use App\Events\SeniMatchUpdated;
use App\Http\Controllers\Controller;
use App\Models\Arena;
use App\Models\SeniPool;
use App\Models\SeniSingleMatch;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;

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

            foreach ($this->mapJuryScores($detail) as $juryScore) {
                $match->juryScores()->create($juryScore);
            }
        });

        $freshMatch = $match->fresh('juryScores');
        $pool = $this->poolForMatch($freshMatch);
        $this->broadcastSeniUpdate($freshMatch, $pool, 'match_activated');

        return response()->json([
            'success' => true,
            'message' => 'Data partai seni berhasil dimuat.',
            'data' => $freshMatch,
            'matches' => SeniSingleMatch::orderBy('no_order')->get(),
            'jury_scores' => $freshMatch->juryScores,
            'pool' => $pool,
        ]);
    }

    public function updateMatchStatus(Request $request, SeniSingleMatch $match)
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(['not_started', 'ongoing', 'paused', 'done'])],
        ]);

        if (! $match->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'Pilih partai aktif terlebih dahulu.',
            ], 422);
        }

        $match->update(['status' => $validated['status']]);

        $freshMatch = $match->fresh('juryScores');
        $pool = $this->poolForMatch($freshMatch);
        $this->broadcastSeniUpdate($freshMatch, $pool, 'status_updated');

        return response()->json([
            'success' => true,
            'message' => 'Status partai seni berhasil diperbarui.',
            'data' => $freshMatch,
            'matches' => SeniSingleMatch::orderBy('no_order')->get(),
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

        return $juryNumbers
            ->map(function (mixed $juryNumber) use ($scores, $totals): array {
                $juryScores = $scores->where('jury_number', $juryNumber);
                $total = $totals->get($juryNumber, []);

                $data = [
                    'jury_number' => (int) $juryNumber,
                    'total_score' => $total['total_score'] ?? $juryScores->sum(fn (array $score): float => (float) ($score['score'] ?? 0)),
                    'is_accepted' => (bool) ($total['is_accepted'] ?? false),
                ];

                foreach ($juryScores as $score) {
                    $column = $this->scoreColumn($score['ref_tgr_score'] ?? $score['ref_seni_score'] ?? $score['ref_score'] ?? null);

                    if ($column) {
                        $data[$column] = $score['score'] ?? null;
                    }
                }

                return $data;
            })
            ->all();
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

    private function broadcastSeniUpdate(?SeniSingleMatch $match, ?SeniPool $pool, string $status): void
    {
        try {
            broadcast(new SeniMatchUpdated($match, $pool, $status))->toOthers();
        } catch (\Throwable $e) {
            \Log::warning('Broadcasting SeniMatchUpdated failed: '.$e->getMessage());
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
