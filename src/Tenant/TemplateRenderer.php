<?php
declare(strict_types=1);

namespace App\Tenant;

use HTMLPurifier;
use HTMLPurifier_Config;

/**
 * Sanitizes signature HTML on save and renders it with token substitution
 * at delivery time.
 *
 * Tokens are simple `{name}` placeholders. Token names map to the values
 * passed in the substitution map; missing tokens are left as `{name}` so
 * the issue is visible in preview rather than silently dropped.
 *
 * Inline CSS is allowed because Outlook signatures rely on it.
 */
final class TemplateRenderer
{
    private ?HTMLPurifier $purifier = null;

    public function __construct(private readonly string $purifierCacheDir) {}

    public function sanitize(string $html): string
    {
        return $this->purifier()->purify($html);
    }

    /**
     * @param array<string,string> $tokens
     *
     * Substitutes both the literal `{token}` and the URL-encoded form
     * `%7Btoken%7D` — HTML Purifier encodes the curly braces when they
     * appear inside URL attributes (e.g. `mailto:{email}` → `mailto:%7Bemail%7D`),
     * so a single literal-form pass would miss those.
     */
    public function render(string $sanitizedHtml, array $tokens): string
    {
        $map = [];
        foreach ($tokens as $k => $v) {
            $map['{' . $k . '}']        = $v;
            $map['%7B' . $k . '%7D']    = $v;
            $map['%7b' . $k . '%7d']    = $v;
        }
        return strtr($sanitizedHtml, $map);
    }

    /** Default sample data for previews and the simulator. */
    public static function sampleTokens(): array
    {
        return [
            'first_name'     => 'Anna',
            'last_name'      => 'Beispiel',
            'display_name'   => 'Anna Beispiel',
            'email'          => 'anna.beispiel@example.com',
            'job_title_line' => 'Head of Customer Success',
            'phone_lines'    => 'Tel +49 30 123 456-78<br>Mob +49 170 123 4567',
        ];
    }

    /**
     * Maps a Graph user profile to the standard token map.
     *
     * @param string|null $emailOverride  optional override for the {email}
     *                                    token — used when the FROM is a
     *                                    shared mailbox and we want the
     *                                    derived alias rather than the
     *                                    primary user's own address.
     * @return array<string,string>
     */
    public static function tokensFromProfile(\App\Graph\UserProfile $p, ?string $emailOverride = null): array
    {
        $phoneLines = self::buildPhoneLines($p->mobilePhone, $p->businessPhones);
        return [
            'first_name'     => $p->givenName    ?? '',
            'last_name'      => $p->surname      ?? '',
            'display_name'   => $p->displayName  ?? trim(($p->givenName ?? '') . ' ' . ($p->surname ?? '')),
            'email'          => $emailOverride ?? $p->mail ?? $p->userPrincipalName,
            'job_title_line' => $p->jobTitle     ?? '',
            'phone_lines'    => $phoneLines,
        ];
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

    private function purifier(): HTMLPurifier
    {
        if ($this->purifier !== null) {
            return $this->purifier;
        }
        if (!is_dir($this->purifierCacheDir)) {
            @mkdir($this->purifierCacheDir, 0775, true);
        }
        $cfg = HTMLPurifier_Config::createDefault();
        $cfg->set('Cache.SerializerPath', $this->purifierCacheDir);
        $cfg->set('HTML.Doctype', 'HTML 4.01 Transitional');
        $cfg->set('HTML.SafeIframe', false);
        $cfg->set('Attr.AllowedFrameTargets', ['_blank']);
        $cfg->set('CSS.AllowTricky', true);
        $cfg->set('HTML.TargetBlank', true);
        // Outlook-friendly subset; permissive enough for typical signatures.
        $cfg->set('HTML.Allowed',
            'p,br,hr,strong,em,b,i,u,s,sub,sup,small,'
            . 'span[style|class],div[style|class],'
            . 'a[href|title|target|style],'
            . 'img[src|alt|title|width|height|style],'
            . 'table[style|cellpadding|cellspacing|border|width|class|align|bgcolor],'
            . 'thead,tbody,tr[style],td[style|colspan|rowspan|align|valign|width|height|bgcolor],'
            . 'th[style|colspan|rowspan|align|valign|width|height|bgcolor],'
            . 'ul,ol,li,blockquote,'
            . 'h1,h2,h3,h4,h5,h6,'
            . 'font[face|size|color]'
        );
        $this->purifier = new HTMLPurifier($cfg);
        return $this->purifier;
    }
}
