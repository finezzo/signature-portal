<?php
declare(strict_types=1);

namespace App\Tenant;

/**
 * Classifies a recipient list against the tenant's own-domain set.
 *
 * - "external" scope matches if AT LEAST ONE recipient is outside the
 *   tenant's domains. (The common "treat any external recipient as an
 *   external send" intent — matches what most signature managers do.)
 * - "internal" scope matches only if EVERY recipient is in the tenant's
 *   domains AND there is at least one recipient.
 * - "all" always matches.
 *
 * If no recipients are supplied (e.g. early in compose), "external" and
 * "internal" both fail to match and the rule is skipped — only "all" rules
 * apply until the first recipient is added.
 */
final class RecipientClassifier
{
    /**
     * @param list<string> $recipients
     * @param list<string> $ownDomains
     */
    public function scopeMatches(string $scope, array $recipients, array $ownDomains): bool
    {
        if ($scope === Rule::SCOPE_ALL) {
            return true;
        }
        if ($recipients === []) {
            return false;
        }

        $own = array_flip(array_map('mb_strtolower', $ownDomains));

        $hasExternal = false;
        $hasInternal = false;
        foreach ($recipients as $email) {
            $domain = $this->domainOf($email);
            if ($domain === '') {
                continue;
            }
            if (isset($own[$domain])) {
                $hasInternal = true;
            } else {
                $hasExternal = true;
            }
        }

        return match ($scope) {
            Rule::SCOPE_EXTERNAL => $hasExternal,
            Rule::SCOPE_INTERNAL => $hasInternal && !$hasExternal,
            default              => false,
        };
    }

    private function domainOf(string $email): string
    {
        $at = strrchr($email, '@');
        return $at === false ? '' : mb_strtolower(trim(substr($at, 1)));
    }
}
