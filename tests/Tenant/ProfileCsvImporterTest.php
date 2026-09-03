<?php
declare(strict_types=1);

namespace Tests\Tenant;

use App\Tenant\ProfileCsvImporter;
use PHPUnit\Framework\TestCase;

final class ProfileCsvImporterTest extends TestCase
{
    private const HEADER = 'userPrincipalName,displayName,givenName,surname,jobTitle,telephoneNumber,accountEnabled';

    /**
     * @return array{rows: list<array{email: string, raw: array<string, mixed>}>, errors: list<string>}
     */
    private function parse(string $csv): array
    {
        return (new ProfileCsvImporter())->parse($csv);
    }

    private function sampleCsv(string $delimiter = ','): string
    {
        $lines = [
            ['userPrincipalName', 'displayName', 'givenName', 'surname', 'jobTitle'],
            ['anna.meier@acme.example', 'Anna Meier', 'Anna', 'Meier', 'CEO'],
            ['ben.klein@acme.example', 'Ben Klein', 'Ben', 'Klein', 'CTO'],
        ];

        return implode("\n", array_map(
            static fn(array $cells): string => implode($delimiter, $cells),
            $lines,
        )) . "\n";
    }

    public function testParsesEntraStyleExport(): void
    {
        $result = $this->parse($this->sampleCsv());

        $this->assertSame([], $result['errors']);
        $this->assertCount(2, $result['rows']);
        $this->assertSame('anna.meier@acme.example', $result['rows'][0]['email']);
        $this->assertSame('Anna', $result['rows'][0]['raw']['givenName']);
        $this->assertSame('Meier', $result['rows'][0]['raw']['surname']);
        $this->assertSame('CEO', $result['rows'][0]['raw']['jobTitle']);
        $this->assertSame('ben.klein@acme.example', $result['rows'][1]['email']);
    }

    public function testUtf8BomIsStripped(): void
    {
        $result = $this->parse("\xEF\xBB\xBF" . $this->sampleCsv());

        $this->assertSame([], $result['errors']);
        $this->assertCount(2, $result['rows']);
        $this->assertSame('userPrincipalName', array_key_first($result['rows'][0]['raw']));
        $this->assertSame('anna.meier@acme.example', $result['rows'][0]['raw']['userPrincipalName']);
    }

    public function testSemicolonAndCommaFilesParseIdentically(): void
    {
        $this->assertEquals(
            $this->parse($this->sampleCsv(',')),
            $this->parse($this->sampleCsv(';')),
        );
    }

    public function testHeaderMatchingIsCaseInsensitive(): void
    {
        $csv = "USERPRINCIPALNAME,DisplayName,GIVENNAME\n"
            . "anna.meier@acme.example,Anna Meier,Anna\n";

        $result = $this->parse($csv);

        $this->assertSame([], $result['errors']);
        $this->assertSame('anna.meier@acme.example', $result['rows'][0]['email']);
        $this->assertSame('Anna Meier', $result['rows'][0]['raw']['displayName']);
        $this->assertSame('Anna', $result['rows'][0]['raw']['givenName']);
    }

    public function testTelephoneNumberBecomesBusinessPhonesList(): void
    {
        $csv = self::HEADER . "\n"
            . "anna.meier@acme.example,Anna Meier,Anna,Meier,CEO,+49 30 123456,TRUE\n";

        $raw = $this->parse($csv)['rows'][0]['raw'];

        $this->assertSame(['+49 30 123456'], $raw['businessPhones']);
        $this->assertArrayNotHasKey('telephoneNumber', $raw);
    }

    public function testAccountEnabledFalseIsRecognisedCaseInsensitively(): void
    {
        $csv = self::HEADER . "\n"
            . "anna.meier@acme.example,Anna Meier,Anna,Meier,CEO,,FALSE\n"
            . "ben.klein@acme.example,Ben Klein,Ben,Klein,CTO,,TRUE\n";

        $rows = $this->parse($csv)['rows'];

        $this->assertFalse($rows[0]['raw']['accountEnabled']);
        $this->assertTrue($rows[1]['raw']['accountEnabled']);
    }

    public function testAccountEnabledDefaultsToTrueWhenColumnIsAbsent(): void
    {
        $raw = $this->parse($this->sampleCsv())['rows'][0]['raw'];

        $this->assertTrue($raw['accountEnabled']);
    }

    public function testRowWithoutAtSignInUpnIsReportedWithItsLineNumberAndSkipped(): void
    {
        $csv = "userPrincipalName,displayName\n"
            . "anna.meier@acme.example,Anna Meier\n"
            . "not-an-email,Broken Row\n"
            . "ben.klein@acme.example,Ben Klein\n";

        $result = $this->parse($csv);

        $this->assertCount(2, $result['rows']);
        $this->assertSame(
            ['anna.meier@acme.example', 'ben.klein@acme.example'],
            array_column($result['rows'], 'email'),
        );
        $this->assertCount(1, $result['errors']);
        $this->assertStringStartsWith('Line 3:', $result['errors'][0]);
        $this->assertStringContainsString('not-an-email', $result['errors'][0]);
    }

