<?php

namespace App\Support\Avatars;

use Filament\AvatarProviders\Contracts\AvatarProvider;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Laravolt\Avatar\Facade as Avatar;

/**
 * Local avatar provider — replaces Filament's default UI Avatars CDN call.
 *
 * Generates a deterministic inline SVG (data: URI) via laravolt/avatar
 * (open-source, server-side). Same look-and-feel as ui-avatars.com but no
 * external network call, GDPR-safe.
 *
 * Security Baseline §15: no third-party assets at runtime.
 */
class LocalAvatarProvider implements AvatarProvider
{
    public function get(Model|Authenticatable $record): string
    {
        $name = (string) ($record->name ?? $record->email ?? 'User');

        $svg = Avatar::create($name)
            ->setDimension(64, 64)
            ->setFontSize(28)
            ->toSvg();

        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }
}
