<?php
declare(strict_types=1);

namespace App\Tenant;

use App\Graph\UserProfile;

/**
 * Computes the value of the {email} token when the FROM is a shared mailbox.
 *
 * Two modes are supported, configurable per tenant via
 * `tenants.shared_display_mode`:
 *
 *   - MODE_SHARED  ('shared')
 *       Use the shared mailbox address as-is. Standard Microsoft pattern;
 *       signatures show e.g. `sales@acme.com` so replies land back in the
 *       team mailbox even though the composer is a real user.
 *
 *   - MODE_DERIVED ('derived')
 *       Compute a personal-looking address from the original sender's name
 *       and the from-domain, e.g. Anna Beispiel sending from
 *       `info@acme.com` → `a.beispiel@acme.com`. Useful when the team
 *       mailbox should appear personal in outgoing mail.
 *
 * For non-shared (personal) mailboxes the deriver is not consulted —
 * SignatureService just uses the profile's own `mail` / `userPrincipalName`.
 */
final class DisplayEmailDeriver
{
    public const MODE_SHARED  = 'shared';
    public const MODE_DERIVED = 'derived';

    public function deriveForSharedMailbox(
        UserProfile $primary,
        string $fromEmail,
        string $mode = self::MODE_SHARED,
    ): string {
        if ($mode === self::MODE_DERIVED) {
            $derived = $this->deriveInitialSurname($primary, $this->domainOf($fromEmail));
            if ($derived !== null) {
                return $derived;
            }
            // Fall through to the shared address when the derivation can't
            // be applied (missing surname / from-domain). A real address
            // beats a half-formed alias.
        }

        return $fromEmail;
    }

    /**
     * Build `<initial>.<surname>@<from-domain>`, lowercase.
     * Returns null if any required piece is missing.
     */
    private function deriveInitialSurname(UserProfile $primary, string $domain): ?string
    {
        $surname = $primary->surname !== null ? mb_strtolower(trim($primary->surname)) : '';
        $given   = $primary->givenName !== null ? trim($primary->givenName) : '';

        if ($domain === '' || $surname === '' || $given === '') {
            return null;
        }

        $initial = mb_strtolower(mb_substr($given, 0, 1));
        $surname = preg_replace('/\s+/u', '-', $surname) ?? $surname;
        return "{$initial}.{$surname}@{$domain}";
    }

    private function domainOf(string $email): string
    {
        $at = strrchr($email, '@');
        return $at === false ? '' : mb_strtolower(substr($at, 1));
    }
}
