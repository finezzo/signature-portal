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
 *     opening the "Choose signature" taskpane. Requires Mailbox 1.3+.
 *   - VersionOverrides V1_1   → event-based auto-activation via
 *     OnNewMessageCompose + OnMessageFromChanged (re-fetch on FROM switch),
 *     a Runtimes block declaring a WebView runtime for new clients
 *     (Outlook on the web / Mac / new Windows) with a JS-only override for
 *     the classic Windows client, PLUS a MessageComposeCommandSurface with
 *     two manual buttons: "Insert signature" (ExecuteFunction) and
 *     "Choose signature" (ShowTaskpane). V1_1 fully replaces V1_0 on
 *     clients that support it, so the command surface must be declared in
 *     BOTH blocks or modern clients show no buttons at all.
 *     Requires Mailbox 1.13+.
 *
 * The Runtimes block is what makes event-based add-ins install on classic
 * Outlook — without it the manifest validates but events never fire.
 *
 * All user-visible strings carry a de-DE <Override> so Outlook picks German
 * labels automatically when its display language is German.
 *
 * Tenant slug + API key are baked into the WebView, JS runtime and taskpane
 * URLs so the add-in code reads them from `location.search` at load time
 * without a separate config fetch. The API key is a secret — distribute the
 * manifest only via M365 admin upload or trusted sideloading.
 */
final class ManifestGenerator
{
    /**
     * Manifest version, also appended as a `v=` cache-buster to every runtime
     * URL (including the icon PNGs). Outlook's WebView caches those files
     * hard; a changed URL forces a fresh fetch on clients that still hold an
     * old copy. Bump this on every release that touches the manifest or the
     * add-in runtime files.
     */
    private const VERSION = '1.3.0.0';

    public function __construct(private readonly string $baseUrl) {}

