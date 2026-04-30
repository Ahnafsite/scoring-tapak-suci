<?php

use App\Http\Controllers\Api\JuryScoreController;
use App\Http\Controllers\Api\MatchSyncController;
use App\Http\Controllers\Api\ScoringSourceController;
use App\Http\Controllers\Api\SeniScoringController;
use App\Http\Controllers\TimerController;
use App\Models\Arena;
use App\Models\FightDetailJuryPointBlue;
use App\Models\FightDetailJuryPointYellow;
use App\Models\FightMatch;
use App\Models\FightRecapJuryPoint;
use App\Models\FightSchedule;
use App\Models\SeniPool;
use App\Models\SeniSingleMatch;
use App\Models\Timer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/login')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', function (Request $request) {
        if ($request->user()->role?->name === 'Timer') {
            return redirect()->route('timer');
        }

        return inertia('Dashboard');
    })->name('dashboard');

    Route::get('timer', [TimerController::class, 'show'])->name('timer');

    Route::get('fight-match-control', function (Request $request) {
        if (! in_array($request->user()->role->name, ['Operator'])) {
            abort(403, 'Unauthorized access.');
        }

        return inertia('FightMatchControl', [
            'schedules' => FightSchedule::all(),
            'arena' => Arena::first(),
            'activeMatch' => FightMatch::first(),
            'recapJuryPoint' => FightRecapJuryPoint::all(),
        ]);
    })->name('fight-match-control');

    Route::get('seni-match-control', function (Request $request) {
        if (! in_array($request->user()->role->name, ['Operator'])) {
            abort(403, 'Unauthorized access.');
        }

        $matches = SeniSingleMatch::orderBy('no_order')->get();
        $activePool = null;

        if ($matches->isNotEmpty()) {
            $activePool = SeniPool::where('no_pool_babak_id', $matches->first()->no_pool_babak_id)->first();
        }

        return inertia('SeniMatchControl', [
            'pools' => SeniPool::orderBy('no_pool')->get(),
            'matches' => $matches,
            'activePool' => $activePool,
            'arena' => Arena::first(),
        ]);
    })->name('seni-match-control');

    Route::get('fight-secretary', function (Request $request) {
        if ($request->user()->role->name !== 'Sekretaris') {
            abort(403, 'Unauthorized access.');
        }

        return inertia('FightSecretary', [
            'arena' => Arena::first(),
            'activeMatch' => FightMatch::first(),
            'recapPoints' => FightRecapJuryPoint::all(),
            'yellowPoints' => FightDetailJuryPointYellow::with(['score', 'punishment'])->get(),
            'bluePoints' => FightDetailJuryPointBlue::with(['score', 'punishment'])->get(),
        ]);
    })->name('fight-secretary');

    Route::get('fight-streaming', function (Request $request) {
        if ($request->user()->role->name !== 'Streamer') {
            abort(403, 'Unauthorized access.');
        }

        return inertia('FightStreaming', [
            'arena' => Arena::first(),
            'activeMatch' => FightMatch::first(),
            'recapPoints' => FightRecapJuryPoint::all(),
            'yellowPoints' => FightDetailJuryPointYellow::with(['score', 'punishment'])->get(),
            'bluePoints' => FightDetailJuryPointBlue::with(['score', 'punishment'])->get(),
            'timer' => Timer::current()->toBroadcastPayload(),
        ]);
    })->name('fight-streaming');

    Route::get('fight-jury', function (Request $request) {
        if ($request->user()->role->name !== 'Juri') {
            abort(403, 'Unauthorized access.');
        }
        $activeMatch = FightMatch::first();

        return inertia('FightJury', [
            'arena' => Arena::first(),
            'activeMatch' => $activeMatch,
            'recapPoints' => FightRecapJuryPoint::all(),
            'yellowPoints' => FightDetailJuryPointYellow::with(['score', 'punishment'])->get(),
            'bluePoints' => FightDetailJuryPointBlue::with(['score', 'punishment'])->get(),
        ]);
    })->name('fight-jury');

    Route::prefix('api')->group(function () {
        Route::get('/source/gelanggang', [ScoringSourceController::class, 'getGelanggang']);
        Route::get('/source/sesi/{gelanggang_id}', [ScoringSourceController::class, 'getSesi']);
        Route::get('/seni/source/sesi/{gelanggang_id}', [SeniScoringController::class, 'getSesi']);
        Route::post('/arena/setup', [ScoringSourceController::class, 'setupArena']);
        Route::post('/seni/arena/setup', [SeniScoringController::class, 'setupArena']);
        Route::post('/seni/pools/{pool}/sync-matches', [SeniScoringController::class, 'syncPoolMatches']);
        Route::post('/seni/matches/{match}/activate', [SeniScoringController::class, 'activateMatch']);
        Route::post('/seni/matches/{match}/status', [SeniScoringController::class, 'updateMatchStatus']);
        Route::post('/partai/sync/{partai_id}', [MatchSyncController::class, 'syncMatch']);
        Route::post('/partai/update-status', [MatchSyncController::class, 'updateStatus']);
        Route::post('/partai/update-round', [MatchSyncController::class, 'updateRound']);
        Route::post('/partai/update-round-winner', [MatchSyncController::class, 'updateRoundWinner']);
        Route::post('/partai/save-partai-data-ts/{partai_id}', [MatchSyncController::class, 'savePartaiDataTs']);

        // Jury Scoring Inputs
        Route::post('/jury/score', [JuryScoreController::class, 'storeScore']);
        Route::delete('/jury/score/{id}', [JuryScoreController::class, 'deleteScore']);
        Route::post('/timer', [TimerController::class, 'update'])->name('timer.update');
        Route::post('/timer/control', [TimerController::class, 'control'])->name('timer.control');
    });
});

require __DIR__.'/settings.php';
