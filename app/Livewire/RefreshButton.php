<?php

namespace App\Livewire;

use App\Events\GlobalRefresh;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The topbar reload button, rendered on every panel page through a render hook.
 *
 * It owns its own click because the hook renders inside whichever page component is showing, so a
 * bare wire:click would have to land on Dashboard, Project, Settings and every page after them.
 */
class RefreshButton extends Component
{
    /**
     * Reload for a refresh nobody in this window asked for — an MCP tool, or the approval modal.
     *
     * A bare reload raises the app: NativePHP's Electron plugin shows the window from its
     * did-finish-load handler (`resources/electron/electron-plugin/src/server/api/window.ts`), and
     * on macOS showing it activates it over whatever the user is actually working in. Its
     * NATIVEPHP_NO_FOCUS escape hatch is unreachable here — it is passed only on the
     * `native:run --no-focus` dev path, never to a packaged app — so the gate is ours: an unfocused
     * window defers its reload to the next time the user looks at it. The flag is what stops a
     * second broadcast stacking a second listener while they are away.
     *
     * The deferred path flushes at reload time rather than now, so an entry cannot refill in the
     * gap between the two.
     */
    #[On('native:'.GlobalRefresh::class)]
    public function globalRefresh(): void
    {
        $this->js(<<<'JS'
const reload = () => $wire.flushCache().then(() => window.location.reload()); if (document.hasFocus()) { reload() } else if (! window.__lfPendingRefresh) { window.__lfPendingRefresh = true; window.addEventListener('focus', reload, { once: true }) }
JS);
    }

    /**
     * Empty the cache so the page that follows is not answered from the entry it is refreshing —
     * AppVersionWidget would otherwise keep reading the release it looked up; up to 15 minutes ago.
     */
    public function flushCache(): void
    {
        Cache::flush();
    }

    public function render(): View
    {
        return view('livewire.refresh-button');
    }
}
