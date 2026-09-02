<?php

namespace App\Listeners;

use App\Enums\WindowId;
use App\Services\CliToolsService;
use App\Services\SettingsService;
use Native\Desktop\Events\App\OpenedFromURL;
use Native\Desktop\Facades\Window;
use Native\Desktop\Windows\Window as NativeWindow;

/**
 * Pick up a request from the `lf` script while the app is already running.
 *
 * A cold launch is handled in NativeAppServiceProvider instead: its deeplink fires before the PHP
 * server is listening, and NativePHP's notifyLaravel() swallows the failure.
 */
class RunPendingCliCommand
{
    /**
     * The event carries the deeplink URL, but it is only a wake trigger — the request itself
     * travels through ~/.laborforest/pending.yaml.
     *
     * The command runs either way; headless mode only decides whether the window is summoned to
     * show the page it resolved to, and never withholds one the window is the only place to see.
     */
    public function handle(OpenedFromURL $event): void
    {
        $result = app(CliToolsService::class)->runPendingCommand();

        if ($result === null) {
            return;
        }

        if (! $result->reportsToWindow && $this->isHeadless()) {
            return;
        }

        $this->navigateTo($result->url);
    }

    /**
     * Whether the user asked the app to stay out of the way of work started somewhere else.
     *
     * Settings that cannot be read answer `false`. Unlike the MCP gates, the safe answer here is
     * the visible one: a broken settings file should not be able to silence the app.
     */
    private function isHeadless(): bool
    {
        return rescue(fn (): bool => app(SettingsService::class)->loadSettings()->headless, false);
    }

    /**
     * Show the window on the given page, opening it if the app is running without one.
     *
     * The window is addressed by id rather than through Window::current(), which asks Electron for
     * the *focused* window and dies on `null.id` whenever nothing has focus. Re-opening an id that
     * already exists is how NativePHP exposes show() + focus().
     *
     * Overridden in tests, which have no Electron runtime to talk to.
     */
    protected function navigateTo(string $url): void
    {
        $window = collect(Window::all())
            ->first(fn (NativeWindow $window): bool => $window->getId() === WindowId::MAIN->value);

        if ($window === null) {
            Window::open(WindowId::MAIN->value)->url($url)->maximized();

            return;
        }

        $window->url($url);

        Window::open(WindowId::MAIN->value);
    }
}
