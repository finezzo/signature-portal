<?php
declare(strict_types=1);

namespace App\Tenant;

/**
 * Pre-built signature building blocks. Inserted into the editor at the
 * cursor — unlike StarterTemplates these are partials, not whole templates.
 *
 * Outlook-friendly: tables instead of flexbox, inline styles, no external
 * CSS, sizes in px or pt to render predictably across clients.
 */
final class SnippetLibrary
{
    /**
     * @return list<array{id:string,name:string,description:string,html:string}>
     */
    public static function all(): array
    {
        return [
            self::twoColumnLogoLeft(),
            self::twoColumnLogoRight(),
            self::dividerLine(),
            self::socialIconsRow(),
            self::contactBlock(),
            self::legalFooter(),
            self::responsiveBanner(),
        ];
    }

    public static function find(string $id): ?array
    {
        foreach (self::all() as $s) {
            if ($s['id'] === $id) {
                return $s;
            }
        }
        return null;
    }

    private static function twoColumnLogoLeft(): array
    {
        return [
            'id'          => 'two-col-logo-left',
            'name'        => 'Logo left, details right',
            'description' => 'Two-column table with placeholder logo on the left and contact details on the right.',
            'html'        => <<<HTML
<table cellpadding="0" cellspacing="0" border="0" style="font-family:Arial,sans-serif;font-size:13px;color:#333;">
  <tr>
    <td style="padding-right:16px;vertical-align:top;">
      <img src="https://placehold.co/120x40/2f6feb/ffffff?text=LOGO" alt="" width="120" height="40" style="display:block;border:0;">
    </td>
    <td style="vertical-align:top;border-left:1px solid #e3e6eb;padding-left:16px;">
      <strong style="font-size:14px;">{display_name}</strong><br>
      <span style="color:#6a737d;">{job_title_line}</span><br>
      {if:phone_lines}{phone_lines}<br>{/if}
      <a href="mailto:{email}" style="color:#2f6feb;">{email}</a>
    </td>
  </tr>
</table>
HTML,
        ];
    }

    private static function twoColumnLogoRight(): array
    {
        return [
            'id'          => 'two-col-logo-right',
            'name'        => 'Details left, logo right',
            'description' => 'Mirror of the previous — name on the left, logo on the right.',
            'html'        => <<<HTML
<table cellpadding="0" cellspacing="0" border="0" style="font-family:Arial,sans-serif;font-size:13px;color:#333;">
  <tr>
    <td style="vertical-align:top;padding-right:16px;border-right:1px solid #e3e6eb;">
      <strong style="font-size:14px;">{display_name}</strong><br>
      <span style="color:#6a737d;">{job_title_line}</span><br>
      {if:phone_lines}{phone_lines}<br>{/if}
      <a href="mailto:{email}" style="color:#2f6feb;">{email}</a>
    </td>
    <td style="vertical-align:top;padding-left:16px;">
      <img src="https://placehold.co/120x40/2f6feb/ffffff?text=LOGO" alt="" width="120" height="40" style="display:block;border:0;">
    </td>
  </tr>
</table>
HTML,
        ];
    }

    private static function dividerLine(): array
    {
        return [
            'id'          => 'divider-line',
            'name'        => 'Divider line',
            'description' => 'Thin horizontal divider with vertical spacing — for separating header from body.',
            'html'        => '<hr style="border:0;border-top:1px solid #d0d7de;margin:8px 0;">',
        ];
    }

    private static function socialIconsRow(): array
    {
        return [
            'id'          => 'social-icons',
            'name'        => 'Social links row',
            'description' => 'Compact row of LinkedIn / XING / website links with text separators (no external icon hosting needed).',
            'html'        => <<<HTML
<div style="font-family:Arial,sans-serif;font-size:12px;color:#6a737d;margin-top:6px;">
  <a href="https://linkedin.com/in/" style="color:#2f6feb;text-decoration:none;">LinkedIn</a>
  <span style="margin:0 6px;color:#9ca3af;">·</span>
  <a href="https://xing.com/profile/" style="color:#2f6feb;text-decoration:none;">XING</a>
  <span style="margin:0 6px;color:#9ca3af;">·</span>
  <a href="https://example.com" style="color:#2f6feb;text-decoration:none;">www.example.com</a>
</div>
HTML,
        ];
    }

    private static function contactBlock(): array
    {
        return [
            'id'          => 'contact-block',
            'name'        => 'Contact block (with conditionals)',
            'description' => 'Stacked phone/mobile/email lines that hide automatically when an attribute is missing.',
            'html'        => <<<HTML
<div style="font-family:Arial,sans-serif;font-size:13px;color:#333;line-height:1.4;">
  {if:business_phones}<span>Tel: {business_phones}</span><br>{/if}
  {if:mobile_phone}<span>Mob: {mobile_phone}</span><br>{/if}
  {if:fax_number}<span>Fax: {fax_number}</span><br>{/if}
  <a href="mailto:{email}" style="color:#2f6feb;">{email}</a>
</div>
HTML,
        ];
    }

    private static function legalFooter(): array
    {
        return [
            'id'          => 'legal-footer',
            'name'        => 'Legal disclaimer footer',
            'description' => 'Small grey print for HRB / Geschäftsführer / impressum lines (DE). Edit the placeholders.',
            'html'        => <<<HTML
<div style="font-size:10px;color:#999;border-top:1px solid #e3e6eb;margin-top:12px;padding-top:8px;line-height:1.4;font-family:Arial,sans-serif;">
  <strong>Acme GmbH</strong> · Musterstraße 1 · 12345 Berlin · Deutschland<br>
  Geschäftsführer: Anna Beispiel · Amtsgericht Berlin HRB 99999 B · USt-IdNr. DE000000000<br>
  Diese E-Mail kann vertrauliche Informationen enthalten — bitte nur an die genannten Empfänger weiterleiten.
</div>
HTML,
        ];
    }

    private static function responsiveBanner(): array
    {
        return [
            'id'          => 'event-banner',
            'name'        => 'Promo / event banner',
            'description' => 'Wide banner image with link — for season campaigns. Pair with a date-range rule.',
            'html'        => <<<HTML
<table cellpadding="0" cellspacing="0" border="0" style="margin-top:12px;">
  <tr>
    <td>
      <a href="https://example.com/event">
        <img src="https://placehold.co/600x80/2f6feb/ffffff?text=Banner+600x80"
             alt="Banner" width="600" height="80" style="display:block;border:0;">
      </a>
    </td>
  </tr>
</table>
HTML,
        ];
    }
}
