<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['is_display', 'started_at', 'started_at_milliseconds', 'status', 'is_countdown', 'second', 'is_autostop', 'elapsed_seconds', 'elapsed_milliseconds'])]
class Timer extends Model
{
    protected $attributes = [
        'is_display' => false,
        'status' => 'stopped',
        'is_countdown' => true,
        'second' => 120,
        'is_autostop' => false,
        'elapsed_seconds' => 0,
        'elapsed_milliseconds' => 0,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_display' => 'boolean',
            'started_at' => 'datetime',
            'started_at_milliseconds' => 'integer',
            'is_countdown' => 'boolean',
            'second' => 'integer',
            'is_autostop' => 'boolean',
            'elapsed_seconds' => 'integer',
            'elapsed_milliseconds' => 'integer',
        ];
    }

    public static function current(): self
    {
        return self::query()->firstOrCreate([]);
    }

    public static function millisecondsSinceEpoch(CarbonInterface $date): int
    {
        return ((int) $date->getTimestamp() * 1000) + (int) floor(((int) $date->format('u')) / 1000);
    }

    public function elapsedSeconds(?CarbonInterface $now = null): int
    {
        return intdiv($this->elapsedMilliseconds($now), 1000);
    }

    public function elapsedMilliseconds(?CarbonInterface $now = null): int
    {
        $now ??= now();
        $elapsedMilliseconds = (int) $this->elapsed_milliseconds;

        if ($this->status === 'running') {
            if ($this->started_at_milliseconds) {
                return $elapsedMilliseconds + max(
                    0,
                    self::millisecondsSinceEpoch($now) - (int) $this->started_at_milliseconds,
                );
            }

            if ($this->started_at) {
                $elapsedMilliseconds += max(0, (int) $this->started_at->diffInMilliseconds($now));
            }
        }

        return $elapsedMilliseconds;
    }

    public function displaySeconds(?CarbonInterface $now = null): int
    {
        return intdiv($this->displayMilliseconds($now), 1000);
    }

    public function displayMilliseconds(?CarbonInterface $now = null): int
    {
        $elapsedMilliseconds = $this->elapsedMilliseconds($now);

        if ($this->is_countdown) {
            return max(0, ($this->second * 1000) - $elapsedMilliseconds);
        }

        if ($this->is_autostop) {
            return min($elapsedMilliseconds, $this->second * 1000);
        }

        return $elapsedMilliseconds;
    }

    public function effectiveStatus(?CarbonInterface $now = null): string
    {
        if ($this->status !== 'running') {
            return $this->status;
        }

        if ($this->is_countdown && $this->displayMilliseconds($now) === 0) {
            return 'stopped';
        }

        if (! $this->is_countdown && $this->is_autostop && $this->elapsedMilliseconds($now) >= ($this->second * 1000)) {
            return 'stopped';
        }

        return $this->status;
    }

    /**
     * @return array<string, bool|int|string|null>
     */
    public function toBroadcastPayload(): array
    {
        $now = now();

        return [
            'id' => $this->id,
            'is_display' => $this->is_display,
            'started_at' => $this->started_at?->toISOString(),
            'started_at_milliseconds' => $this->started_at_milliseconds,
            'status' => $this->effectiveStatus($now),
            'stored_status' => $this->status,
            'is_countdown' => $this->is_countdown,
            'second' => $this->second,
            'is_autostop' => $this->is_autostop,
            'elapsed_seconds' => $this->elapsedSeconds($now),
            'elapsed_milliseconds' => $this->elapsedMilliseconds($now),
            'display_seconds' => $this->displaySeconds($now),
            'display_milliseconds' => $this->displayMilliseconds($now),
            'server_now' => $now->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
