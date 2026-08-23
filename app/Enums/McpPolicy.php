<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum McpPolicy: string implements HasLabel
{
    case ALLOW = 'allow';
    case REQUIRE_APPROVAL = 'require-approval';
    case DENY = 'deny';

    public function getLabel(): string
    {
        return ucfirst(str_replace('-', ' ', $this->value));
    }
}
