<?php
declare(strict_types=1);

namespace App\Mail;

/**
 * Minimal mail sender built on PHP's mail() — the only transport that works
 * on every shared host without extra dependencies or SMTP credentials.
 * Plain-text only: transactional mails (password resets) don't need HTML,
 * and text-only keeps spam scores low.
 *
 * The From address comes from config 'mail.from'; when unset it defaults to
 * no-reply@<host of base_url>, which shared hosts accept as long as the
 * domain is theirs.
 */
final class Mailer
{
    public function __construct(
        private readonly string $fromAddress,
        private readonly string $fromName = 'SignaturePortal',
    ) {}

    public function send(string $to, string $subject, string $textBody): bool
    {
        // Defence against header injection via a crafted recipient/subject.
        if (preg_match('/[\r\n]/', $to . $subject) === 1) {
            return false;
        }

        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $encodedFrom    = '=?UTF-8?B?' . base64_encode($this->fromName) . '?='
                        . ' <' . $this->fromAddress . '>';

        $headers = [
            'From: ' . $encodedFrom,
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            'X-Mailer: SignaturePortal',
        ];

        return mail($to, $encodedSubject, $textBody, implode("\r\n", $headers));
    }

    /** Derive the default From address from the portal's base URL. */
    public static function defaultFrom(string $baseUrl): string
    {
        $host = (string) (parse_url($baseUrl, PHP_URL_HOST) ?: 'localhost');
        return 'no-reply@' . $host;
    }
}
