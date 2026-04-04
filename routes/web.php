<?php

use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;

Route::redirect('/', '/login')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
    Route::get('fight-match-control', function() {
        return inertia('FightMatchControl', [
            'schedules' => \App\Models\FightSchedule::all(),
            'arena' => \App\Models\Arena::first()
        ]);
    })->name('fight-match-control');

    Route::prefix('api')->group(function () {
        Route::get('/source/gelanggang', [\App\Http\Controllers\Api\ScoringSourceController::class, 'getGelanggang']);
        Route::get('/source/sesi/{gelanggang_id}', [\App\Http\Controllers\Api\ScoringSourceController::class, 'getSesi']);
        Route::post('/arena/setup', [\App\Http\Controllers\Api\ScoringSourceController::class, 'setupArena']);
    });
});

require __DIR__.'/settings.php';