    public function testBlankLinesAreSkippedSilently(): void
    {
        $csv = "userPrincipalName,displayName\n"
            . "anna.meier@acme.example,Anna Meier\n"
            . "\n"
            . "ben.klein@acme.example,Ben Klein\n"
            . "\n";

        $result = $this->parse($csv);

        $this->assertCount(2, $result['rows']);
        $this->assertSame([], $result['errors']);
    }

    public function testDuplicateEmailKeepsTheLastRowAndNotesIt(): void
    {
        $csv = "userPrincipalName,jobTitle\n"
            . "anna.meier@acme.example,Old Title\n"
            . "ben.klein@acme.example,CTO\n"
            . "ANNA.MEIER@acme.example,New Title\n";

        $result = $this->parse($csv);

        $this->assertCount(2, $result['rows']);
        $this->assertSame('anna.meier@acme.example', $result['rows'][0]['email']);
        $this->assertSame('New Title', $result['rows'][0]['raw']['jobTitle']);
        $this->assertSame('ANNA.MEIER@acme.example', $result['rows'][0]['raw']['userPrincipalName']);
        $this->assertCount(1, $result['errors']);
        $this->assertStringContainsString('duplicate', $result['errors'][0]);
        $this->assertStringStartsWith('Line 4:', $result['errors'][0]);
    }

    public function testEveryRowCarriesANonEmptyAssignedLicensesList(): void
    {
        // Without this, UserProfile::looksLikeSharedMailbox() would classify
        // every imported user as a shared mailbox.
        foreach ($this->parse($this->sampleCsv())['rows'] as $row) {
            $this->assertSame([['skuId' => 'local-csv']], $row['raw']['assignedLicenses']);
        }
    }

    public function testEmptyCellsAreOmittedFromRaw(): void
    {
        $csv = "userPrincipalName,displayName,jobTitle,department,mobilePhone\n"
            . "anna.meier@acme.example,Anna Meier,, ,\n";

        $raw = $this->parse($csv)['rows'][0]['raw'];

        $this->assertArrayNotHasKey('jobTitle', $raw);
        $this->assertArrayNotHasKey('department', $raw);
        $this->assertArrayNotHasKey('mobilePhone', $raw);
        $this->assertSame('Anna Meier', $raw['displayName']);
    }

    public function testMailDefaultsToUserPrincipalNameButAnExplicitMailWins(): void
    {
        $csv = "userPrincipalName,mail\n"
            . "anna.meier@acme.example,\n"
            . "ben.klein@acme.example,ben@acme-mail.example\n";

        $rows = $this->parse($csv)['rows'];

        $this->assertSame('anna.meier@acme.example', $rows[0]['raw']['mail']);
        $this->assertSame('ben@acme-mail.example', $rows[1]['raw']['mail']);
    }

    public function testUnknownColumnsAreIgnored(): void
    {
        $csv = "userPrincipalName,objectId,ageGroup,displayName\n"
            . "anna.meier@acme.example,4711,adult,Anna Meier\n";

        $raw = $this->parse($csv)['rows'][0]['raw'];

        $this->assertArrayNotHasKey('objectId', $raw);
        $this->assertArrayNotHasKey('ageGroup', $raw);
        $this->assertSame('Anna Meier', $raw['displayName']);
    }

    public function testPreambleLineBeforeTheHeaderIsTolerated(): void
    {
        $csv = "Exported 2026-09-01 by admin\n"
            . "userPrincipalName,displayName\n"
            . "anna.meier@acme.example,Anna Meier\n";

        $result = $this->parse($csv);

        $this->assertSame([], $result['errors']);
        $this->assertCount(1, $result['rows']);
        $this->assertSame('anna.meier@acme.example', $result['rows'][0]['email']);
    }

    public function testHeaderBeyondTheFirstThreeLinesIsAnError(): void
    {
        $csv = "junk\njunk\njunk\n"
            . "userPrincipalName,displayName\n"
            . "anna.meier@acme.example,Anna Meier\n";

        $result = $this->parse($csv);

        $this->assertSame([], $result['rows']);
        $this->assertCount(1, $result['errors']);
        $this->assertStringContainsString('userPrincipalName', $result['errors'][0]);
    }

    public function testFileWithoutAnyHeaderIsAnError(): void
    {
        $result = $this->parse("name,email\nAnna,anna.meier@acme.example\n");

        $this->assertSame([], $result['rows']);
        $this->assertCount(1, $result['errors']);
    }

    public function testEmptyInputYieldsNoRowsAndOneError(): void
    {
        $result = $this->parse('');

        $this->assertSame([], $result['rows']);
        $this->assertCount(1, $result['errors']);
    }

    public function testQuotedCellsContainingTheDelimiterSurvive(): void
    {
        $csv = "userPrincipalName,displayName,jobTitle\n"
            . "anna.meier@acme.example,\"Meier, Anna\",\"Head of Sales, DACH\"\n";

        $raw = $this->parse($csv)['rows'][0]['raw'];

        $this->assertSame('Meier, Anna', $raw['displayName']);
        $this->assertSame('Head of Sales, DACH', $raw['jobTitle']);
    }
}
