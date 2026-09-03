<?php
declare(strict_types=1);

namespace App\Graph;

use App\Tenant\ProfileRepository;
use App\Tenant\Tenant;

/**
 * Graph lookups with a local write-through store behind them.
 *
 * Sits between the signature pipeline and GraphClient so the pipeline keeps
 * working when Microsoft's login gateway refuses the hosting IP — a real
 * failure mode on shared hosting, which otherwise turns every /api/sig call
 * into a 502.
 *
 * The contract, in full:
 *
 *   - Graph answers with a profile → the raw response is written through to
 *     `user_profiles` (source 'graph', overwriting any csv/manual row) and
 *     returned as live. Shared mailboxes are cached too: `assignedLicenses: []`
 *     round-trips through the JSON column, so looksLikeSharedMailbox() gives
 *     the same answer from cache as it does live.
 *
 *   - Graph answers 404 → null, and the store is NOT consulted. A 404 is
 *     authoritative: it is how the pipeline detects a legacy shared mailbox
 *     with no Entra object at all, and serving a cached row here would silently
 *     turn that into a personal-mailbox render. The stored row is also NOT
 *     deleted — during an emergency the local copy may be the only data left,
 *     and a single odd 404 must not destroy it.
 *
 *   - Graph throws (token endpoint blocked, network down, tenant credentials
 *     broken) → fall back to the stored profile. On a miss the original
 *     exception is rethrown rather than returning null, because null means
 *     "no such user" to every caller: masking an outage as a 404 would render
 *     the wrong signature or none at all, with no ops signal.
 */
final class ProfileResolver
{
    public function __construct(
        private readonly GraphClient $graph,
        private readonly ProfileRepository $profiles,
    ) {}

    /**
     * @return ResolvedProfile|null null only when Graph says the user does not exist.
     * @throws GraphException when Graph is unreachable and nothing is stored.
     */
    public function resolve(Tenant $tenant, string $email): ?ResolvedProfile
    {
        try {
            $profile = $this->graph->getUserProfile($tenant, $email);
            if ($profile === null) {
                return null;
            }
            $this->profiles->upsertFromGraph($tenant->id, $email, $profile->raw);
            return new ResolvedProfile($profile, false, null);
        } catch (GraphException $e) {
            $stored = $this->profiles->findByEmail($tenant->id, $email);
            if ($stored === null) {
                throw $e;
            }
            return new ResolvedProfile($stored->toUserProfile(), true, $stored->storedAt());
        }
    }

    /**
     * Store-only lookup — never touches Graph. Used as the last-chance fallback
     * once a Graph call has already failed, so a second doomed round-trip (and
     * its timeout) is not paid for.
     */
    public function cached(Tenant $tenant, string $email): ?ResolvedProfile
    {
        $stored = $this->profiles->findByEmail($tenant->id, $email);
        if ($stored === null) {
            return null;
        }
        return new ResolvedProfile($stored->toUserProfile(), true, $stored->storedAt());
    }
}
