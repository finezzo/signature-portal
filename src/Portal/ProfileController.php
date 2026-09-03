<?php
declare(strict_types=1);

namespace App\Portal;

use App\Audit\AuditLogger;
use App\Auth\SessionManager;
use App\Tenant\ProfileCsvImporter;
use App\Tenant\ProfileRepository;
use App\Tenant\TenantRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Views\Twig;

/**
 * The local user-profile store — the fallback the signature pipeline reads when
 * Microsoft Graph is unreachable. Rows land here three ways: written through by
 * every successful Graph lookup, bulk-seeded from an Entra CSV export, or typed
 * in by hand under a tenant's "Profiles" sub-tab.
 *
 * Hand edits are deliberately non-destructive: a row that came from Graph keeps
 * every property this form does not manage (object id, proxyAddresses, ...), so
 * correcting a job title does not quietly shrink the cached profile.
 */
final class ProfileController
{
    /**
     * Form field → Graph property, for the plain string fields. `email`, `mail`,
     * `business_phones` and `licensed` need more than a rename and are handled
     * explicitly in the mappers below.
     *
     * @var array<string,string>
     */
    private const FIELD_MAP = [
        'given_name'         => 'givenName',
        'surname'            => 'surname',
        'display_name'       => 'displayName',
        'job_title'          => 'jobTitle',
        'department'         => 'department',
        'company_name'       => 'companyName',
        'office_location'    => 'officeLocation',
        'employee_id'        => 'employeeId',
        'mobile_phone'       => 'mobilePhone',
        'street_address'     => 'streetAddress',
        'postal_code'        => 'postalCode',
        'city'               => 'city',
        'country'            => 'country',
        'preferred_language' => 'preferredLanguage',
    ];

    /** Largest CSV accepted, in bytes. An Entra export of 10k users is ~1.5 MB. */
    private const MAX_CSV_BYTES = 2 * 1024 * 1024;

    /** @var list<string> */
    private const CSV_MEDIA_TYPES = [
        'text/plain',
        'text/csv',
        'application/csv',
        'application/vnd.ms-excel',
    ];

    public function __construct(
        private readonly Twig $view,
        private readonly TenantRepository $tenants,
        private readonly ProfileRepository $profiles,
        private readonly ProfileCsvImporter $importer,
        private readonly SessionManager $session,
        private readonly AuditLogger $audit,
    ) {}

    public function index(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        [$tenant, $err] = $this->resolveTenant($request, $args);
        if ($err) return $err;

        return $this->view->render($response, 'portal/profiles/index.twig', [
            'tenant'   => $tenant,
            'profiles' => $this->profiles->listForTenant($tenant->id),
        ]);
    }

    public function newForm(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        [$tenant, $err] = $this->resolveTenant($request, $args);
        if ($err) return $err;

        return $this->view->render($response, 'portal/profiles/form.twig', [
            'tenant'  => $tenant,
            'profile' => null,
            'form'    => $this->emptyForm(),
            'errors'  => [],
        ]);
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        [$tenant, $err] = $this->resolveTenant($request, $args);
        if ($err) return $err;

        $form   = $this->normalizeForm((array) $request->getParsedBody());
        $errors = $this->validate($form, $tenant->id, null);

        if ($errors !== []) {
            return $this->view->render($response, 'portal/profiles/form.twig', [
                'tenant'  => $tenant,
                'profile' => null,
                'form'    => $form,
                'errors'  => $errors,
            ]);
        }

        $newId = $this->profiles->upsert(
            $tenant->id,
            $form['email'],
            $this->formToRaw($form, []),
            'manual',
        );
        $this->audit->record($tenant->id, 'profile.created', 'user_profile', $newId, "Profile added for {$form['email']}");
        $this->session->flash('success', 'Profile added.');
        return $this->redirectToIndex($response, $tenant->id);
    }

    public function editForm(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        [$tenant, $err] = $this->resolveTenant($request, $args);
        if ($err) return $err;
        $profile = $this->profiles->find((int) $args['pid'], $tenant->id);
        if ($profile === null) return $this->notFound($response);

        $form          = $this->rawToForm($profile->raw);
        $form['email'] = $profile->email;

        return $this->view->render($response, 'portal/profiles/form.twig', [
            'tenant'  => $tenant,
            'profile' => $profile,
            'form'    => $form,
            'errors'  => [],
        ]);
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        [$tenant, $err] = $this->resolveTenant($request, $args);
        if ($err) return $err;
        $profile = $this->profiles->find((int) $args['pid'], $tenant->id);
        if ($profile === null) return $this->notFound($response);

        $form   = $this->normalizeForm((array) $request->getParsedBody());
        $errors = $this->validate($form, $tenant->id, $profile->id);

        if ($errors !== []) {
            return $this->view->render($response, 'portal/profiles/form.twig', [
                'tenant'  => $tenant,
                'profile' => $profile,
                'form'    => $form,
                'errors'  => $errors,
            ]);
        }

        $this->profiles->updateById(
            $profile->id,
            $tenant->id,
            $form['email'],
            $this->formToRaw($form, $profile->raw),
            'manual',
        );
        $this->audit->record($tenant->id, 'profile.updated', 'user_profile', $profile->id, "Profile saved for {$form['email']}");
        $this->session->flash('success', 'Profile saved.');
        return $this->redirectToIndex($response, $tenant->id);
    }

    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        [$tenant, $err] = $this->resolveTenant($request, $args);
        if ($err) return $err;
        $profile = $this->profiles->find((int) $args['pid'], $tenant->id);
        if ($profile === null) return $this->notFound($response);

