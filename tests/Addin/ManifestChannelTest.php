<?php
declare(strict_types=1);

namespace Tests\Addin;

use App\Addin\AddinChannel;
use App\Addin\ManifestGenerator;
use App\Tenant\Tenant;
use PHPUnit\Framework\TestCase;

final class ManifestChannelTest extends TestCase
{
    private const STABLE_GUID = '11111111-2222-4333-8444-555555555555';

    private function build(AddinChannel $channel): string
    {
        $tenant = new Tenant(
            id: 7,
            slug: 'acme',
            name: 'Acme GmbH',
            emailDomains: ['acme.example'],
            entraTenantId: null,
            entraClientId: null,
            entraClientSecretEncrypted: null,
            apiKeyEncrypted: 'irrelevant',
            apiKeyLastRotatedAt: null,
            apiKeyAcknowledgedAt: null,
            manifestGuid: self::STABLE_GUID,
            ssoEnabled: false,
            ssoAutoProvision: false,
            ssoDefaultRole: 'tenant_editor',
            sharedDisplayMode: 'shared',
            sharedDisplayModePerDomain: [],
            disclaimerHtml: null,
            createdAt: '2026-01-01 00:00:00',
            updatedAt: '2026-01-01 00:00:00',
        );
        return (new ManifestGenerator('https://sig.example.com/'))->build($tenant, 'sekret-key', $channel);
    }

    /**
     * Any change to an admin-deployed manifest blocks users until an admin
     * consents. If this fails, you are about to change what everyone runs: that
     * has to be a deliberate release, with the fixture updated on purpose.
     */
    public function testStableManifestIsFrozen(): void
    {
        $this->assertSame(
            file_get_contents(__DIR__ . '/fixtures/manifest-stable.xml'),
            $this->build(AddinChannel::Stable),
        );
    }

    public function testEveryChannelIsWellFormedXml(): void
    {
        foreach (AddinChannel::cases() as $channel) {
            $this->assertNotFalse(simplexml_load_string($this->build($channel)), $channel->value);
        }
    }

    public function testChannelsAreSeparateAddinsWithStableIds(): void
    {
        $ids = array_map(static fn(AddinChannel $c): string => $c->manifestGuid(self::STABLE_GUID), AddinChannel::cases());

        $this->assertCount(3, array_unique($ids));
        $this->assertSame(self::STABLE_GUID, AddinChannel::Stable->manifestGuid(self::STABLE_GUID));
        foreach ($ids as $id) {
            $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-8[0-9a-f]{3}-[0-9a-f]{12}$/', $id);
        }
        // A re-download must update the same installation, not add another one.
        $this->assertSame($ids[1], AddinChannel::Preview->manifestGuid(self::STABLE_GUID));
    }

    public function testPreviewUsesItsOwnFilesAndIdentifiesItself(): void
    {
        $m = $this->build(AddinChannel::Preview);

        $this->assertStringContainsString('https://sig.example.com/addin-preview/commands.html?tenant=acme', $m);
        $this->assertStringContainsString('https://sig.example.com/addin-preview/taskpane.html?tenant=acme', $m);
        $this->assertStringContainsString('&amp;channel=preview', $m);
        $this->assertStringContainsString('Signaturen — Acme GmbH (Preview)', $m);
        $this->assertStringNotContainsString('sig.example.com/addin/commands', $m);
    }

    public function testLocalPointsAtTheDevServerButSupportsThePortal(): void
    {
        $m = $this->build(AddinChannel::Local);

        $this->assertStringContainsString('https://localhost:8443/addin-preview/commands.html?tenant=acme', $m);
        $this->assertStringContainsString('https://localhost:8443/addin/icon-64.png', $m);
        $this->assertStringContainsString('<SupportUrl DefaultValue="https://sig.example.com/portal"/>', $m);
        $this->assertStringContainsString('Signaturen — Acme GmbH (Local)', $m);
    }

    public function testApiKeyStaysOutOfQueryStringOutsideStable(): void
    {
        foreach ([AddinChannel::Preview, AddinChannel::Local] as $channel) {
            $m = $this->build($channel);
            $this->assertStringNotContainsString('key=sekret-key&amp;', $m, $channel->value);
            $this->assertStringNotContainsString('?key=', $m, $channel->value);
            $this->assertStringContainsString('#key=sekret-key', $m, $channel->value);
        }
    }

    public function testOnlyStableKeepsMailboxWidePermission(): void
    {
        $this->assertStringContainsString('<Permissions>ReadWriteMailbox</Permissions>', $this->build(AddinChannel::Stable));
        $this->assertStringContainsString('<Permissions>ReadWriteItem</Permissions>', $this->build(AddinChannel::Preview));
        $this->assertStringContainsString('<Permissions>ReadWriteItem</Permissions>', $this->build(AddinChannel::Local));
    }
}
