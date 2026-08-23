<?php

namespace App\Concerns\Mcp;

use App\Services\McpService;

/**
 * Drop a tool from the server while MCP is in read-only mode.
 *
 * Applied to every tool that changes something outside the server process — the settings and
 * projects files, the git worktrees on disk, the run logs — and to the launch tools, which change
 * nothing in LaborForest but do spawn a user-configured command.
 *
 * The answer is memoized on McpService, because every tool asks on every request while the server
 * builds its primitive list. A settings file that cannot be read drops the tool: a file the app
 * cannot parse says nothing about which mode the user wanted, so it is answered with the narrower
 * one. The shortened tool list that leaves behind is ambiguous between a broken file and a
 * deliberately restricted server, which is what the settings resource and the Settings screen's
 * `Test connection` button are for — neither is gated on registration.
 */
trait RegistersWhenWritable
{
    /**
     * Determine if the tool should be registered.
     */
    public function shouldRegister(): bool
    {
        return ! app(McpService::class)->isReadOnly();
    }
}