        $this->profiles->delete($profile->id, $tenant->id);
        $this->audit->record($tenant->id, 'profile.deleted', 'user_profile', $profile->id, "Profile deleted for {$profile->email}");
        $this->session->flash('success', 'Profile deleted.');
        return $this->redirectToIndex($response, $tenant->id);
    }

    /**
     * Bulk-seed from an Entra admin center "Download users" export.
     *
     * Graph-sourced rows are never touched: live directory data outranks a CSV
     * that was accurate whenever the admin happened to download it.
     */
    public function import(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        [$tenant, $err] = $this->resolveTenant($request, $args);
        if ($err) return $err;

        $files  = $request->getUploadedFiles();
        $upload = $files['file'] ?? null;
        if ($upload === null) {
            $this->session->flash('error', 'No file submitted.');
            return $this->redirectToIndex($response, $tenant->id);
        }

        if ($upload->getError() !== UPLOAD_ERR_OK) {
            $this->session->flash('error', 'Upload failed — the file did not arrive intact.');
            return $this->redirectToIndex($response, $tenant->id);
        }
        if ((int) $upload->getSize() > self::MAX_CSV_BYTES) {
            $this->session->flash('error', 'File too large (max 2 MB).');
            return $this->redirectToIndex($response, $tenant->id);
        }

        $extension = mb_strtolower(pathinfo((string) $upload->getClientFilename(), PATHINFO_EXTENSION));
        if (!in_array($extension, ['csv', 'txt'], true)) {
            $this->session->flash('error', 'Only .csv or .txt files can be imported.');
            return $this->redirectToIndex($response, $tenant->id);
        }
        $mediaType = (string) $upload->getClientMediaType();
        if ($mediaType !== '' && !in_array($mediaType, self::CSV_MEDIA_TYPES, true)) {
            $this->session->flash('error', 'That does not look like a CSV file.');
            return $this->redirectToIndex($response, $tenant->id);
        }

        try {
            $csv = $upload->getStream()->getContents();
        } catch (\RuntimeException $e) {
            $this->session->flash('error', 'Could not read the uploaded file: ' . $e->getMessage());
            return $this->redirectToIndex($response, $tenant->id);
        }

        $parsed  = $this->importer->parse($csv);
        $added   = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($parsed['rows'] as $row) {
            $existing = $this->profiles->findByEmail($tenant->id, $row['email']);
            if ($existing !== null && $existing->source === 'graph') {
                $skipped++;
                continue;
            }
            $this->profiles->upsert($tenant->id, $row['email'], $row['raw'], 'csv');
            $existing === null ? $added++ : $updated++;
        }

        $this->audit->record(
            $tenant->id,
            'profiles.imported',
            'user_profile',
            null,
            "CSV import: {$added} added, {$updated} updated, {$skipped} skipped (Graph-sourced)",
        );
        $this->session->flash('success', "CSV import: {$added} added, {$updated} updated, {$skipped} skipped (Graph-sourced).");

        // parse() reports per-line notes (skipped rows, duplicates) rather than
        // fatal errors — the import already happened, so these are shown as a
        // report alongside the counts.
        if ($parsed['errors'] !== []) {
            $shown     = array_slice($parsed['errors'], 0, 5);
            $remaining = count($parsed['errors']) - count($shown);
            $report    = implode(' ', $shown);
            if ($remaining > 0) {
                $report .= " … and {$remaining} more.";
            }
            $this->session->flash('error', $report);
        }

        return $this->redirectToIndex($response, $tenant->id);
    }

    // ---------- form ↔ Graph shape ----------

    /** @return array<string,string|bool> */
    private function emptyForm(): array
    {
        $form = ['email' => '', 'mail' => '', 'business_phones' => '', 'licensed' => true];
        foreach (array_keys(self::FIELD_MAP) as $key) {
            $form[$key] = '';
        }
        return $form;
    }

    /**
     * @param array<string,mixed> $body
     * @return array<string,string|bool>
     */
    private function normalizeForm(array $body): array
    {
        $form = [
            'email'           => mb_strtolower(trim((string) ($body['email'] ?? ''))),
            'mail'            => trim((string) ($body['mail'] ?? '')),
            'business_phones' => trim((string) ($body['business_phones'] ?? '')),
            'licensed'        => !empty($body['licensed']),
        ];
        foreach (array_keys(self::FIELD_MAP) as $key) {
            $form[$key] = trim((string) ($body[$key] ?? ''));
        }
        return $form;
    }

    /**
     * @param array<string,string|bool> $form
     * @return list<string>
     */
    private function validate(array $form, int $tenantId, ?int $editingId): array
    {
        $errors = [];
        $email  = (string) $form['email'];

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'A valid email address is required.';
        } else {
            $existing = $this->profiles->findByEmail($tenantId, $email);
            if ($existing !== null && $existing->id !== $editingId) {
                $errors[] = 'A profile for this email already exists. Edit that one instead.';
            }
        }

        return $errors;
    }

    /**
     * Stored Graph shape → form values. Only the properties this form manages
     * are surfaced; everything else in the row rides along untouched.
     *
     * @param array<string,mixed> $raw
     * @return array<string,string|bool>
     */
    private function rawToForm(array $raw): array
    {
        $form = [
            'email' => isset($raw['userPrincipalName']) ? (string) $raw['userPrincipalName'] : '',
            'mail'  => isset($raw['mail']) ? (string) $raw['mail'] : '',
        ];
        foreach (self::FIELD_MAP as $formKey => $graphKey) {
            $form[$formKey] = isset($raw[$graphKey]) ? (string) $raw[$graphKey] : '';
        }

        $phones = $raw['businessPhones'] ?? [];
        $form['business_phones'] = is_array($phones)
            ? implode("\n", array_map('strval', $phones))
            : '';

        $form['licensed'] = !empty($raw['assignedLicenses']);

        return $form;
    }

    /**
     * Form values → stored Graph shape, layered over what is already stored.
     *
     * Starting from $existingRaw is load-bearing: a row written through from
     * Graph carries properties this form has no input for (id, proxyAddresses,
     * otherMails, usageLocation, ...) and they must survive a hand edit. Blanked
     * fields are removed rather than stored as '' so UserProfile sees a missing
     * property, exactly as it would from Graph.
     *
     * @param array<string,string|bool> $form
     * @param array<string,mixed>       $existingRaw
     * @return array<string,mixed>
     */
    private function formToRaw(array $form, array $existingRaw): array
    {
        $raw = $existingRaw;

        foreach (self::FIELD_MAP as $formKey => $graphKey) {
            $value = (string) ($form[$formKey] ?? '');
            if ($value === '') {
                unset($raw[$graphKey]);
            } else {
                $raw[$graphKey] = $value;
            }
        }

        $phones = [];
        foreach (preg_split("/\r\n|\n|\r/", (string) $form['business_phones']) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '') {
                $phones[] = $line;
            }
        }
        if ($phones === []) {
            unset($raw['businessPhones']);
        } else {
            $raw['businessPhones'] = $phones;
        }

        $email = (string) $form['email'];
        $raw['userPrincipalName'] = $email;
        $raw['mail']              = ((string) $form['mail']) !== '' ? (string) $form['mail'] : $email;
        $raw['accountEnabled']    = (bool) ($existingRaw['accountEnabled'] ?? true);

        // looksLikeSharedMailbox() reads assignedLicenses, so the checkbox is
        // the admin's "this is a real person" switch. An unlicensed-looking row
        // gets a synthetic license rather than a fabricated real one.
        if (empty($form['licensed'])) {
            $raw['assignedLicenses'] = [];
        } else {
            $existingLicenses = $existingRaw['assignedLicenses'] ?? [];
            $raw['assignedLicenses'] = is_array($existingLicenses) && $existingLicenses !== []
                ? $existingLicenses
                : [['skuId' => 'local-manual']];
        }

        return $raw;
    }

    // ---------- helpers ----------

    /** @return array{0:\App\Tenant\Tenant|null, 1:?ResponseInterface} */
    private function resolveTenant(ServerRequestInterface $request, array $args): array
    {
        $id     = (int) ($args['id'] ?? 0);
        $tenant = $id > 0 ? $this->tenants->find($id) : null;
        if ($tenant === null) {
            $r = (new ResponseFactory())->createResponse(404);
            $r->getBody()->write('Tenant not found.');
            return [null, $r];
        }
        $user = (array) $request->getAttribute('current_user');
        if (!AccessControl::canAccessTenant($user, $tenant->id)) {
            $r = (new ResponseFactory())->createResponse(403);
            $r->getBody()->write('Forbidden.');
            return [null, $r];
        }
        return [$tenant, null];
    }

    private function redirectToIndex(ResponseInterface $response, int $tenantId): ResponseInterface
    {
        return $response->withHeader('Location', "/portal/tenants/{$tenantId}/profiles")->withStatus(302);
    }

    private function notFound(ResponseInterface $r): ResponseInterface
    {
        $r = $r->withStatus(404);
        $r->getBody()->write('Not found.');
        return $r;
    }
}
