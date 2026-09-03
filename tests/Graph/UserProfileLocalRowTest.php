<?php
declare(strict_types=1);

namespace Tests\Graph;

use App\Graph\UserProfile;
use PHPUnit\Framework\TestCase;

/**
 * UserProfile fed from a locally imported (CSV) row rather than a live Graph
 * response. The importer's synthetic `assignedLicenses` entry is what keeps
 * these rows from being mistaken for shared mailboxes, so that contract is
 * pinned here from the consuming side.
 */
final class UserProfileLocalRowTest extends TestCase
{
    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function importedRow(array $overrides = []): array
    {
        return $overrides + [
            'userPrincipalName' => 'anna.meier@acme.example',
            'mail'              => 'anna.meier@acme.example',
            'displayName'       => 'Anna Meier',
            'givenName'         => 'Anna',
            'surname'           => 'Meier',
            'jobTitle'          => 'Head of Sales',
            'businessPhones'    => ['+49 30 123456'],
            'accountEnabled'    => true,
            'assignedLicenses'  => [['skuId' => 'local-csv']],
        ];
    }

    public function testSyntheticLicenseKeepsImportedRowOutOfSharedMailboxHandling(): void
    {
        $profile = UserProfile::fromGraphResponse($this->importedRow());

        $this->assertTrue($profile->isLicensed);
        $this->assertFalse($profile->looksLikeSharedMailbox());
    }

    public function testRowWithoutLicensesIsTreatedAsSharedMailbox(): void
    {
        $profile = UserProfile::fromGraphResponse($this->importedRow(['assignedLicenses' => []]));

        $this->assertFalse($profile->isLicensed);
        $this->assertTrue($profile->looksLikeSharedMailbox());
    }

    public function testTokensExposeTheImportedValues(): void
    {
        $tokens = UserProfile::fromGraphResponse($this->importedRow())->tokens();

        $this->assertSame('Anna', $tokens['first_name']);
        $this->assertSame('Meier', $tokens['last_name']);
        $this->assertSame('Anna Meier', $tokens['display_name']);
        $this->assertSame('anna.meier@acme.example', $tokens['email']);
        $this->assertSame('Head of Sales', $tokens['job_title_line']);
        $this->assertStringContainsString('+49 30 123456', $tokens['phone_lines']);
    }

    public function testEmailOverrideWinsOverTheImportedMail(): void
    {
        $tokens = UserProfile::fromGraphResponse($this->importedRow())
            ->tokens('info@acme.example');

        $this->assertSame('info@acme.example', $tokens['email']);
    }

    public function testShortLanguageReducesBcp47TagToTwoLetters(): void
    {
        $profile = UserProfile::fromGraphResponse($this->importedRow(['preferredLanguage' => 'de-DE']));

        $this->assertSame('de', $profile->shortLanguage());
    }

    public function testShortLanguageIsNullWhenTheImportCarriedNoLanguage(): void
    {
        $this->assertNull(UserProfile::fromGraphResponse($this->importedRow())->shortLanguage());
    }
}
