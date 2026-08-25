<?php
declare(strict_types=1);

namespace Tests\Addin;

use App\Addin\ManifestGenerator;
use App\Tenant\Tenant;
use PHPUnit\Framework\TestCase;

final class ManifestGeneratorTest extends TestCase
{
    private function tenant(): Tenant
    {
        return new Tenant(
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
            manifestGuid: '11111111-2222-4333-8444-555555555555',
            ssoEnabled: false,
            ssoAutoProvision: false,
            ssoDefaultRole: 'tenant_editor',
            sharedDisplayMode: 'shared',
            sharedDisplayModePerDomain: [],
            disclaimerHtml: null,
            createdAt: '2026-01-01 00:00:00',
            updatedAt: '2026-01-01 00:00:00',
        );
    }

    private function build(): string
    {
        return (new ManifestGenerator('https://sig.example.com/'))
            ->build($this->tenant(), 'sekret-key');
    }

    public function testXmlIsWellFormed(): void
    {
        $xml = simplexml_load_string($this->build());
        $this->assertNotFalse($xml);
    }

    public function testVersionBumpedAndUsedAsCacheBuster(): void
    {
        $m = $this->build();
        $this->assertStringContainsString('<Version>1.3.0.0</Version>', $m);
        $this->assertStringContainsString('v=1.3.0.0', $m);
    }

    public function testTaskpaneUrlCarriesTenantAndKey(): void
    {
        $this->assertStringContainsString(
            'taskpane.html?tenant=acme&amp;key=sekret-key&amp;v=1.3.0.0',
            $this->build(),
        );
    }

    public function testManualButtonsDeclaredInV11(): void
    {
        $m = $this->build();
        $this->assertStringContainsString('<FunctionName>insertSignatureManual</FunctionName>', $m);
        $this->assertStringContainsString('id="sigPortalInsertBtn"', $m);
        $this->assertStringContainsString('id="sigPortalChooseBtn11"', $m);
        // Command surface must exist in BOTH VersionOverrides blocks — V1_1
        // replaces V1_0 wholesale on modern clients.
        $this->assertSame(2, substr_count($m, '<ExtensionPoint xsi:type="MessageComposeCommandSurface">'));
    }

    public function testStringResourcesPresentInBothResourceBlocks(): void
    {
        $m = $this->build();
        foreach (['groupLabel', 'btnInsertLabel', 'btnChooseLabel', 'btnInsertDesc', 'btnChooseDesc'] as $resid) {
            $this->assertSame(2, substr_count($m, "bt:String id=\"{$resid}\""), "resid {$resid}");
        }
    }

    public function testGermanLocaleOverrides(): void
    {
        $m = $this->build();
        $this->assertStringContainsString('Locale="de-DE"', $m);
        $this->assertStringContainsString('Signatur einfügen', $m);
        $this->assertStringContainsString('Signatur auswählen', $m);
        $this->assertStringContainsString('Signaturen — Acme GmbH', $m);
    }

    public function testSizeCorrectIconResources(): void
    {
        $m = $this->build();
        foreach ([16, 20, 24, 32, 40, 48, 64, 80] as $size) {
            $this->assertSame(
                2,
                substr_count($m, "bt:Image id=\"icon{$size}\" DefaultValue=\"https://sig.example.com/addin/icon-{$size}.png?v=1.3.0.0\""),
                "icon{$size}",
            );
        }
        $this->assertStringContainsString('<IconUrl DefaultValue="https://sig.example.com/addin/icon-64.png?v=1.3.0.0"/>', $m);
        $this->assertStringContainsString('<HighResolutionIconUrl DefaultValue="https://sig.example.com/addin/icon-128.png?v=1.3.0.0"/>', $m);
    }

    public function testLaunchEventsUnchanged(): void
    {
        $m = $this->build();
        foreach (['OnNewMessageCompose', 'OnMessageFromChanged', 'OnMessageRecipientsChanged'] as $ev) {
            $this->assertStringContainsString("Type=\"{$ev}\"", $m);
        }
    }
}
