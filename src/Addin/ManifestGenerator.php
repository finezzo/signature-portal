<?php
declare(strict_types=1);

namespace App\Addin;

use App\Tenant\Tenant;

/**
 * Builds an Outlook Add-in OfficeApp manifest (XML) for a tenant.
 *
 * Structure mirrors the Microsoft-recommended event-based add-in layout:
 *
 *   - VersionOverrides V1_0   → manual ribbon button (MessageComposeCommandSurface)
 *     so users can re-apply the signature on demand. Requires Mailbox 1.3+.
 *   - VersionOverrides V1_1   → event-based auto-activation via
 *     OnNewMessageCompose + OnMessageFromChanged (re-fetch on FROM switch),
 *     plus a Runtimes block declaring a WebView runtime for new clients
 *     (Outlook on the web / Mac / new Windows) and a JS-only override for the
 *     classic Windows client. Requires Mailbox 1.13+.
 *
 * The Runtimes block is what makes event-based add-ins install on classic
 * Outlook — without it the manifest validates but events never fire.
 *
 * Tenant slug + API key are baked into the WebView and JS runtime URLs so the
 * add-in code reads them from `location.search` at load time without a
 * separate config fetch. The API key is a secret — distribute the manifest
 * only via M365 admin upload or trusted sideloading.
 */
final class ManifestGenerator
{
    public function __construct(private readonly string $baseUrl) {}

