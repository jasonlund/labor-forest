<?php

use App\Enums\BroadcastChannel;
use App\Enums\McpActionPendingApprovalType;
use App\Events\McpActionPendingApproval;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

it('broadcasts on the channel the desktop window listens to', function () {
    $channels = (new McpActionPendingApproval(
        workspacePath: '/tmp/repo-feature',
        type: McpActionPendingApprovalType::RUN_WORKFLOW->value,
        workflowName: 'up',
    ))->broadcastOn();

    expect($channels)->toHaveCount(1)
        ->and($channels[0])->toBeInstanceOf(Channel::class)
        ->and($channels[0]->name)->toBe(BroadcastChannel::NATIVEPHP->value);
});

it('broadcasts immediately rather than through the queue', function () {
    // the tool call that parks the action is a short-lived mcp request with no worker behind it, and
    // it answers the client that the user was asked — a queued broadcast would never ask them
    expect(new McpActionPendingApproval('/tmp/repo-feature', McpActionPendingApprovalType::LAUNCH_IDE->value))
        ->toBeInstanceOf(ShouldBroadcastNow::class);
});

it('carries no workflow name for an action that is not a workflow run', function () {
    $event = new McpActionPendingApproval('/tmp/repo-feature', McpActionPendingApprovalType::LAUNCH_TERMINAL->value);

    expect($event->workspacePath)->toBe('/tmp/repo-feature')
        ->and($event->type)->toBe('launch-terminal')
        ->and($event->workflowName)->toBeNull();
});
