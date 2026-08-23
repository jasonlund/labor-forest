<?php

namespace App\Services;

use App\Data\ProjectData;
use App\Data\SettingsData;
use App\Data\WorkspaceData;
use Closure;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;

class LaunchService
{
    public function launchTerminal(ProjectData $projectData, WorkspaceData $workspaceData): void
    {
        $this->launch(
            projectData: $projectData,
            workspaceData: $workspaceData,
            command: $this->getTerminalCommand($projectData),
        );
    }

    public function launchIde(ProjectData $projectData, WorkspaceData $workspaceData): void
    {
        $this->launch(
            projectData: $projectData,
            workspaceData: $workspaceData,
            command: $this->getIdeCommand($projectData),
        );
    }

    public function launchBrowser(ProjectData $projectData, WorkspaceData $workspaceData): void
    {
        $this->launch(
            projectData: $projectData,
            workspaceData: $workspaceData,
            command: $this->getBrowserCommand($projectData),
        );
    }

    /**
     * Resolve the command to run, preferring the project's override over the global setting.
     *
     * A cleared override reaches this as an empty string rather than null, so the fallback tests the
     * same way `launch()` does rather than with `??`, which would take that empty string for a
     * deliberate command and then launch nothing at all.
     *
     * @param  Closure(SettingsData): ?string  $settingsCommand
     */
    protected function resolveCommand(?string $projectCommand, Closure $settingsCommand): ?string
    {
        if ($projectCommand) {
            return $projectCommand;
        }

        return $settingsCommand(app(SettingsService::class)->loadSettings());
    }

    protected function launch(ProjectData $projectData, WorkspaceData $workspaceData, ?string $command): void
    {
        if (! $command) {
            return;
        }

        $this->launchProcess(
            command: $this->getRawCommand($projectData, $workspaceData, $command),
            cwd: $workspaceData->path,
        )->start();
    }

    public function getBrowserCommand(ProjectData $projectData): ?string
    {
        return $this->resolveCommand(
            $projectData->command_launch_browser,
            fn (SettingsData $settingsData) => $settingsData->command_launch_browser,
        );
    }

    public function getIdeCommand(ProjectData $projectData): ?string
    {
        return $this->resolveCommand(
            $projectData->command_launch_ide,
            fn (SettingsData $settingsData) => $settingsData->command_launch_ide,
        );
    }

    public function getTerminalCommand(ProjectData $projectData): ?string
    {
        return $this->resolveCommand(
            $projectData->command_launch_terminal,
            fn (SettingsData $settingsData) => $settingsData->command_launch_terminal,
        );
    }

    public function getRawCommand(ProjectData $projectData, WorkspaceData $workspaceData, ?string $command): ?string
    {
        if (! $command) {
            return null;
        }

        return app(VariableReplacementService::class)->replace(
            projectData: $projectData,
            workspaceData: $workspaceData,
            content: $command,
        );
    }

    /**
     * Build the launch process, kept as a test seam even though the rest of the application doubles
     * processes with Process::fake().
     *
     * The fake path of PendingProcess::start() builds a process it never starts and then discards
     * it, and create_new_console sends that process's destructor down a branch that reads pipes
     * which were never opened — a fatal error rather than a failed test. The option cannot simply
     * be dropped: without it the destructor stops the process instead, killing the editor or
     * terminal the moment this method returns.
     */
    protected function launchProcess(string $command, string $cwd): PendingProcess
    {
        return Process::path($cwd)
            // a terminal or editor opened here must not inherit this application's environment
            ->env(app(ProcessEnvironmentService::class)->sanitized())
            ->options(['create_new_console' => true])
            ->command($command);
    }
}
