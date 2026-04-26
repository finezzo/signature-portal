<?php
declare(strict_types=1);

namespace App\Addin;

use App\Tenant\Tenant;

/**
 * Builds an Outlook Add-in OfficeApp manifest (XML) for a tenant.
 *
 * The manifest references the portal's add-in HTML files at
 *   {base_url}/addin/commands.html
 *   {base_url}/addin/taskpane.html
 * (these are scaffolded as static files; the runtime add-in code lands in
 * Phase 3). The tenant slug + API key are embedded in the commands URL
 * so the add-in JS can read them at load time without a separate config
 * fetch.
 *
 * The API key is a secret — anyone with the manifest can call /api/sig
 * for that tenant. Distribute the manifest only via M365 admin upload or
 * trusted sideloading.
 */
final class ManifestGenerator
{
    public function __construct(private readonly string $baseUrl) {}

    public function build(Tenant $tenant, string $apiKeyPlaintext): string
    {
        $base       = rtrim($this->baseUrl, '/');
        $host       = (string) parse_url($base, PHP_URL_HOST);
        $guid       = $tenant->manifestGuid ?? '00000000-0000-4000-8000-000000000000';
        $displayName = "Signatures — {$tenant->name}";
        $cmdsUrl     = "{$base}/addin/commands.html?tenant={$tenant->slug}&key={$apiKeyPlaintext}";
        $taskpaneUrl = "{$base}/addin/taskpane.html";

        $e = static fn(string $s): string => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<OfficeApp
    xmlns="http://schemas.microsoft.com/office/appforoffice/1.1"
    xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
    xmlns:bt="http://schemas.microsoft.com/office/officeappbasictypes/1.0"
    xmlns:mailappor="http://schemas.microsoft.com/office/mailappversionoverrides"
    xsi:type="MailApp">
  <Id>{$e($guid)}</Id>
  <Version>1.0.0.0</Version>
  <ProviderName>SignaturePortal</ProviderName>
  <DefaultLocale>en-US</DefaultLocale>
  <DisplayName DefaultValue="{$e($displayName)}"/>
  <Description DefaultValue="Applies the correct corporate signature on compose."/>
  <IconUrl DefaultValue="{$e($base)}/addin/icon-64.png"/>
  <HighResolutionIconUrl DefaultValue="{$e($base)}/addin/icon-128.png"/>
  <SupportUrl DefaultValue="{$e($base)}/portal"/>
  <AppDomains>
    <AppDomain>{$e($host)}</AppDomain>
  </AppDomains>
  <Hosts>
    <Host Name="Mailbox"/>
  </Hosts>
  <Requirements>
    <Sets>
      <Set Name="Mailbox" MinVersion="1.5"/>
    </Sets>
  </Requirements>
  <FormSettings>
    <Form xsi:type="ItemEdit">
      <DesktopSettings>
        <SourceLocation DefaultValue="{$e($taskpaneUrl)}"/>
      </DesktopSettings>
    </Form>
  </FormSettings>
  <Permissions>ReadWriteMailbox</Permissions>
  <Rule xsi:type="RuleCollection" Mode="Or">
    <Rule xsi:type="ItemIs" ItemType="Message" FormType="Edit"/>
  </Rule>
  <DisableEntityHighlighting>false</DisableEntityHighlighting>
  <VersionOverrides xmlns="http://schemas.microsoft.com/office/mailappversionoverrides" xsi:type="VersionOverridesV1_0">
    <VersionOverrides xmlns="http://schemas.microsoft.com/office/mailappversionoverrides/1.1" xsi:type="VersionOverridesV1_1">
      <Requirements>
        <bt:Sets DefaultMinVersion="1.10">
          <bt:Set Name="Mailbox"/>
        </bt:Sets>
      </Requirements>
      <Hosts>
        <Host xsi:type="MailHost">
          <DesktopFormFactor>
            <FunctionFile resid="commands.url"/>
            <ExtensionPoint xsi:type="LaunchEvent">
              <LaunchEvents>
                <!-- Fires once when a new compose window opens. -->
                <LaunchEvent Type="OnNewMessageCompose"      FunctionName="onMessageCompose"/>
                <!-- Re-fire when the user changes the FROM (shared mailbox switch).
                     Requires Mailbox 1.14+; older clients silently skip it. -->
                <LaunchEvent Type="OnMessageFromChange"      FunctionName="onFromChange"/>
                <!-- Re-fire when recipients change so external/internal scope
                     rules can update the signature. Requires Mailbox 1.13+. -->
                <LaunchEvent Type="OnMessageRecipientsChange" FunctionName="onRecipientsChange"/>
              </LaunchEvents>
              <SourceLocation resid="commands.url"/>
            </ExtensionPoint>
          </DesktopFormFactor>
        </Host>
      </Hosts>
      <Resources>
        <bt:Urls>
          <bt:Url id="commands.url" DefaultValue="{$e($cmdsUrl)}"/>
        </bt:Urls>
        <bt:ShortStrings>
          <bt:String id="GetStarted.Title" DefaultValue="SignaturePortal"/>
        </bt:ShortStrings>
        <bt:LongStrings>
          <bt:String id="GetStarted.Description" DefaultValue="Auto-applies your corporate signature on every new message."/>
        </bt:LongStrings>
      </Resources>
    </VersionOverrides>
  </VersionOverrides>
</OfficeApp>
XML;
    }
}
