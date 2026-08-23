<?php

use App\Data\SettingsData;
use App\Enums\McpActionPendingApprovalType;
use App\Enums\WindowId;
use App\Enums\WorkspaceStatus;
use App\Events\GlobalRefresh;
use App\Events\McpActionPendingApproval;
use App\Exceptions\InvalidSettingsFile;
use App\Exceptions\UnresolvedVariable;
use App\Exceptions\WorkflowNotRunnable;
use App\Livewire\McpActionPendingApprovalModal;
use App\Services\LaunchService;
use App\Services\ProjectsService;
use App\Services\SettingsService;
use App\Services\WorkflowService;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Mockery\MockInterface;
use Native\Desktop\Facades\Window;
use Native\Desktop\Windows\Window as NativeWindow;

beforeEach(function () {
    // the component brings the window forward on every parked action; without a double that reaches
    // the real NativePHP client over HTTP, which the suite-wide preventStrayRequests() rejects
    $this->windows = Window::fake()
        ->alwaysReturnWindows([new NativeWindow(WindowId::MAIN->value)]);

    $this->uuid = '11111111-1111-1111-1111-111111111111';
    $this->workspacePath = '/tmp/repo-feature';
    $this->workflowPath = base_path('tests/Fixtures/workflows/valid.yaml');

    $this->mockProjects = function (?callable $extend = null) {
        $this->mock(ProjectsService::class, function (MockInterface $mock) use ($extend) {
            $mock->shouldReceive('loadProjectFromWorkspace')
                ->with($this->workspacePath)
                ->andReturn(componentProjectData($this->uuid));

            $mock->shouldReceive('loadProjectWorkspace')
                ->with($this->workspacePath)
                ->andReturn(componentWorkspaceData($this->workspacePath));

            if ($extend) {
                $extend($mock);
            }
        });
    };
});