    public function build(Tenant $tenant, string $apiKeyPlaintext): string
    {
        $base          = rtrim($this->baseUrl, '/');
        $guid          = $tenant->manifestGuid ?? '00000000-0000-4000-8000-000000000000';
        $displayName   = "Signatures — {$tenant->name}";
        $displayNameDe = "Signaturen — {$tenant->name}";
        $description   = 'Applies the correct corporate signature on compose.';
        $descriptionDe = 'Fügt beim Verfassen automatisch die richtige Firmensignatur ein.';
        $version       = self::VERSION;

        $query       = '?tenant=' . rawurlencode($tenant->slug)
                     . '&key='    . rawurlencode($apiKeyPlaintext)
                     . '&v='      . rawurlencode(self::VERSION);

        $cmdsUrl     = "{$base}/addin/commands.html{$query}";
        $jsUrl       = "{$base}/addin/commands.js{$query}";
        // The taskpane needs tenant + key too — it lists and renders templates
        // via the key-authenticated API. Same exposure as commands.html above.
        $taskpaneUrl = "{$base}/addin/taskpane.html{$query}";

        // One exact-size PNG per resolution (Microsoft: 16/32/80 required for
        // ribbon buttons, 20/24/40/48/64 recommended for high-DPI; 64 for
        // IconUrl, 128 for HighResolutionIconUrl). The `v=` buster matters
        // here too: icon URLs are otherwise cached across manifest updates.
        $icon = static fn(int $size): string =>
            "{$base}/addin/icon-{$size}.png?v=" . rawurlencode(self::VERSION);
        $icon16  = $icon(16);
        $icon20  = $icon(20);
        $icon24  = $icon(24);
        $icon32  = $icon(32);
        $icon40  = $icon(40);
        $icon48  = $icon(48);
        $icon64  = $icon(64);
        $icon80  = $icon(80);
        $icon128 = $icon(128);

        $e = static fn(string $s): string => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        // Identical in both VersionOverrides blocks (V1_1 replaces V1_0
        // wholesale, so each needs the full set).
        $imageResources = <<<XML
        <bt:Images>
          <bt:Image id="icon16" DefaultValue="{$e($icon16)}"/>
          <bt:Image id="icon20" DefaultValue="{$e($icon20)}"/>
          <bt:Image id="icon24" DefaultValue="{$e($icon24)}"/>
          <bt:Image id="icon32" DefaultValue="{$e($icon32)}"/>
          <bt:Image id="icon40" DefaultValue="{$e($icon40)}"/>
          <bt:Image id="icon48" DefaultValue="{$e($icon48)}"/>
          <bt:Image id="icon64" DefaultValue="{$e($icon64)}"/>
          <bt:Image id="icon80" DefaultValue="{$e($icon80)}"/>
        </bt:Images>
XML;

        $stringResources = <<<XML
        <bt:ShortStrings>
          <bt:String id="groupLabel" DefaultValue="Signature">
            <bt:Override Locale="de-DE" Value="Signatur"/>
          </bt:String>
          <bt:String id="btnInsertLabel" DefaultValue="Insert signature">
            <bt:Override Locale="de-DE" Value="Signatur einfügen"/>
          </bt:String>
          <bt:String id="btnChooseLabel" DefaultValue="Choose signature">
            <bt:Override Locale="de-DE" Value="Signatur auswählen"/>
          </bt:String>
        </bt:ShortStrings>
        <bt:LongStrings>
          <bt:String id="btnInsertDesc" DefaultValue="Fetch and insert your corporate signature for the current sender.">
            <bt:Override Locale="de-DE" Value="Ruft die Firmensignatur für den aktuellen Absender ab und fügt sie ein."/>
          </bt:String>
          <bt:String id="btnChooseDesc" DefaultValue="Pick one of the approved signature templates and insert it.">
            <bt:Override Locale="de-DE" Value="Eine freigegebene Signaturvorlage auswählen und einfügen."/>
          </bt:String>
        </bt:LongStrings>
XML;

        $iconBlock = <<<XML
                  <Icon>
                    <bt:Image size="16" resid="icon16"/>
                    <bt:Image size="20" resid="icon20"/>
                    <bt:Image size="24" resid="icon24"/>
                    <bt:Image size="32" resid="icon32"/>
                    <bt:Image size="40" resid="icon40"/>
                    <bt:Image size="48" resid="icon48"/>
                    <bt:Image size="64" resid="icon64"/>
                    <bt:Image size="80" resid="icon80"/>
                  </Icon>
XML;

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<OfficeApp
    xmlns="http://schemas.microsoft.com/office/appforoffice/1.1"
    xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
    xmlns:bt="http://schemas.microsoft.com/office/officeappbasictypes/1.0"
    xsi:type="MailApp">
  <Id>{$e($guid)}</Id>
  <Version>{$e($version)}</Version>
  <ProviderName>SignaturePortal</ProviderName>
  <DefaultLocale>en-US</DefaultLocale>
  <DisplayName DefaultValue="{$e($displayName)}">
    <Override Locale="de-DE" Value="{$e($displayNameDe)}"/>
  </DisplayName>
  <Description DefaultValue="{$e($description)}">
    <Override Locale="de-DE" Value="{$e($descriptionDe)}"/>
  </Description>
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
                <Control xsi:type="Button" id="sigPortalChooseBtn">
                  <Label resid="btnChooseLabel"/>
                  <Supertip>
                    <Title resid="btnChooseLabel"/>
                    <Description resid="btnChooseDesc"/>
                  </Supertip>
{$iconBlock}
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
{$imageResources}
      <bt:Urls>
        <bt:Url id="Commands.Url" DefaultValue="{$e($cmdsUrl)}"/>
        <bt:Url id="Taskpane.Url" DefaultValue="{$e($taskpaneUrl)}"/>
      </bt:Urls>
{$stringResources}
    </Resources>
    <VersionOverrides xmlns="http://schemas.microsoft.com/office/mailappversionoverrides/1.1" xsi:type="VersionOverridesV1_1">
      <Requirements>
        <!-- 1.13 is required by OnMessageFromChanged (per the Microsoft signature
             sample). Clients below 1.13 (pre-2023 builds) fall back to the
             VersionOverridesV1_0 manual chooser button above. -->
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
                <!-- Re-fetch when recipients change: rules can be scoped by
                     recipient (internal/external), so the matching template may
                     differ once the To/Cc/Bcc fields are filled in. -->
                <LaunchEvent Type="OnMessageRecipientsChanged" FunctionName="onMessageRecipientsChangedHandler"/>
              </LaunchEvents>
              <SourceLocation resid="WebViewRuntime.Url"/>
            </ExtensionPoint>
            <!-- Manual controls. V1_1 replaces V1_0 wholesale on Mailbox
                 1.13+ clients, so the command surface must be repeated here —
                 otherwise modern Outlook shows no ribbon buttons at all. -->
            <ExtensionPoint xsi:type="MessageComposeCommandSurface">
              <OfficeTab id="TabDefault">
                <Group id="sigPortalGroup11">
                  <Label resid="groupLabel"/>
                  <Control xsi:type="Button" id="sigPortalInsertBtn">
                    <Label resid="btnInsertLabel"/>
                    <Supertip>
                      <Title resid="btnInsertLabel"/>
                      <Description resid="btnInsertDesc"/>
                    </Supertip>
{$iconBlock}
                    <Action xsi:type="ExecuteFunction">
                      <FunctionName>insertSignatureManual</FunctionName>
                    </Action>
                  </Control>
                  <Control xsi:type="Button" id="sigPortalChooseBtn11">
                    <Label resid="btnChooseLabel"/>
                    <Supertip>
                      <Title resid="btnChooseLabel"/>
                      <Description resid="btnChooseDesc"/>
                    </Supertip>
{$iconBlock}
                    <Action xsi:type="ShowTaskpane">
                      <SourceLocation resid="Taskpane.Url"/>
                    </Action>
                  </Control>
                </Group>
              </OfficeTab>
            </ExtensionPoint>
          </DesktopFormFactor>
          <!-- Outlook mobile (iOS/Android). Event-based signatures work there
               for Exchange Online accounts; MobileFormFactor cannot host
               ribbon buttons, so manual insertion is desktop/web only.
               Shared-mailbox FROM switching is not supported on mobile, so
               the recipients event is omitted to mirror Microsoft's
               signature sample. -->
          <MobileFormFactor>
            <ExtensionPoint xsi:type="LaunchEvent">
              <LaunchEvents>
                <LaunchEvent Type="OnNewMessageCompose" FunctionName="onNewMessageComposeHandler"/>
                <LaunchEvent Type="OnMessageFromChanged" FunctionName="onMessageFromChangedHandler"/>
              </LaunchEvents>
              <SourceLocation resid="WebViewRuntime.Url"/>
            </ExtensionPoint>
          </MobileFormFactor>
        </Host>
      </Hosts>
      <Resources>
{$imageResources}
        <bt:Urls>
          <bt:Url id="Commands.Url"       DefaultValue="{$e($cmdsUrl)}"/>
          <bt:Url id="Taskpane.Url"       DefaultValue="{$e($taskpaneUrl)}"/>
          <bt:Url id="WebViewRuntime.Url" DefaultValue="{$e($cmdsUrl)}"/>
          <bt:Url id="JSRuntime.Url"      DefaultValue="{$e($jsUrl)}"/>
        </bt:Urls>
{$stringResources}
      </Resources>
    </VersionOverrides>
  </VersionOverrides>
</OfficeApp>
XML;
    }
}
