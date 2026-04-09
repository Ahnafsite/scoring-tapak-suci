<?php

use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;

Route::redirect('/', '/login')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
    Route::get('fight-match-control', function (\Illuminate\Http\Request $request) {
        if (!in_array($request->user()->role->name, ['Operator', 'Sekretaris'])) {
            abort(403, 'Unauthorized access.');
        }
        return inertia('FightMatchControl', [
            'schedules' => \App\Models\FightSchedule::all(),
            'arena' => \App\Models\Arena::first(),
            'activeMatch' => \App\Models\FightMatch::first(),
            'recapJuryPoint' => \App\Models\FightRecapJuryPoint::all(),
        ]);
    })->name('fight-match-control');

    Route::get('fight-jury', function (\Illuminate\Http\Request $request) {
        if ($request->user()->role->name !== 'Juri') {
            abort(403, 'Unauthorized access.');
        }
        $activeMatch = \App\Models\FightMatch::first();
        return inertia('FightJury', [
            'arena' => \App\Models\Arena::first(),
            'activeMatch' => $activeMatch,
            'recapPoints' => \App\Models\FightRecapJuryPoint::all(),
            'yellowPoints' => \App\Models\FightDetailJuryPointYellow::with(['score', 'punishment'])->get(),
            'bluePoints' => \App\Models\FightDetailJuryPointBlue::with(['score', 'punishment'])->get(),
        ]);
    })->name('fight-jury');

    Route::prefix('api')->group(function () {
        Route::get('/source/gelanggang', [\App\Http\Controllers\Api\ScoringSourceController::class, 'getGelanggang']);
        Route::get('/source/sesi/{gelanggang_id}', [\App\Http\Controllers\Api\ScoringSourceController::class, 'getSesi']);
        Route::post('/arena/setup', [\App\Http\Controllers\Api\ScoringSourceController::class, 'setupArena']);
        Route::post('/partai/sync/{partai_id}', [\App\Http\Controllers\Api\MatchSyncController::class, 'syncMatch']);
        Route::post('/partai/update-status', [\App\Http\Controllers\Api\MatchSyncController::class, 'updateStatus']);
        Route::post('/partai/update-round', [\App\Http\Controllers\Api\MatchSyncController::class, 'updateRound']);
    });
});

require __DIR__ . '/settings.php';
