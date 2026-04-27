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
        $html = $this->normalizeBlocks($html);
        $html = $this->purifier()->purify($html);
        $html = $this->preserveBlankLines($html);
        $html = $this->applyDefaultBlockSpacing($html);
        return $html;
    }

    /**
     * Outlook's HTML renderer ignores external CSS — only inline styles
     * count. So the editor's "0.35em bottom margin on every paragraph"
     * (from content_style) wouldn't survive into delivered mail unless we
     * baked the same value as an inline style on every <div>/<p>.
     *
     * Existing margin declarations win — Word/Outlook content that came in
     * with `style="margin:0cm"` stays tight (the user explicitly set that
     * via paste), only blocks WITHOUT a margin get our default. That keeps
     * pasted content faithful while making the editor and Outlook agree
     * on plain typed content.
     */
    private function applyDefaultBlockSpacing(string $html): string
    {
        return preg_replace_callback(
            '/<(div|p)(\b[^>]*)?>/i',
            static function (array $m): string {
                $tag   = $m[1];
                $attrs = $m[2] ?? '';

                // Already has a margin (any: top/bottom/left/right/shorthand)? Skip.
                if (preg_match('/style\s*=\s*"[^"]*\bmargin\b/i', $attrs)) {
                    return $m[0];
                }

                if (preg_match('/style\s*=\s*"([^"]*)"/i', $attrs, $sm)) {
                    $existing = rtrim(trim($sm[1]), ';');
                    $newStyle = ($existing !== '' ? $existing . ';' : '') . 'margin:0 0 0.35em 0';
                    $attrs    = preg_replace(
                        '/style\s*=\s*"[^"]*"/i',
                        'style="' . $newStyle . '"',
                        $attrs,
                        1,
                    );
                } else {
                    $attrs = ' style="margin:0 0 0.35em 0"' . $attrs;
                }

                return '<' . $tag . $attrs . '>';
            },
            $html,
        ) ?? $html;
    }

    /**
     * Convert `<p>` to `<div>` (preserving attributes). Email signatures
     * only ever care about visual layout, never about <p>-vs-<div> semantics
     * — and <p>'s 1em browser-default margin causes the classic mismatch
     * where the editor shows tight blocks (our content_style override) but
     * Outlook renders them loose. Standardising on <div> kills that whole
     * class of bug. The editor's own paste filter does the same on the
     * client side; this is the server-side safety net for older content
     * and direct API usage.
     */
    private function normalizeBlocks(string $html): string
    {
        $html = preg_replace('/<p(\s[^>]*)?>/i',  '<div$1>', $html) ?? $html;
        $html = preg_replace('/<\/p\s*>/i',       '</div>',  $html) ?? $html;
        return $html;
    }

    /**
     * Empty `<div></div>` (or `<p></p>`) collapses to zero height in every
     * browser including Outlook — which means a user pressing Enter twice
     * to make a blank line gets *nothing*. Adding a `<br>` inside any empty
     * block forces the browser to render one line of vertical space, so
     * blank lines work the way users expect.
     *
     * `<br>` is preferred over `&nbsp;` because Outlook's WordEditor renders
     * `&nbsp;` with the surrounding paragraph's font-size, which can vary
     * unexpectedly; `<br>` stays one line-height tall everywhere.
     */
    private function preserveBlankLines(string $html): string
    {
        return preg_replace(
            '/<(div|p)(\s[^>]*)?><\/\1\s*>/i',
            '<$1$2><br></$1>',
            $html,
        ) ?? $html;
    }

    /**
     * @param array<string,string> $tokens
     *
     * Two passes:
     *   1. Conditional blocks `{if:NAME}…{/if}` — kept when the named token
     *      has a non-empty value, dropped (including the markers) when empty.
     *      Lets templates avoid dangling labels for missing Graph attributes,
     *      e.g. `{if:mobile_phone}Mob: {mobile_phone}<br>{/if}` shows nothing
     *      when there's no mobile phone, instead of "Mob:" with a blank.
     *   2. Plain token substitution. Both the literal `{token}` and the
     *      URL-encoded form `%7Btoken%7D` are replaced — HTML Purifier
     *      encodes the curly braces when they appear inside URL attributes
     *      (e.g. `mailto:{email}` → `mailto:%7Bemail%7D`), so a single
     *      literal-form pass would miss those.
     */
    public function render(string $sanitizedHtml, array $tokens): string
    {
        $sanitizedHtml = $this->processConditionals($sanitizedHtml, $tokens);

        $map = [];
        foreach ($tokens as $k => $v) {
            $map['{' . $k . '}']        = $v;
            $map['%7B' . $k . '%7D']    = $v;
            $map['%7b' . $k . '%7d']    = $v;
        }
        return strtr($sanitizedHtml, $map);
    }

    /**
     * Resolve `{if:NAME}…{/if}` blocks. Token name is case-insensitive
     * letters/digits/underscore; content is anything (incl. line breaks).
     * Nested conditionals are not supported; one level deep is enough for
     * the use cases we have (suppress an empty-attribute line) and keeps
     * the regex bounded.
     *
     * @param array<string,string> $tokens
     */
    private function processConditionals(string $html, array $tokens): string
    {
        // Two regex passes covering literal and URL-encoded markers.
        $patterns = [
            '/\{if:([a-z0-9_]+)\}(.*?)\{\/if\}/is',
            '/%7Bif:([a-z0-9_]+)%7D(.*?)%7B\/if%7D/is',
        ];
        foreach ($patterns as $pattern) {
            $html = preg_replace_callback(
                $pattern,
                static function (array $m) use ($tokens): string {
                    $name  = mb_strtolower($m[1]);
                    $value = $tokens[$name] ?? '';
                    return trim((string) $value) !== '' ? $m[2] : '';
                },
                $html,
            ) ?? $html;
        }
        return $html;
    }

    /** Default sample data for previews and the simulator. */
    public static function sampleTokens(): array
    {
        return [
            'first_name'      => 'Anna',
            'last_name'       => 'Beispiel',
            'display_name'    => 'Anna Beispiel',
            'email'           => 'anna.beispiel@example.com',
            'job_title_line'  => 'Head of Customer Success',
            'phone_lines'     => 'Tel +49 30 123 456-78<br>Mob +49 170 123 4567',
            // Common Graph fields users may want to use in templates.
            'job_title'       => 'Head of Customer Success',
            'department'      => 'Customer Success',
            'company_name'    => 'Acme GmbH',
            'office_location' => 'Berlin HQ',
            'mobile_phone'    => '+49 170 123 4567',
            'business_phones' => '+49 30 123 456-78',
            'street_address'  => 'Musterstraße 1',
            'postal_code'     => '12345',
            'city'            => 'Berlin',
            'country'         => 'Germany',
            'state'           => 'Berlin',
            'full_address'    => 'Musterstraße 1, 12345 Berlin, Germany',
            'employee_id'     => 'A12345',
            'mail'            => 'anna.beispiel@example.com',
            'user_principal_name' => 'anna.beispiel@example.com',
            'preferred_language'  => 'de-DE',
        ];
    }

    /**
     * Maps a Graph user profile to the full token map.
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
        return $p->tokens($emailOverride);
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
