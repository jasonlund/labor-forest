<?php

namespace App\Data;

use Spatie\LaravelData\Data;

/**
 * The outcome of a request the `lf` script left behind: the page to land on, and whether the
 * window is the only place that outcome can be told to the user.
 *
 * The second part exists for headless mode, which keeps the window out of the way of work the
 * user started from the CLI. A run that was dispatched, or a project that was added, has already
 * happened and the page is only somewhere to look; a failure, and the result of `lf validate`,
 * exist nowhere but the notification the page carries, so suppressing the window would throw
 * them away.
 */
class PendingCliCommandResultData extends Data
{
    public function __construct(
        public string $url,
        public bool $reportsToWindow,
    ) {}
}