    public function build(Tenant $tenant, string $apiKeyPlaintext): string
    {
        $base        = rtrim($this->baseUrl, '/');
        $guid        = $tenant->manifestGuid ?? '00000000-0000-4000-8000-000000000000';
        $displayName = "Signatures — {$tenant->name}";
        $description = 'Applies the correct corporate signature on compose.';

        $query       = '?tenant=' . rawurlencode($tenant->slug)
                     . '&key='    . rawurlencode($apiKeyPlaintext);

        $cmdsUrl     = "{$base}/addin/commands.html{$query}";
        $jsUrl       = "{$base}/addin/commands.js{$query}";
        $taskpaneUrl = "{$base}/addin/taskpane.html";
        $icon64      = "{$base}/addin/icon-64.png";
        $icon128     = "{$base}/addin/icon-128.png";

        $e = static fn(string $s): string => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<OfficeApp
    xmlns="http://schemas.microsoft.com/office/appforoffice/1.1"
    xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
    xmlns:bt="http://schemas.microsoft.com/office/officeappbasictypes/1.0"
    xsi:type="MailApp">
  <Id>{$e($guid)}</Id>
  <Version>1.1.0.0</Version>
  <ProviderName>SignaturePortal</ProviderName>
  <DefaultLocale>en-US</DefaultLocale>
  <DisplayName DefaultValue="{$e($displayName)}"/>
  <Description DefaultValue="{$e($description)}"/>
  <IconUrl DefaultValue="{$e($icon64)}"/>
  <HighResolutionIconUrl DefaultValue="{$e($icon128)}"/>
  <SupportUrl DefaultValue="{$e($base)}/portal"/>
  <AppDomains>
    <AppDomain>https://login.microsoftonline.com</AppDomain>
    <AppDomain>https://graph.microsoft.com</AppDomain>
  </AppDomains>
  <Hosts>
    <Host Name="Mailbox"/>
  </Hosts>
  <Requirements>
    <Sets>
      <Set Name="Mailbox" MinVersion="1.1"/>
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
  <VersionOverrides xmlns="http://schemas.microsoft.com/office/mailappversionoverrides" xsi:type="VersionOverridesV1_0">
    <Requirements>
      <bt:Sets DefaultMinVersion="1.3">
        <bt:Set Name="Mailbox"/>
      </bt:Sets>
    </Requirements>
    <Hosts>
      <Host xsi:type="MailHost">
        <DesktopFormFactor>
          <FunctionFile resid="Commands.Url"/>
          <ExtensionPoint xsi:type="MessageComposeCommandSurface">
            <OfficeTab id="TabDefault">
              <Group id="sigPortalGroup">
                <Label resid="groupLabel"/>
                <Control xsi:type="Button" id="sigPortalRefreshBtn">
                  <Label resid="btnLabel"/>
                  <Supertip>
                    <Title resid="btnLabel"/>
                    <Description resid="addInDesc"/>
                  </Supertip>
                  <Icon>
                    <bt:Image size="16" resid="icon16"/>
                    <bt:Image size="32" resid="icon32"/>
                    <bt:Image size="80" resid="icon80"/>
                  </Icon>
                  <Action xsi:type="ShowTaskpane">
                    <SourceLocation resid="Taskpane.Url"/>
                  </Action>
                </Control>
              </Group>
            </OfficeTab>
          </ExtensionPoint>
        </DesktopFormFactor>
      </Host>
    </Hosts>
    <Resources>
      <bt:Images>
        <bt:Image id="icon16" DefaultValue="{$e($icon64)}"/>
        <bt:Image id="icon32" DefaultValue="{$e($icon64)}"/>
        <bt:Image id="icon80" DefaultValue="{$e($icon64)}"/>
      </bt:Images>
      <bt:Urls>
        <bt:Url id="Commands.Url" DefaultValue="{$e($cmdsUrl)}"/>
        <bt:Url id="Taskpane.Url" DefaultValue="{$e($taskpaneUrl)}"/>
      </bt:Urls>
      <bt:ShortStrings>
        <bt:String id="groupLabel" DefaultValue="Signature"/>
        <bt:String id="btnLabel" DefaultValue="Refresh"/>
      </bt:ShortStrings>
      <bt:LongStrings>
        <bt:String id="addInDesc" DefaultValue="Reapply the corporate signature for this message."/>
      </bt:LongStrings>
    </Resources>
    <VersionOverrides xmlns="http://schemas.microsoft.com/office/mailappversionoverrides/1.1" xsi:type="VersionOverridesV1_1">
      <Requirements>
        <!-- 1.13 is required by OnMessageFromChanged (per the Microsoft signature
             sample). Clients below 1.13 (pre-2023 builds) fall back to the
             VersionOverridesV1_0 manual Refresh button above. -->
        <bt:Sets DefaultMinVersion="1.13">
          <bt:Set Name="Mailbox"/>
        </bt:Sets>
      </Requirements>
      <Hosts>
        <Host xsi:type="MailHost">
          <Runtimes>
            <Runtime resid="WebViewRuntime.Url">
              <Override type="javascript" resid="JSRuntime.Url"/>
            </Runtime>
          </Runtimes>
          <DesktopFormFactor>
            <SupportsSharedFolders>true</SupportsSharedFolders>
            <FunctionFile resid="Commands.Url"/>
            <ExtensionPoint xsi:type="LaunchEvent">
              <LaunchEvents>
                <LaunchEvent Type="OnNewMessageCompose" FunctionName="onNewMessageComposeHandler"/>
                <!-- Re-fetch the signature whenever the user changes the FROM
                     address (e.g. switching to a shared mailbox) — including in
                     popped-out compose windows, where no new-compose event fires. -->
                <LaunchEvent Type="OnMessageFromChanged" FunctionName="onMessageFromChangedHandler"/>
              </LaunchEvents>
              <SourceLocation resid="WebViewRuntime.Url"/>
            </ExtensionPoint>
          </DesktopFormFactor>
        </Host>
      </Hosts>
      <Resources>
        <bt:Images>
          <bt:Image id="icon16" DefaultValue="{$e($icon64)}"/>
          <bt:Image id="icon32" DefaultValue="{$e($icon64)}"/>
          <bt:Image id="icon80" DefaultValue="{$e($icon64)}"/>
        </bt:Images>
        <bt:Urls>
          <bt:Url id="Commands.Url"       DefaultValue="{$e($cmdsUrl)}"/>
          <bt:Url id="Taskpane.Url"       DefaultValue="{$e($taskpaneUrl)}"/>
          <bt:Url id="WebViewRuntime.Url" DefaultValue="{$e($cmdsUrl)}"/>
          <bt:Url id="JSRuntime.Url"      DefaultValue="{$e($jsUrl)}"/>
        </bt:Urls>
        <bt:ShortStrings>
          <bt:String id="groupLabel" DefaultValue="Signature"/>
          <bt:String id="btnLabel" DefaultValue="Refresh"/>
        </bt:ShortStrings>
        <bt:LongStrings>
          <bt:String id="addInDesc" DefaultValue="Reapply the corporate signature for this message."/>
        </bt:LongStrings>
      </Resources>
    </VersionOverrides>
  </VersionOverrides>
</OfficeApp>
XML;
    }
}
