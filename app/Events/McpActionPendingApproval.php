<?php

namespace App\Events;

use App\Enums\BroadcastChannel;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

class McpActionPendingApproval implements ShouldBroadcastNow
{
    /**
     * Create a new event instance.
     */
    public function __construct(public string $workspacePath, public string $type, public ?string $workflowName = null)
    {
        //
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new Channel(BroadcastChannel::NATIVEPHP->value),
        ];
    }
}
