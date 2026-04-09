<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class JuryScoreUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $partaiId;
    public $corner;
    public $roundNumber;
    public $juryNumber;
    public $scoreDetail;
    public $recap;

    /**
     * Create a new event instance.
     */
    public function __construct($partaiId, $corner, $roundNumber, $juryNumber, $scoreDetail, $recap)
    {
        $this->partaiId = $partaiId;
        $this->corner = $corner;
        $this->roundNumber = $roundNumber;
        $this->juryNumber = $juryNumber;
        $this->scoreDetail = $scoreDetail;
        $this->recap = $recap;
    }

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): array
    {
        return [
            new Channel('match.score'),
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'JuryScoreUpdated';
    }
}
