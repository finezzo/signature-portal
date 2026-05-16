<?php
declare(strict_types=1);

namespace App\Tenant;

use App\Graph\UserProfile;

/**
 * Computes the value of the {email} token when the FROM is a shared mailbox.
 *
 * Three modes are supported, configurable per tenant via
 * `tenants.shared_display_mode`:
 *
 *   - MODE_SHARED  ('shared')   — default
 *       Use the shared mailbox address as-is. Standard Microsoft pattern;
 *       signatures show e.g. `sales@acme.com` so replies land back in the
 *       team mailbox even though the composer is a real user.
 *
 *   - MODE_PRIMARY ('primary')
 *       Use the *primary user's actual Graph email* (their `mail` or UPN).
 *       Useful when the team mailbox sends but you want the recipient to
 *       reply to the human directly. The address is real (it exists in
 *       Entra), unlike MODE_DERIVED which fabricates one.
 *
 *   - MODE_DERIVED ('derived')
 *       Compute a personal-looking address from the original sender's name
 *       and the from-domain, e.g. Anna Beispiel sending from
 *       `info@acme.com` → `a.beispiel@acme.com`. Convention-based; the
 *       computed address may or may not exist as a real mailbox.
 *
 * For non-shared (personal) mailboxes the deriver is not consulted —
 * SignatureService just uses the profile's own `mail` / `userPrincipalName`.
 */
final class DisplayEmailDeriver
{
    public const MODE_SHARED  = 'shared';
    public const MODE_PRIMARY = 'primary';
    public const MODE_DERIVED = 'derived';

    public function deriveForSharedMailbox(
        UserProfile $primary,
        string $fromEmail,
        string $mode = self::MODE_SHARED,
    ): string {
        if ($mode === self::MODE_PRIMARY) {
            $real = $primary->mail !== null && $primary->mail !== ''
                ? $primary->mail
                : $primary->userPrincipalName;
            if ($real !== '') {
                return $real;
            }
            // Fall through to the shared address if Graph somehow returned
            // a user with no mail and no UPN (shouldn't happen, but be safe).
        }

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
