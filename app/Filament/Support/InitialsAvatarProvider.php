<?php

namespace App\Filament\Support;

use Filament\AvatarProviders\Contracts\AvatarProvider;
use Filament\Models\Contracts\HasAvatar;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Filament's default avatar provider calls an external image service. This one
 * draws the initials locally as an inline SVG: no outbound request, and nothing
 * about a user leaves the server.
 */
class InitialsAvatarProvider implements AvatarProvider
{
    public function get(Model|HasAvatar $record): string
    {
        $name = trim((string) ($record->name ?? ''));

        $initials = collect(preg_split('/\s+/u', $name, -1, PREG_SPLIT_NO_EMPTY))
            ->take(2)
            ->map(fn (string $part) => Str::substr($part, 0, 1))
            ->join('');

        $svg = <<<SVG
            <svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 64 64">
                <rect width="64" height="64" rx="32" fill="#059669"/>
                <text x="32" y="41" font-family="sans-serif" font-size="26" font-weight="600"
                      fill="#ffffff" text-anchor="middle">{$initials}</text>
            </svg>
            SVG;

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
