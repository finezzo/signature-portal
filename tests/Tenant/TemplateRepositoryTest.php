<?php
declare(strict_types=1);

namespace Tests\Tenant;

use App\Tenant\TemplateRepository;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Runs against an in-memory SQLite database with a schema equivalent to the
 * MySQL one (001_init.sql + 017_template_manual_selection.sql). The queries
 * under test are plain ANSI SQL, so the engine difference doesn't matter.
 */
final class TemplateRepositoryTest extends TestCase
{
    private PDO $pdo;
    private TemplateRepository $repo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(
            'CREATE TABLE templates (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER NOT NULL,
                name TEXT NOT NULL,
                html TEXT NOT NULL,
                is_manually_selectable INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )'
        );
        $this->repo = new TemplateRepository($this->pdo);
    }

    public function testCreateDefaultsToNotSelectable(): void
    {
        $id = $this->repo->create(1, 'Plain', '<p>x</p>');
        $t  = $this->repo->find($id, 1);
        $this->assertNotNull($t);
        $this->assertFalse($t->isManuallySelectable);
    }

    public function testCreatePersistsSelectableFlag(): void
    {
        $id = $this->repo->create(1, 'Manual', '<p>x</p>', true);
        $t  = $this->repo->find($id, 1);
        $this->assertNotNull($t);
        $this->assertTrue($t->isManuallySelectable);
    }

    public function testUpdateTogglesFlagBothWays(): void
    {
        $id = $this->repo->create(1, 'Toggle', '<p>x</p>');

        $this->repo->update($id, 1, 'Toggle', '<p>x</p>', true);
        $this->assertTrue($this->repo->find($id, 1)->isManuallySelectable);

        $this->repo->update($id, 1, 'Toggle', '<p>x</p>', false);
        $this->assertFalse($this->repo->find($id, 1)->isManuallySelectable);
    }

    public function testListManuallySelectableFiltersByFlagAndTenant(): void
    {
        $flagged   = $this->repo->create(1, 'B flagged', '<p>x</p>', true);
        $this->repo->create(1, 'Unflagged', '<p>x</p>');
        $this->repo->create(2, 'A other tenant', '<p>x</p>', true);
        $flagged2  = $this->repo->create(1, 'A flagged', '<p>x</p>', true);

        $list = $this->repo->listManuallySelectable(1);

        $this->assertSame([$flagged2, $flagged], array_map(fn($t) => $t->id, $list)); // ordered by name
        foreach ($list as $t) {
            $this->assertSame(1, $t->tenantId);
            $this->assertTrue($t->isManuallySelectable);
        }
    }

    public function testFindIsTenantScoped(): void
    {
        $id = $this->repo->create(1, 'Scoped', '<p>x</p>', true);
        $this->assertNull($this->repo->find($id, 2), 'foreign tenant must not resolve the template');
        $this->assertNotNull($this->repo->find($id, 1));
    }
}
