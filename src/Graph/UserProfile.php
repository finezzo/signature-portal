<?php
declare(strict_types=1);

namespace App\Graph;

/**
 * A Microsoft Graph /users response, mapped for the signature renderer.
 *
 * Beyond the typed fields used by the engine itself (mailbox detection,
 * language, display email derivation), the raw response is kept around
 * so every Graph property can be exposed as a template token without a
 * code change per field — the GraphClient $select decides what's
 * available, and `tokens()` flattens it into a `{snake_case}` map.
 */
final class UserProfile
{
    /**
     * @param array<string,mixed> $raw  the Graph response as decoded JSON
     */
    public function __construct(
        public readonly string $id,
        public readonly string $userPrincipalName,
        public readonly ?string $mail,
        public readonly ?string $displayName,
        public readonly ?string $givenName,
        public readonly ?string $surname,
        public readonly ?string $jobTitle,
        public readonly ?string $mobilePhone,
        /** @var list<string> */
        public readonly array $businessPhones,
        public readonly ?string $preferredLanguage,
        public readonly bool $isLicensed,
        public readonly bool $accountEnabled,
        public readonly array $raw,
    ) {}

    /** @param array<string,mixed> $a */
    public static function fromGraphResponse(array $a): self
    {
        $bp = $a['businessPhones'] ?? [];
        if (!is_array($bp)) $bp = [];

        $licenses = $a['assignedLicenses'] ?? [];
        $isLicensed = is_array($licenses) && $licenses !== [];

        $accountEnabled = array_key_exists('accountEnabled', $a)
            ? (bool) $a['accountEnabled']
            : true;

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
            $isLicensed,
            $accountEnabled,
            $a,
        );
    }

    /**
     * Flat token map for template substitution.
     *
     * Every scalar (or list of scalars) in the raw Graph response becomes
     * `{property_name}` in snake_case. Plus a few computed convenience
     * tokens (`{phone_lines}`, `{job_title_line}`, `{full_address}`).
     *
     * The `$emailOverride` parameter wins over `mail` / `userPrincipalName`
     * for the `{email}` token — used for the shared-mailbox display logic.
     *
     * @return array<string,string>
     */
    public function tokens(?string $emailOverride = null): array
    {
        // Every value is HTML-escaped here, at the single point where the token
        // map is produced, because the renderer substitutes tokens verbatim
        // (strtr, no encoding) into already-sanitized signature HTML. The
        // template passes through HTML Purifier on save, but the token *values*
        // come from Microsoft Graph — displayName, jobTitle, aboutMe, etc. are
        // often self-service attributes, so an unescaped value like
        // `<img src=x onerror=…>` would inject markup into the delivered mail,
        // bypassing Purifier entirely. Escaping at the source keeps every
        // current and future Graph attribute safe by default.
        //
        // `phone_lines` is the sole exception: it is assembled as HTML
        // (<br>-separated, each value individually escaped) and must not be
        // re-escaped here, or the `<br>` would show up literally.
        $tokens = [];

        // 1. Pass through every Graph property in snake_case form.
        foreach ($this->raw as $key => $value) {
            $tokens[self::camelToSnake((string) $key)] = self::esc(self::stringifyValue($value));
        }

        // 2. Convenience aliases / computed tokens, retained for back-compat.
        $tokens['display_name']   = self::esc((string) ($this->displayName
            ?? trim(($this->givenName ?? '') . ' ' . ($this->surname ?? ''))));
        $tokens['first_name']     = self::esc((string) ($this->givenName ?? ''));
        $tokens['last_name']      = self::esc((string) ($this->surname ?? ''));
        $tokens['email']          = self::esc((string) ($emailOverride ?? $this->mail ?? $this->userPrincipalName));
        $tokens['job_title_line'] = self::esc((string) ($this->jobTitle ?? ''));
        $tokens['phone_lines']    = self::buildPhoneLines($this->mobilePhone, $this->businessPhones);
        $tokens['full_address']   = self::esc(self::buildFullAddress($this->raw));

        return $tokens;
    }

    /** HTML-escape a token value for safe substitution into signature markup. */
    private static function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Indicates this Graph object is most likely a shared/resource mailbox
     * rather than a real user. Heuristic: no assigned license. Reliable in
     * the M365/Exchange Online world because shared mailboxes never carry
     * licenses and personal mailboxes always do.
     */
    public function looksLikeSharedMailbox(): bool
    {
        return !$this->isLicensed;
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

    // ---------- helpers ----------

    /** Convert any Graph value to a single string suitable for token substitution. */
    private static function stringifyValue(mixed $v): string
    {
        if ($v === null) return '';
        if (is_string($v)) return $v;
        if (is_bool($v))  return $v ? 'true' : 'false';
        if (is_scalar($v)) return (string) $v;
        if (is_array($v)) {
            // Numeric list of scalars → join with comma.
            $isList = array_keys($v) === range(0, count($v) - 1);
            if ($isList) {
                $strs = array_filter(array_map(
                    static fn($x) => is_scalar($x) ? (string) $x : '',
                    $v
                ));
                return implode(', ', $strs);
            }
            // Non-list assoc → not a useful single token; return empty.
            return '';
        }
        return '';
    }

    /** PascalOrCamelCase → snake_case (BusinessPhones → business_phones). */
    private static function camelToSnake(string $s): string
    {
        $out = preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $s) ?? $s;
        return mb_strtolower($out);
    }

    /** @param list<string> $businessPhones */
    private static function buildPhoneLines(?string $mobile, array $businessPhones): string
    {
        $lines = [];
        $primaryBusiness = $businessPhones[0] ?? null;
        if ($primaryBusiness !== null && $primaryBusiness !== '') {
            $lines[] = 'Tel ' . htmlspecialchars($primaryBusiness, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        if ($mobile !== null && $mobile !== '') {
            $lines[] = 'Mob ' . htmlspecialchars($mobile, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        return implode('<br>', $lines);
    }

    /** @param array<string,mixed> $raw */
    private static function buildFullAddress(array $raw): string
    {
        $street  = (string) ($raw['streetAddress'] ?? '');
        $postal  = (string) ($raw['postalCode']    ?? '');
        $city    = (string) ($raw['city']          ?? '');
        $country = (string) ($raw['country']       ?? '');

        $parts = [];
        if ($street !== '') $parts[] = $street;
        $line2 = trim($postal . ' ' . $city);
        if ($line2 !== '') $parts[] = $line2;
        if ($country !== '') $parts[] = $country;

        return implode(', ', $parts);
    }
}
