<?php

namespace App\Livewire;

use App\Concerns\Filament\Pages\HasResultNotificationOperations;
use App\Data\ProjectData;
use App\Data\WorkspaceData;
use App\Enums\McpActionPendingApprovalType;
use App\Enums\WindowId;
use App\Events\GlobalRefresh;
use App\Events\McpActionPendingApproval;
use App\Services\LaunchService;
use App\Services\ProjectsService;
use App\Services\SettingsService;
use App\Services\VariableReplacementService;
use App\Services\WorkflowService;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Infolists\Components\CodeEntry;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Support\Enums\Width;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Native\Desktop\Facades\Window;
use Phiki\Grammar\Grammar;
use Throwable;

class McpActionPendingApprovalModal extends Component implements HasActions, HasSchemas
{
    use HasResultNotificationOperations;
    use InteractsWithActions;
    use InteractsWithSchemas;

    #[Locked]
    public ?string $workspacePath = null;

    #[Locked]
    public ?string $type = null;

    #[Locked]
    public ?string $workflowName = null;

    #[Computed]
    public function project(): ?ProjectData
    {
        return rescue(fn () => app(ProjectsService::class)->loadProjectFromWorkspace($this->workspacePath));
    }

    #[Computed]
    public function workspace(): ?WorkspaceData
    {
        return rescue(fn () => app(ProjectsService::class)->loadProjectWorkspace($this->workspacePath));
    }

    #[Computed]
    public function actionType(): ?McpActionPendingApprovalType
    {
        return McpActionPendingApprovalType::tryFrom((string) $this->type);
    }

    #[Computed]
    public function workflowPath(): ?string
    {
        return rescue(fn () => app(WorkflowService::class)->findWorkflowPath($this->workspacePath, $this->workflowName));
    }

    public function render(): View
    {
        return view('livewire.mcp-action-pending-approval-modal');
    }

    #[On('native:'.McpActionPendingApproval::class)]
    public function actionPendingApproval(string $workspacePath, string $type, ?string $workflowName = null): void
    {
        $this->workspacePath = $workspacePath;
        $this->type = $type;
        $this->workflowName = $workflowName;

        if ($this->type && $this->project && $this->workspace && (! $this->workflowName || $this->workflowPath)) {
            $this->mountAction('approvePending');
        }

        // Window::open() on an id that already exists is show() + focus(); App::focus() posts to a
        // route the Electron plugin does not register, so it 404s and the window never comes forward
        Window::open(WindowId::MAIN->value);
    }

    /**
     * The workflow file, or the launch command, as it will run — every {{ }} tag resolved.
     */
    #[Computed]
    public function rawData(): string
    {
        if ($this->project === null || $this->workspace === null) {
            return '';
        }

        if ($this->workflowName) {
            $rawData = (string) rescue(fn () => File::get($this->workflowPath), '');
        } else {
            $launchService = app(LaunchService::class);

            $rawData = match ($this->actionType) {
                McpActionPendingApprovalType::LAUNCH_TERMINAL => $launchService->getTerminalCommand($this->project),
                McpActionPendingApprovalType::LAUNCH_IDE => $launchService->getIdeCommand($this->project),
                McpActionPendingApprovalType::LAUNCH_BROWSER => $launchService->getBrowserCommand($this->project),
                default => null,
            } ?? '';
        }

        // the tags resolve when the action runs, so an approval decided against the template is
        // decided against something other than what will happen; content the service cannot resolve
        // is shown as authored rather than swallowed into an empty modal
        return rescue(fn () => app(VariableReplacementService::class)->replace(
            projectData: $this->project,
            workspaceData: $this->workspace,
            content: $rawData,
        ), $rawData);
    }

    public function approvePendingAction(): Action
    {
        $launchService = app(LaunchService::class);

        // the component renders on every panel page, so the action is resolvable while nothing is
        // pending — a bare action rather than a dereference of the null state
        if ($this->actionType === null || $this->project === null || $this->workspace === null) {
            return Action::make('approvePending');
        }

        $project = $this->project->dirName();
        $workspace = $this->workspace->slugKebab();

        return Action::make('approvePending')
            ->requiresConfirmation()
            ->modalHeading('MCP action requires approval')
            ->modalWidth(Width::ThreeExtraLarge)
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->modalSubmitActionLabel(fn () => $this->workflowName ? 'Run workflow' : 'Execute command')
            ->color('danger')
            ->schema([
                KeyValueEntry::make('summary')
                    ->hiddenLabel()
                    ->state(array_filter([
                        'Project' => $project,
                        'Workspace' => $workspace,
                        'Action' => $this->actionType->getLabel(),
                        'Workflow' => $this->workflowName,
                    ])),
                CodeEntry::make('raw_data')
                    ->label(fn () => $this->workflowName ? 'Workflow' : 'Command')
                    ->grammar($this->workflowName ? Grammar::Yaml : Grammar::Shellscript)
                    ->state($this->rawData),
            ])
            // the MCP client was answered when the action was parked and hears nothing more, so a
            // failure at approval time has nowhere to go but the window the user approved it in.
            // The run gate is one of these: run-workflow parks before the workspace status is
            // considered, and dispatchWorkflow is what refuses a workflow the status will not take
            ->action(fn () => static::resultNotificationOperation(
                callback: function () use ($launchService) {
                    $timeout = rescue(fn () => app(SettingsService::class)->loadSettings()->workflow_step_timeout_seconds, 600);

                    match (true) {
                        (bool) $this->workflowName => app(WorkflowService::class)->dispatchWorkflow(
                            projectUuid: $this->project->uuid,
                            workspacePath: $this->workspacePath,
                            workflowName: $this->workflowName,
                            stepHashes: null,
                            parentLogId: null,
                            timeoutSeconds: $timeout,
                        ),
                        $this->actionType === McpActionPendingApprovalType::LAUNCH_TERMINAL => $launchService->launchTerminal($this->project, $this->workspace),
                        $this->actionType === McpActionPendingApprovalType::LAUNCH_IDE => $launchService->launchIde($this->project, $this->workspace),
                        $this->actionType === McpActionPendingApprovalType::LAUNCH_BROWSER => $launchService->launchBrowser($this->project, $this->workspace),
                    };

                    broadcast(new GlobalRefresh);
                },
                // the same titles the Project screen sends for the same work
                successTitle: match (true) {
                    (bool) $this->workflowName => Str::headline($this->workflowName).' workflow started',
                    $this->actionType === McpActionPendingApprovalType::LAUNCH_TERMINAL => 'Terminal launched',
                    $this->actionType === McpActionPendingApprovalType::LAUNCH_IDE => 'IDE launched',
                    default => 'Browser launched',
                },
                failureBody: fn (Throwable $th) => $th->getMessage(),
            ));
    }
}
