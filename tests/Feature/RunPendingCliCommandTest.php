<?php

use App\Data\PendingCliCommandResultData;
use App\Data\SettingsData;
use App\Exceptions\InvalidSettingsFile;
use App\Services\CliToolsService;
use App\Services\SettingsService;
use Mockery\MockInterface;
use Native\Desktop\Events\App\OpenedFromURL;
use Tests\Fakes\FakeRunPendingCliCommand;

it('sends the window to the page the pending command resolved to', function () {
    cliCommandResolvesTo('http://localhost:8000/projects/some-uuid');

    expect(runPendingCliCommandListener()->navigations)
        ->toBe(['http://localhost:8000/projects/some-uuid']);
});

it('leaves the window alone when nothing was pending', function () {
    $this->mock(CliToolsService::class, function (MockInterface $mock) {
        $mock->shouldReceive('runPendingCommand')
            ->once()
            ->andReturnNull();
    });

    expect(runPendingCliCommandListener()->navigations)->toBe([]);
});

describe('headless mode', function () {
    it('leaves the window where it is once the work is done', function () {
        // the command still runs — cliCommandResolvesTo() expects it once; only the page is withheld
        headlessModeIs(true);
        cliCommandResolvesTo('http://localhost:8000/projects/some-uuid');

        expect(runPendingCliCommandListener()->navigations)->toBe([]);
    });

    it('still shows an outcome that exists nowhere else', function () {
        headlessModeIs(true);
        cliCommandResolvesTo('http://localhost:8000?error=Path+does+not+exist.', reportsToWindow: true);

        expect(runPendingCliCommandListener()->navigations)
            ->toBe(['http://localhost:8000?error=Path+does+not+exist.']);
    });

    it('shows the page as usual when it is switched off', function () {
        headlessModeIs(false);
        cliCommandResolvesTo('http://localhost:8000/projects/some-uuid');

        expect(runPendingCliCommandListener()->navigations)
            ->toBe(['http://localhost:8000/projects/some-uuid']);
    });

    it('shows the page when the settings cannot be read', function () {
        // the safe answer here is the visible one — a broken file must not be able to silence the app
        $this->mock(SettingsService::class, function (MockInterface $mock) {
            $mock->shouldReceive('loadSettings')
                ->andThrow(new InvalidSettingsFile('.laborforest/settings.yaml', ['broken']));
        });

        cliCommandResolvesTo('http://localhost:8000/projects/some-uuid');

        expect(runPendingCliCommandListener()->navigations)
            ->toBe(['http://localhost:8000/projects/some-uuid']);
    });
});

/**
 * Auto-discovery also scans app/Listeners, so an explicit registration alongside a concrete event
 * type hint can bind the listener twice and run the command twice.
 */
it('is bound to the deeplink event exactly once', function () {
    expect(app('events')->getListeners(OpenedFromURL::class))->toHaveCount(1);
});

/**
 * Handle a deeplink with a listener that records navigation instead of performing it, because
 * there is no Electron runtime under test.
 */
function runPendingCliCommandListener(): FakeRunPendingCliCommand
{
    $listener = new FakeRunPendingCliCommand;

    $listener->handle(new OpenedFromURL('laborforest://add-project'));

    return $listener;
}

/**
 * A pending request that resolved to the given page.
 */
function cliCommandResolvesTo(string $url, bool $reportsToWindow = false): void
{
    test()->mock(CliToolsService::class, function (MockInterface $mock) use ($url, $reportsToWindow) {
        $mock->shouldReceive('runPendingCommand')
            ->once()
            ->andReturn(new PendingCliCommandResultData($url, $reportsToWindow));
    });
}

function headlessModeIs(bool $headless): void
{
    test()->mock(SettingsService::class, function (MockInterface $mock) use ($headless) {
        $mock->shouldReceive('loadSettings')->andReturn(new SettingsData(headless: $headless));
    });
}
