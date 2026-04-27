<?php
declare(strict_types=1);

namespace App\Tenant;

/**
 * Hand-crafted, Outlook-friendly signature templates that admins can use as
 * a starting point when creating a new template. Pure HTML tables + inline
 * CSS — no flexbox/grid, no external CSS.
 *
 * Tokens used: {display_name}, {first_name}, {last_name}, {job_title_line},
 * {phone_lines}, {email}.
 */
final class StarterTemplates
{
    /**
     * @return list<array{id:string,name:string,description:string,html:string}>
     */
    public static function all(): array
    {
        return [
            self::minimal(),
            self::twoColumnDivider(),
            self::leftLogo(),
            self::compactOneLine(),
            self::bilingualDeEn(),
        ];
    }

    public static function find(string $id): ?array
    {
        foreach (self::all() as $t) {
            if ($t['id'] === $id) {
                return $t;
            }
        }
        return null;
    }

    private static function minimal(): array
    {
        return [
            'id'          => 'minimal',
            'name'        => 'Minimal — name + email',
            'description' => 'Plain three-line signature. Good for internal-only use or as a fallback.',
            'html'        => <<<HTML
<div style="font-family:Arial,sans-serif;font-size:13px;color:#333;line-height:1.5;">
  <strong>{display_name}</strong><br>
  {job_title_line}<br>
  <a href="mailto:{email}" style="color:#2f6feb;">{email}</a>
</div>
HTML,
        ];
    }

    private static function twoColumnDivider(): array
    {
        return [
            'id'          => 'two-column-divider',
            'name'        => 'Two-column with vertical divider',
            'description' => 'Name + title on the left, contact details on the right, accent-coloured divider in between.',
            'html'        => <<<HTML
<table cellpadding="0" cellspacing="0" border="0" style="font-family:Arial,sans-serif;font-size:13px;color:#333;">
  <tr>
    <td style="padding-right:12px;border-right:2px solid #2f6feb;">
      <strong style="font-size:15px;">{display_name}</strong><br>
      <span style="color:#6a737d;">{job_title_line}</span>
    </td>
    <td style="padding-left:12px;">
      {phone_lines}<br>
      <a href="mailto:{email}" style="color:#2f6feb;">{email}</a>
    </td>
  </tr>
</table>
HTML,
        ];
    }

    private static function leftLogo(): array
    {
        return [
            'id'          => 'left-logo',
            'name'        => 'Logo on the left, details on the right',
            'description' => 'Replace the placeholder image URL with one from the Images library after creating the template.',
            'html'        => <<<HTML
<table cellpadding="0" cellspacing="0" border="0" style="font-family:Arial,sans-serif;font-size:13px;color:#333;">
  <tr>
    <td style="padding-right:16px;vertical-align:top;">
      <!-- Replace src with a URL from the tenant Images library. -->
      <img src="https://placehold.co/120x40/2f6feb/ffffff?text=LOGO"
           alt="" width="120" height="40" style="display:block;border:0;">
    </td>
    <td style="vertical-align:top;border-left:1px solid #e3e6eb;padding-left:16px;">
      <strong style="font-size:14px;">{display_name}</strong><br>
      <span style="color:#6a737d;">{job_title_line}</span><br>
      <span style="display:inline-block;height:6px;"></span><br>
      {phone_lines}<br>
      <a href="mailto:{email}" style="color:#2f6feb;">{email}</a>
    </td>
  </tr>
</table>
HTML,
        ];
    }

    private static function compactOneLine(): array
    {
        return [
            'id'          => 'compact-one-line',
            'name'        => 'Compact — single line',
            'description' => 'Everything on one line, separated by bullets. Useful for short replies and chat-like email.',
            'html'        => <<<HTML
<div style="font-family:Arial,sans-serif;font-size:13px;color:#333;">
  <strong>{display_name}</strong>
  <span style="color:#9ca3af;">·</span>
  {job_title_line}
  <span style="color:#9ca3af;">·</span>
  <a href="mailto:{email}" style="color:#2f6feb;">{email}</a>
</div>
HTML,
        ];
    }

    private static function bilingualDeEn(): array
    {
        return [
            'id'          => 'bilingual-de-en',
            'name'        => 'Bilingual — German + English',
            'description' => 'Name and title shown once; contact lines without language-specific copy. Common in DE/EN corporate communication.',
            'html'        => <<<HTML
<table cellpadding="0" cellspacing="0" border="0" style="font-family:Arial,sans-serif;font-size:13px;color:#333;">
  <tr>
    <td>
      <strong style="font-size:15px;">{display_name}</strong><br>
      <span style="color:#6a737d;">{job_title_line}</span>
      <br><br>
      {phone_lines}<br>
      <a href="mailto:{email}" style="color:#2f6feb;">{email}</a>
      <br><br>
      <span style="color:#9ca3af;font-size:12px;">
        Diese E-Mail kann vertrauliche Informationen enthalten — bitte nur an die genannten Empfänger weiterleiten.<br>
        This email may contain confidential information — please do not forward beyond the named recipients.
      </span>
    </td>
  </tr>
</table>
HTML,
        ];
    }
}
