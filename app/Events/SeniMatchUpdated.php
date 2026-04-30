<?php

namespace App\Events;

use App\Models\SeniPool;
use App\Models\SeniSingleMatch;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SeniMatchUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public array $match;

    public array $pool;

    public string $status;

    /**
     * Create a new event instance.
     */
    public function __construct(?SeniSingleMatch $match = null, ?SeniPool $pool = null, string $status = 'updated')
    {
        $this->match = $match ? $match->toArray() : [];
        $this->pool = $pool ? $pool->toArray() : [];
        $this->status = $status;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new Channel('seni.match.status'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'SeniMatchUpdated';
    }
}
