<?php

namespace App\Events;

use App\Models\SeniJuryPunishment;
use App\Models\SeniJuryScore;
use App\Models\SeniSingleMatch;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SeniJuryScoreUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public array $match;

    public array $score;

    public array $punishment;

    public string $type;

    public string $field;

    public int $juryNumber;

    /**
     * Create a new event instance.
     */
    public function __construct(
        SeniSingleMatch $match,
        SeniJuryScore $score,
        ?SeniJuryPunishment $punishment,
        string $type,
        string $field,
        int $juryNumber,
    ) {
        $this->match = $match->toArray();
        $this->score = $score->toArray();
        $this->punishment = $punishment ? $punishment->toArray() : [];
        $this->type = $type;
        $this->field = $field;
        $this->juryNumber = $juryNumber;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new Channel('seni.match.score'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'SeniJuryScoreUpdated';
    }
}
