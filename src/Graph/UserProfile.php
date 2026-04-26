<?php
declare(strict_types=1);

namespace App\Graph;

/**
 * Subset of a Microsoft Graph /users response used by the signature renderer.
 *
 * Field mapping is intentionally narrow — only what the templating layer
 * needs. Extend when adding tokens.
 */
final class UserProfile
{
    /** @param list<string> $businessPhones */
    public function __construct(
        public readonly string $id,
        public readonly string $userPrincipalName,
        public readonly ?string $mail,
        public readonly ?string $displayName,
        public readonly ?string $givenName,
        public readonly ?string $surname,
        public readonly ?string $jobTitle,
        public readonly ?string $mobilePhone,
        public readonly array $businessPhones,
        public readonly ?string $preferredLanguage,
    ) {}

    /** @param array<string,mixed> $a */
    public static function fromGraphResponse(array $a): self
    {
        $bp = $a['businessPhones'] ?? [];
        if (!is_array($bp)) $bp = [];

        return new self(
            (string) ($a['id'] ?? ''),
            (string) ($a['userPrincipalName'] ?? ''),
            isset($a['mail']) ? (string) $a['mail'] : null,
            isset($a['displayName']) ? (string) $a['displayName'] : null,
            isset($a['givenName']) ? (string) $a['givenName'] : null,
            isset($a['surname']) ? (string) $a['surname'] : null,
            isset($a['jobTitle']) ? (string) $a['jobTitle'] : null,
            isset($a['mobilePhone']) ? (string) $a['mobilePhone'] : null,
            array_values(array_map('strval', $bp)),
            isset($a['preferredLanguage']) ? (string) $a['preferredLanguage'] : null,
        );
    }

    /**
     * BCP-47 language tag → 2-letter code, e.g. "de-DE" → "de".
     * Returns null if no preferredLanguage is set.
     */
    public function shortLanguage(): ?string
    {
        if ($this->preferredLanguage === null || $this->preferredLanguage === '') {
            return null;
        }
        $first = explode('-', $this->preferredLanguage, 2)[0];
        return mb_strtolower(trim($first)) ?: null;
    }
}
