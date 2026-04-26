<?php

namespace App\Http\Controllers;

use App\Events\TimerUpdated;
use App\Http\Requests\TimerControlRequest;
use App\Http\Requests\TimerUpdateRequest;
use App\Models\Timer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TimerController extends Controller
{
    public function show(Request $request): Response
    {
        if ($request->user()?->role?->name !== 'Timer') {
            abort(403, 'Unauthorized access.');
        }

        return Inertia::render('Timer', [
            'timer' => Timer::current()->toBroadcastPayload(),
        ]);
    }

    public function update(TimerUpdateRequest $request): JsonResponse
    {
        $timer = Timer::current();
        $validated = $request->validated();
        $isTimingConfigurationChanged = array_key_exists('is_countdown', $validated)
            || array_key_exists('second', $validated);

        if ($isTimingConfigurationChanged) {
            $validated['elapsed_seconds'] = 0;
            $validated['elapsed_milliseconds'] = 0;
            $now = now();
            $validated['started_at'] = $timer->status === 'running' ? $now : null;
            $validated['started_at_milliseconds'] = $timer->status === 'running'
                ? Timer::millisecondsSinceEpoch($now)
                : null;
        }

        if (($validated['status'] ?? null) && $validated['status'] !== $timer->status) {
            $validated = array_merge($validated, $this->statusAttributes($timer, $validated['status']));
        }

        $timer->update($validated);

        TimerUpdated::dispatch($timer->fresh());

        return response()->json([
            'timer' => $timer->fresh()->toBroadcastPayload(),
        ]);
    }

    public function control(TimerControlRequest $request): JsonResponse
    {
        $timer = Timer::current();
        $attributes = $this->controlAttributes($timer, $request->validated('action'));

        $timer->update($attributes);

        TimerUpdated::dispatch($timer->fresh());

        return response()->json([
            'timer' => $timer->fresh()->toBroadcastPayload(),
        ]);
    }

    /**
     * @return array<string, int|string|null>
     */
    private function controlAttributes(Timer $timer, string $action): array
    {
        $now = now();

        return match ($action) {
            'start' => [
                'status' => 'running',
                'started_at' => $now,
                'started_at_milliseconds' => Timer::millisecondsSinceEpoch($now),
                'elapsed_seconds' => $timer->status === 'paused' ? $timer->elapsedSeconds() : 0,
                'elapsed_milliseconds' => $timer->status === 'paused' ? $timer->elapsedMilliseconds() : 0,
            ],
            'pause' => [
                'status' => 'paused',
                'started_at' => null,
                'started_at_milliseconds' => null,
                'elapsed_seconds' => $timer->elapsedSeconds(),
                'elapsed_milliseconds' => $timer->elapsedMilliseconds(),
            ],
            'stop' => [
                'status' => 'stopped',
                'started_at' => null,
                'started_at_milliseconds' => null,
                'elapsed_seconds' => $timer->elapsedSeconds(),
                'elapsed_milliseconds' => $timer->elapsedMilliseconds(),
            ],
            'reset' => [
                'status' => 'stopped',
                'started_at' => null,
                'started_at_milliseconds' => null,
                'elapsed_seconds' => 0,
                'elapsed_milliseconds' => 0,
            ],
        };
    }

    /**
     * @return array<string, int|string|null>
     */
    private function statusAttributes(Timer $timer, string $status): array
    {
        return match ($status) {
            'running' => $this->controlAttributes($timer, 'start'),
            'paused' => $this->controlAttributes($timer, 'pause'),
            'stopped' => $this->controlAttributes($timer, 'stop'),
        };
    }
}
