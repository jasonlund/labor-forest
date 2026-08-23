<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum McpActionPendingApprovalType: string implements HasLabel
{
    case LAUNCH_TERMINAL = 'launch-terminal';
    case LAUNCH_IDE = 'launch-ide';
    case LAUNCH_BROWSER = 'launch-browser';
    case RUN_WORKFLOW = 'run-workflow';

    public function getLabel(): string
    {
        return str_replace('-', ' ', $this->value);
    }
}
