<?php

namespace App\Concerns\Mcp;

use App\Enums\McpActionPendingApprovalType;
use App\Enums\McpPolicy;
use App\Events\McpActionPendingApproval;
use App\Services\McpService;
use App\Services\SettingsService;
use Laravel\Mcp\Response;

trait IsShellCommandExecutionTool
{
    /**
     * Determine if the tool should be registered.
     *
     * Both of the user's gates are answered here rather than by RegistersWhenWritable, because a
     * tool may only declare shouldRegister() once and this trait is what marks a tool as one that
     * spawns a command: read-only mode withholds it for changing something outside the server, and
     * a `deny` shell policy withholds it for running a command at all.
     *
     * Withholding rather than publishing-and-refusing is the whole point. A tool that could only
     * ever answer `denied` still tells an agent the capability is there and invites it to keep
     * asking; a tool that is absent is understood immediately.
     */
    public function shouldRegister(): bool
    {
        $mcp = app(McpService::class);

        return ! $mcp->isReadOnly() && ! $mcp->deniesShellCommands();
    }

    /**
     * Run a tool's shell-spawning work behind the user's `mcp_shell_policy`.
     *
     * `$whenAllowed` returns null to accept the default success response, or a response of its
     * own when the tool has something to report back — `run-workflow` returns the run log id.
     *
     * Only the two policies that let the work happen are answered here. `McpPolicy::DENY` cannot
     * reach a handle(): shouldRegister() withholds the tool, and the package resolves a
     * `tools/call` through the same eligibility filter as `tools/list` (see CallTool), so a denied
     * tool is reported as not found. The match is left without that arm deliberately — an
     * UnhandledMatchError lands in the calling tool's catch rather than falling through and
     * spawning the command the policy refuses.
     *
     * @param  callable(): ?Response  $whenAllowed
     */
    protected function executeShellCommandTool(
        callable $whenAllowed,
        string $workspacePath,
        McpActionPendingApprovalType $type,
        ?string $workflowName = null,
    ): Response {
        $policy = app(SettingsService::class)->loadSettings()->mcp_shell_policy;

        return match ($policy) {
            McpPolicy::ALLOW => $whenAllowed() ?? Response::text('success')->asAssistant(),
            McpPolicy::REQUIRE_APPROVAL => $this->parkForApproval($workspacePath, $type, $workflowName),
        };
    }

    /**
     * Hand the action to the user in the application window and answer the client without it.
     */
    protected function parkForApproval(
        string $workspacePath,
        McpActionPendingApprovalType $type,
        ?string $workflowName,
    ): Response {
        broadcast(new McpActionPendingApproval(
            workspacePath: $workspacePath,
            type: $type->value,
            workflowName: $workflowName,
        ));

        return Response::text('pending manual approval in application UI')->asAssistant();
    }
}
