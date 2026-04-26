<?php

namespace App\Events;

use App\Models\Timer;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TimerUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @var array<string, bool|int|string|null>
     */
    public array $timer;

    /**
     * Create a new event instance.
     */
    public function __construct(Timer $timer)
    {
        $this->timer = $timer->toBroadcastPayload();
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new Channel('timer'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'TimerUpdated';
    }
}
