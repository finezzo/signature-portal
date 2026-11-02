<?php
declare(strict_types=1);

namespace App\Addin;

/**
 * Release channels of the Outlook add-in.
 *
 *   Stable  → public/addin/          what every user runs
 *   Preview → public/addin-preview/  same server, assigned to selected users only
 *   Local   → public/addin-preview/  served from the developer's own machine by
 *                                    tools/addin-dev/server.py
 *
 * Each channel is a separate add-in as far as Outlook is concerned (its own
 * manifest <Id>), so it can be installed for one person while everyone else
 * stays on Stable. A file uploaded to public/addin-preview/ reaches nobody who
 * doesn't have that manifest; bin/addin-promote.php moves it to Stable.
 */
enum AddinChannel: string
{
    case Stable  = 'stable';
    case Preview = 'preview';
    case Local   = 'local';

    public static function fromQuery(string $value): self
    {
        return self::tryFrom($value) ?? self::Stable;
    }

    /** Directory below the web root that holds this channel's runtime files. */
    public function directory(): string
    {
        return $this === self::Stable ? 'addin' : 'addin-preview';
    }

    public function nameSuffix(): string
    {
        return match ($this) {
            self::Stable  => '',
            self::Preview => ' (Preview)',
            self::Local   => ' (Local)',
        };
    }

    /**
     * The add-in id for this channel. Stable keeps the tenant's id. The others
     * derive theirs from it, so they coexist with Stable and a re-download
     * updates the same installation instead of adding another one.
     */
    public function manifestGuid(string $stableGuid): string
    {
        if ($this === self::Stable) {
            return $stableGuid;
        }
        $hash = md5($stableGuid . '|' . $this->value);
        return substr($hash, 0, 8) . '-' . substr($hash, 8, 4)
            . '-4' . substr($hash, 13, 3)
            . '-8' . substr($hash, 17, 3)
            . '-' . substr($hash, 20, 12);
    }
}