describe('actionPendingApproval', function () {
    it('mounts the approval modal for a parked workflow run', function () {
        ($this->mockProjects)();

        $this->mock(WorkflowService::class, function (MockInterface $mock) {
            $mock->shouldReceive('findWorkflowPath')
                ->with($this->workspacePath, 'up')
                ->andReturn($this->workflowPath);
        });

        Livewire::test(McpActionPendingApprovalModal::class)
            ->dispatch(
                'native:'.McpActionPendingApproval::class,
                workspacePath: $this->workspacePath,
                type: McpActionPendingApprovalType::RUN_WORKFLOW->value,
                workflowName: 'up',
            )
            ->assertOk()
            ->assertActionMounted('approvePending')
            ->assertDispatched('sync-action-modals');

        $this->windows->assertOpened(WindowId::MAIN->value);
    });

    it('mounts the approval modal for a parked launch, showing the command it would run', function () {
        ($this->mockProjects)();

        $this->mock(LaunchService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getTerminalCommand')->andReturn('open -a Ghostty {{ WORKSPACE_DIR }}');
        });

        Livewire::test(McpActionPendingApprovalModal::class)
            ->dispatch(
                'native:'.McpActionPendingApproval::class,
                workspacePath: $this->workspacePath,
                type: McpActionPendingApprovalType::LAUNCH_TERMINAL->value,
            )
            ->assertOk()
            ->assertActionMounted('approvePending')
            ->assertDispatched('sync-action-modals')
            ->assertSet('rawData', 'open -a Ghostty /tmp/repo-feature');
    });

    it('shows content it cannot resolve as authored rather than an empty modal', function () {
        ($this->mockProjects)();

        $this->mock(LaunchService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getIdeCommand')->andReturn('code {{ NOPE }}');
        });

        Livewire::test(McpActionPendingApprovalModal::class)
            ->dispatch(
                'native:'.McpActionPendingApproval::class,
                workspacePath: $this->workspacePath,
                type: McpActionPendingApprovalType::LAUNCH_IDE->value,
            )
            ->assertOk()
            ->assertActionMounted('approvePending')
            ->assertSet('rawData', 'code {{ NOPE }}');
    });

    it('mounts nothing when the workspace does not resolve to a project', function () {
        $this->mock(ProjectsService::class, function (MockInterface $mock) {
            $mock->shouldReceive('loadProjectFromWorkspace')->andReturn(null);
            $mock->shouldReceive('loadProjectWorkspace')->andReturn(componentWorkspaceData($this->workspacePath));
        });

        Livewire::test(McpActionPendingApprovalModal::class)
            ->dispatch(
                'native:'.McpActionPendingApproval::class,
                workspacePath: $this->workspacePath,
                type: McpActionPendingApprovalType::LAUNCH_IDE->value,
            )
            ->assertOk()
            ->assertActionNotMounted();
    });

    it('shows the parked workflow file with its tags resolved', function () {
        ($this->mockProjects)();

        $this->mock(WorkflowService::class, function (MockInterface $mock) {
            $mock->shouldReceive('findWorkflowPath')->andReturn($this->workflowPath);
        });

        Livewire::test(McpActionPendingApprovalModal::class)
            ->dispatch(
                'native:'.McpActionPendingApproval::class,
                workspacePath: $this->workspacePath,
                type: McpActionPendingApprovalType::RUN_WORKFLOW->value,
                workflowName: 'up',
            )
            ->assertOk()
            ->assertActionMounted('approvePending')
            // approving the template rather than what will run would be approving something else
            ->assertSet('rawData', str_replace(
                '{{ WORKSPACE_SLUG_KEBAB }}',
                'repo-feature',
                file_get_contents($this->workflowPath),
            ));
    });

    it('mounts nothing when the path is not a workspace of the project', function () {
        $this->mock(ProjectsService::class, function (MockInterface $mock) {
            $mock->shouldReceive('loadProjectFromWorkspace')->andReturn(componentProjectData($this->uuid));
            $mock->shouldReceive('loadProjectWorkspace')->andReturn(null);
        });

        Livewire::test(McpActionPendingApprovalModal::class)
            ->dispatch(
                'native:'.McpActionPendingApproval::class,
                workspacePath: $this->workspacePath,
                type: McpActionPendingApprovalType::LAUNCH_IDE->value,
            )
            ->assertOk()
            ->assertActionNotMounted()
            // nothing to resolve the command against, so the modal has nothing to show rather than
            // dereferencing the workspace it does not have
            ->assertSet('rawData', '');
    });

    it('mounts nothing for an action type it does not know', function () {
        ($this->mockProjects)();

        Livewire::test(McpActionPendingApprovalModal::class)
            ->dispatch(
                'native:'.McpActionPendingApproval::class,
                workspacePath: $this->workspacePath,
                type: 'launch-rocket',
            )
            ->assertOk()
            ->assertActionNotMounted();
    });

    it('mounts nothing when the named workflow has no file', function () {
        ($this->mockProjects)();

        $this->mock(WorkflowService::class, function (MockInterface $mock) {
            $mock->shouldReceive('findWorkflowPath')->andReturn(null);
        });

        Livewire::test(McpActionPendingApprovalModal::class)
            ->dispatch(
                'native:'.McpActionPendingApproval::class,
                workspacePath: $this->workspacePath,
                type: McpActionPendingApprovalType::RUN_WORKFLOW->value,
                workflowName: 'nope',
            )
            ->assertOk()
            ->assertActionNotMounted();
    });

    /**
     * The modal container is what the mounted action renders into — without it the action mounts
     * server-side and the user is never shown anything.
     */
    it('renders the action modal container with nothing parked', function () {
        Livewire::test(McpActionPendingApprovalModal::class)
            ->assertOk()
            ->assertActionNotMounted()
            ->assertSeeHtml('wire:partial="action-modals"');
    });
});

