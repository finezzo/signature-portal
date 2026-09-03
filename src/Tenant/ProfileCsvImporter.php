<?php
declare(strict_types=1);

namespace App\Tenant;

/**
 * Parses the CSV the Entra admin center produces via "Users → Download users"
 * into Graph-shaped profile rows, so a tenant can bulk-seed the local profile
 * store without ever calling Graph.
 *
 * This class is a pure function: CSV text in, normalized rows plus per-line
 * errors out. It touches no database and no request state — a controller feeds
 * the rows into the repository and shows the errors back to the admin.
 *
 * The produced `raw` arrays are shaped exactly like a Graph /users response so
 * that {@see \App\Graph\UserProfile::fromGraphResponse()} can consume them
 * unchanged.
 */
final class ProfileCsvImporter
{
    /**
     * Header cell (lower-cased) → Graph property name used as the `raw` key.
     * Anything not listed here is silently ignored, because the Entra export
     * carries a few dozen columns the signature renderer has no use for.
     *
     * `telephoneNumber` and `accountEnabled` are listed for recognition but
     * handled specially below — they do not map 1:1 onto a raw string value.
     *
     * @var array<string,string>
     */
    private const COLUMNS = [
        'userprincipalname' => 'userPrincipalName',
        'displayname'       => 'displayName',
        'givenname'         => 'givenName',
        'surname'           => 'surname',
        'mail'              => 'mail',
        'jobtitle'          => 'jobTitle',
        'department'        => 'department',
        'companyname'       => 'companyName',
        'officelocation'    => 'officeLocation',
        'mobilephone'       => 'mobilePhone',
        'streetaddress'     => 'streetAddress',
        'city'              => 'city',
        'state'             => 'state',
        'postalcode'        => 'postalCode',
        'country'           => 'country',
        'usagelocation'     => 'usageLocation',
        'employeeid'        => 'employeeId',
        'telephonenumber'   => 'telephoneNumber',
        'accountenabled'    => 'accountEnabled',
    ];

    /** Values that turn `accountEnabled` off; everything else means enabled. */
    private const FALSY = ['false', 'no', '0'];

    /** How many physical lines at the top may precede the header row. */
    private const HEADER_SEARCH_LINES = 3;

    /**
     * @return array{rows: list<array{email: string, raw: array<string, mixed>}>, errors: list<string>}
     */
    public function parse(string $csv): array
    {
        $data = self::stripBom($csv);

        $headerLine = self::findHeaderLine($data);
        if ($headerLine === null) {
            return [
                'rows'   => [],
                'errors' => [
                    'No header row with a userPrincipalName column was found in the first '
                    . self::HEADER_SEARCH_LINES . ' lines — is this an Entra "Download users" export?',
                ],
            ];
        }

        $delimiter = self::sniffDelimiter($headerLine['text']);

        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            return ['rows' => [], 'errors' => ['Could not open a temporary stream to read the CSV.']];
        }
        fwrite($stream, $data);
        rewind($stream);

        /** @var array<string,int> $index email → position in $rows */
        $index  = [];
        /** @var array<string,int> $seenAt email → line it was first read on */
        $seenAt = [];
        /** @var list<array{email: string, raw: array<string, mixed>}> $rows */
        $rows   = [];
        /** @var list<string> $errors */
        $errors = [];

        /** @var array<int,string>|null $columns column position → canonical name */
        $columns = null;
        $lineNo  = 1;

