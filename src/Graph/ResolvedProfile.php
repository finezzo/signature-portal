<?php
declare(strict_types=1);

namespace App\Graph;

/**
 * A profile plus its provenance: live from Graph, or replayed from the local
 * store because Graph was unreachable.
 *
 * The signature pipeline surfaces this to the add-in as X-Sig-Profile-Source
 * so a stale signature is visibly distinguishable from a fresh one.
 */
final class ResolvedProfile
{
    public function __construct(
        public readonly UserProfile $profile,
        public readonly bool $fromCache,
        /** Timestamp the stored copy was last confirmed; null when live. */
        public readonly ?string $storedAt,
    ) {}
}