describe('approvePending', function () {
    it('dispatches the workflow once the user approves it', function () {
        Event::fake([GlobalRefresh::class]);

        ($this->mockProjects)();

        $this->mock(WorkflowService::class, function (MockInterface $mock) {
            $mock->shouldReceive('findWorkflowPath')->andReturn($this->workflowPath);

            $mock->shouldReceive('dispatchWorkflow')
                ->once()
                ->withArgs(fn (
                    string $projectUuid,
                    string $workspacePath,
                    string $workflowName,
                    ?array $stepHashes,
                    ?string $parentLogId,
                    int $timeoutSeconds,
                ): bool => $projectUuid === $this->uuid
                    && $workspacePath === $this->workspacePath
                    && $workflowName === 'up'
                    && $stepHashes === null
                    && $parentLogId === null
                    && $timeoutSeconds === 90)
                ->andReturn('log-1');
        });

        $this->mock(SettingsService::class, function (MockInterface $mock) {
            $mock->shouldReceive('loadSettings')->andReturn(new SettingsData(workflow_step_timeout_seconds: 90));
        });

        Livewire::test(McpActionPendingApprovalModal::class)
            ->dispatch(
                'native:'.McpActionPendingApproval::class,
                workspacePath: $this->workspacePath,
                type: McpActionPendingApprovalType::RUN_WORKFLOW->value,
                workflowName: 'up',
            )
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified('Up workflow started');

        // the approval happens outside the screen that shows the workspace, so the refresh is what
        // brings the new status to whichever page the modal was mounted on
        Event::assertDispatched(GlobalRefresh::class);
    });

    it('reports a run the workspace status refuses rather than throwing out of the modal', function () {
        ($this->mockProjects)();

        // run-workflow parks before the gate is considered, so approving is the first moment a
        // workflow the status will not take can be refused
        $this->mock(WorkflowService::class, function (MockInterface $mock) {
            $mock->shouldReceive('findWorkflowPath')->andReturn($this->workflowPath);

            $mock->shouldReceive('dispatchWorkflow')->once()->andThrow(
                new WorkflowNotRunnable('up', WorkspaceStatus::READY, WorkspaceStatus::SUSPENDED),
            );
        });

        $this->mock(SettingsService::class, function (MockInterface $mock) {
            $mock->shouldReceive('loadSettings')->andReturn(new SettingsData);
        });

        Livewire::test(McpActionPendingApprovalModal::class)
            ->dispatch(
                'native:'.McpActionPendingApproval::class,
                workspacePath: $this->workspacePath,
                type: McpActionPendingApprovalType::RUN_WORKFLOW->value,
                workflowName: 'up',
            )
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified('Whoops! Something went wrong.')
            ->assertNotNotified('Up workflow started');
    });

    it('reports a launch command it cannot run rather than throwing out of the modal', function () {
        ($this->mockProjects)();

        $this->mock(LaunchService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getIdeCommand')->andReturn('code "{{ ENV_APP_URL }}"');
            $mock->shouldReceive('launchIde')->once()->andThrow(
                UnresolvedVariable::missingEnvironmentVariable('APP_URL', '/tmp/repo-feature/.env'),
            );
        });

        $this->mock(SettingsService::class, function (MockInterface $mock) {
            $mock->shouldReceive('loadSettings')->andReturn(new SettingsData);
        });

        Livewire::test(McpActionPendingApprovalModal::class)
            ->dispatch(
                'native:'.McpActionPendingApproval::class,
                workspacePath: $this->workspacePath,
                type: McpActionPendingApprovalType::LAUNCH_IDE->value,
            )
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified('Whoops! Something went wrong.');
    });

    it('falls back to the default step timeout when the settings cannot be read', function () {
        ($this->mockProjects)();

        $this->mock(WorkflowService::class, function (MockInterface $mock) {
            $mock->shouldReceive('findWorkflowPath')->andReturn($this->workflowPath);

            $mock->shouldReceive('dispatchWorkflow')
                ->once()
                // an unreadable settings file must not stop a run the user just approved
                ->withArgs(fn (...$arguments): bool => $arguments[5] === 600)
                ->andReturn('log-1');
        });

        $this->mock(SettingsService::class, function (MockInterface $mock) {
            $mock->shouldReceive('loadSettings')
                ->andThrow(new InvalidSettingsFile('.laborforest/settings.yaml', ['broken']));
        });

        Livewire::test(McpActionPendingApprovalModal::class)
            ->dispatch(
                'native:'.McpActionPendingApproval::class,
                workspacePath: $this->workspacePath,
                type: McpActionPendingApprovalType::RUN_WORKFLOW->value,
                workflowName: 'up',
            )
            ->callMountedAction()
            ->assertHasNoActionErrors();
    });

    it('launches through the service the parked action names', function (McpActionPendingApprovalType $type, string $commandMethod, string $launchMethod) {
        ($this->mockProjects)();

        $this->mock(LaunchService::class, function (MockInterface $mock) use ($commandMethod, $launchMethod) {
            $mock->shouldReceive($commandMethod)->andReturn('open -a Thing');
            $mock->shouldReceive($launchMethod)->once();
        });

        $this->mock(SettingsService::class, function (MockInterface $mock) {
            $mock->shouldReceive('loadSettings')->andReturn(new SettingsData);
        });

        Livewire::test(McpActionPendingApprovalModal::class)
            ->dispatch(
                'native:'.McpActionPendingApproval::class,
                workspacePath: $this->workspacePath,
                type: $type->value,
            )
            ->callMountedAction()
            ->assertHasNoActionErrors();
    })->with([
        'terminal' => [McpActionPendingApprovalType::LAUNCH_TERMINAL, 'getTerminalCommand', 'launchTerminal'],
        'ide' => [McpActionPendingApprovalType::LAUNCH_IDE, 'getIdeCommand', 'launchIde'],
        'browser' => [McpActionPendingApprovalType::LAUNCH_BROWSER, 'getBrowserCommand', 'launchBrowser'],
    ]);
});