        while (true) {
            $start  = ftell($stream);
            $record = fgetcsv($stream, 0, $delimiter, '"', '');
            if ($record === false) {
                break;
            }
            $end     = ftell($stream);
            $lineOfRecord = $lineNo;
            if ($start !== false && $end !== false) {
                $lineNo += substr_count(substr($data, $start, $end - $start), "\n");
            } else {
                $lineNo++;
            }

            if ($columns === null) {
                // Everything above the header row is preamble (Excel exports and
                // hand-edited files like to carry a title line) and is skipped.
                if ($lineOfRecord < $headerLine['number']) {
                    continue;
                }
                $columns = self::mapHeader($record);
                if (!in_array('userPrincipalName', $columns, true)) {
                    fclose($stream);
                    return [
                        'rows'   => [],
                        'errors' => [
                            'No header row with a userPrincipalName column was found in the first '
                            . self::HEADER_SEARCH_LINES . ' lines — is this an Entra "Download users" export?',
                        ],
                    ];
                }
                continue;
            }

            $cells = self::trimCells($record);
            if ($cells === []) {
                continue; // blank line
            }

            $values = [];
            foreach ($columns as $position => $name) {
                $value = $cells[$position] ?? '';
                if ($value !== '') {
                    $values[$name] = $value;
                }
            }

            $upn = $values['userPrincipalName'] ?? '';
            if ($upn === '' || !str_contains($upn, '@')) {
                $errors[] = $upn === ''
                    ? "Line {$lineOfRecord}: skipped — no userPrincipalName."
                    : "Line {$lineOfRecord}: skipped — userPrincipalName \"{$upn}\" is not an email address.";
                continue;
            }

            $email = mb_strtolower($upn);
            $row   = ['email' => $email, 'raw' => self::buildRaw($upn, $values)];

            if (isset($index[$email])) {
                $errors[]      = "Line {$lineOfRecord}: duplicate of {$email} from line {$seenAt[$email]}"
                    . ' — previous row replaced.';
                $rows[$index[$email]] = $row;
                $seenAt[$email]       = $lineOfRecord;
                continue;
            }

            $index[$email]  = count($rows);
            $seenAt[$email] = $lineOfRecord;
            $rows[]         = $row;
        }

        fclose($stream);

        return ['rows' => array_values($rows), 'errors' => $errors];
    }

    // ---------- helpers ----------

    private static function stripBom(string $csv): string
    {
        return str_starts_with($csv, "\xEF\xBB\xBF") ? substr($csv, 3) : $csv;
    }

    /**
     * The header is the first of the leading lines that mentions
     * userPrincipalName; anything above it is preamble.
     *
     * @return array{number: int, text: string}|null 1-based physical line number
     */
    private static function findHeaderLine(string $data): ?array
    {
        $lines = preg_split("/\r\n|\n|\r/", $data) ?: [];
        $limit = min(self::HEADER_SEARCH_LINES, count($lines));

        for ($i = 0; $i < $limit; $i++) {
            if (str_contains(mb_strtolower($lines[$i]), 'userprincipalname')) {
                return ['number' => $i + 1, 'text' => $lines[$i]];
            }
        }

        return null;
    }

    /**
     * Entra exports are comma-separated, but an admin who opens one in a
     * German-locale Excel and saves it gets semicolons — so whichever
     * character dominates the header line is the delimiter. Comma wins ties.
     */
    private static function sniffDelimiter(string $headerLine): string
    {
        return substr_count($headerLine, ';') > substr_count($headerLine, ',') ? ';' : ',';
    }

    /**
     * @param list<string|null> $record
     * @return array<int,string> column position → canonical name
     */
    private static function mapHeader(array $record): array
    {
        $columns = [];
        foreach ($record as $position => $cell) {
            $key = mb_strtolower(trim((string) $cell));
            if (isset(self::COLUMNS[$key])) {
                $columns[$position] = self::COLUMNS[$key];
            }
        }

        return $columns;
    }

    /**
     * @param list<string|null> $record
     * @return array<int,string> empty when the whole record is blank
     */
    private static function trimCells(array $record): array
    {
        $cells = [];
        $any   = false;
        foreach ($record as $position => $cell) {
            $value           = trim((string) $cell);
            $cells[$position] = $value;
            if ($value !== '') {
                $any = true;
            }
        }

        return $any ? $cells : [];
    }

    /**
     * @param array<string,string> $values canonical name → non-empty cell value
     * @return array<string,mixed>
     */
    private static function buildRaw(string $upn, array $values): array
    {
        $raw = ['userPrincipalName' => $upn];

        foreach ($values as $name => $value) {
            if ($name === 'userPrincipalName' || $name === 'telephoneNumber' || $name === 'accountEnabled') {
                continue;
            }
            $raw[$name] = $value;
        }

        if (isset($values['telephoneNumber'])) {
            $raw['businessPhones'] = [$values['telephoneNumber']];
        }

        if (!isset($raw['mail'])) {
            $raw['mail'] = $upn;
        }

        $raw['accountEnabled'] = !in_array(
            mb_strtolower($values['accountEnabled'] ?? ''),
            self::FALSY,
            true,
        );

        // Load-bearing: UserProfile::looksLikeSharedMailbox() calls anything
        // without an assigned license a shared mailbox. A CSV-imported user is
        // by definition a real person, so every imported row carries a
        // synthetic license — otherwise the rule engine would treat the whole
        // import as shared mailboxes.
        $raw['assignedLicenses'] = [['skuId' => 'local-csv']];

        return $raw;
    }
}
