<?php
declare(strict_types=1);

namespace Tests\Tenant;

use App\Tenant\ProfileRepository;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Runs against an in-memory SQLite database with a schema equivalent to the
 * MySQL one (018_user_profiles.sql): ENUM → TEXT, MEDIUMTEXT → TEXT, DATETIME
 * → TEXT, no foreign keys. The repository deliberately avoids MySQL-only
 * syntax (no ON DUPLICATE KEY UPDATE), so the engine difference doesn't matter.
 */
final class ProfileRepositoryTest extends TestCase
{
    private PDO $pdo;
    private ProfileRepository $repo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(
            'CREATE TABLE user_profiles (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER NOT NULL,
                email TEXT NOT NULL,
                display_name TEXT DEFAULT NULL,
                source TEXT NOT NULL,
                profile_json TEXT NOT NULL,
                graph_synced_at TEXT DEFAULT NULL,
                updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (tenant_id, email)
            )'
        );
        $this->repo = new ProfileRepository($this->pdo);
    }

    public function testUpsertInsertsThenUpdatesTheSameRow(): void
    {
        $first  = $this->repo->upsert(1, 'anna@example.de', ['displayName' => 'Anna A'], 'manual');
        $second = $this->repo->upsert(1, 'anna@example.de', ['displayName' => 'Anna B'], 'manual');

        $this->assertSame($first, $second, 'second upsert must reuse the existing row');
        $this->assertCount(1, $this->repo->listForTenant(1));

        $row = $this->repo->find($first, 1);
        $this->assertNotNull($row);
        $this->assertSame('Anna B', $row->displayName);
        $this->assertSame('Anna B', $row->raw['displayName']);
        $this->assertNull($row->graphSyncedAt, 'a manual upsert must not claim a Graph sync');
    }

    public function testUpsertFromGraphOverwritesManualRow(): void
    {
        $id = $this->repo->upsert(1, 'anna@example.de', ['displayName' => 'Hand typed'], 'manual');

        $this->repo->upsertFromGraph(1, 'anna@example.de', [
            'displayName'      => 'Anna Graph',
            'assignedLicenses' => [['skuId' => 'x']],
        ]);

        $this->assertCount(1, $this->repo->listForTenant(1));
        $row = $this->repo->find($id, 1);
        $this->assertNotNull($row);
        $this->assertSame('graph', $row->source, 'Graph always wins over a manual row');
        $this->assertSame('Anna Graph', $row->displayName);
        $this->assertNotNull($row->graphSyncedAt);
        $this->assertSame($row->graphSyncedAt, $row->storedAt());
    }

    public function testFindByEmailIsCaseInsensitive(): void
    {
        $id = $this->repo->upsert(1, 'User@X.de', ['displayName' => 'Mixed Case'], 'csv');

        $row = $this->repo->findByEmail(1, 'user@x.de');
        $this->assertNotNull($row);
        $this->assertSame($id, $row->id);
        $this->assertSame('user@x.de', $row->email, 'emails are stored lowercased');

        $this->assertNotNull($this->repo->findByEmail(1, 'USER@X.DE'));
    }

    public function testJsonRoundTripPreservesTokensAndSharedMailboxDetection(): void
    {
        $shared = [
            'id'                => 'guid-1',
            'userPrincipalName' => 'info@example.de',
            'displayName'       => 'Info Postfach',
            'jobTitle'          => 'Kundenbetreuung Süd',
            'businessPhones'    => ['+49 30 1234'],
            'mobilePhone'       => '+49 170 9999',
            'assignedLicenses'  => [],
        ];
        $this->repo->upsertFromGraph(1, 'info@example.de', $shared);

        $profile = $this->repo->findByEmail(1, 'info@example.de')?->toUserProfile();
        $this->assertNotNull($profile);
        $this->assertTrue($profile->looksLikeSharedMailbox(), 'assignedLicenses: [] must survive the round-trip');
        $this->assertSame($shared, $profile->raw);

        $tokens = $profile->tokens();
        $this->assertSame('Info Postfach', $tokens['display_name']);
        $this->assertSame('Kundenbetreuung Süd', $tokens['job_title_line']);
        $this->assertStringContainsString('+49 30 1234', $tokens['phone_lines']);
        $this->assertStringContainsString('+49 170 9999', $tokens['phone_lines']);

        // A licensed user from the same store is not mistaken for a shared mailbox.
        $this->repo->upsertFromGraph(1, 'anna@example.de', [
            'userPrincipalName' => 'anna@example.de',
            'assignedLicenses'  => [['skuId' => 'e3']],
        ]);
        $licensed = $this->repo->findByEmail(1, 'anna@example.de')?->toUserProfile();
        $this->assertNotNull($licensed);
        $this->assertFalse($licensed->looksLikeSharedMailbox());
    }

    public function testEveryLookupIsTenantScoped(): void
    {
        $id = $this->repo->upsert(1, 'anna@example.de', ['displayName' => 'Anna'], 'manual');

        $this->assertNull($this->repo->find($id, 2), 'foreign tenant must not resolve the profile');
        $this->assertNull($this->repo->findByEmail(2, 'anna@example.de'));
        $this->assertSame([], $this->repo->listForTenant(2));

        $this->assertNotNull($this->repo->find($id, 1));
        $this->assertNotNull($this->repo->findByEmail(1, 'anna@example.de'));
        $this->assertCount(1, $this->repo->listForTenant(1));
    }

    public function testSameEmailInTwoTenantsAreSeparateRows(): void
    {
        $a = $this->repo->upsert(1, 'anna@example.de', ['displayName' => 'Tenant one'], 'manual');
        $b = $this->repo->upsert(2, 'anna@example.de', ['displayName' => 'Tenant two'], 'manual');

        $this->assertNotSame($a, $b);
        $this->assertSame('Tenant one', $this->repo->findByEmail(1, 'anna@example.de')?->displayName);
        $this->assertSame('Tenant two', $this->repo->findByEmail(2, 'anna@example.de')?->displayName);
    }

    public function testUpdateByIdAndDeleteAreTenantScoped(): void
    {
        $id = $this->repo->upsert(1, 'old@example.de', ['displayName' => 'Old'], 'manual');

        $this->repo->updateById($id, 2, 'hijacked@example.de', ['displayName' => 'Nope'], 'manual');
        $this->assertSame('old@example.de', $this->repo->find($id, 1)?->email);

        $this->repo->updateById($id, 1, 'New@Example.de', ['displayName' => 'New'], 'csv');
        $row = $this->repo->find($id, 1);
        $this->assertNotNull($row);
        $this->assertSame('new@example.de', $row->email);
        $this->assertSame('csv', $row->source);

        $this->repo->delete($id, 2);
        $this->assertNotNull($this->repo->find($id, 1), 'foreign tenant must not delete the row');

        $this->repo->delete($id, 1);
        $this->assertNull($this->repo->find($id, 1));
    }
}
