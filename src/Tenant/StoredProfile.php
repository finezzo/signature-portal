<?php
declare(strict_types=1);

namespace App\Tenant;

use App\Graph\UserProfile;

/**
 * A row of `user_profiles` — one locally stored Microsoft Graph profile.
 *
 * `$raw` is the Graph response shape, so toUserProfile() reconstructs exactly
 * the object the live Graph path would have produced (assignedLicenses
 * included, which is what looksLikeSharedMailbox() reads).
 */
final class StoredProfile
{
    /** @param array<string,mixed> $raw the Graph response as decoded JSON */
    public function __construct(
        public readonly int $id,
        public readonly int $tenantId,
        public readonly string $email,
        public readonly ?string $displayName,
        public readonly string $source,
        public readonly array $raw,
        public readonly ?string $graphSyncedAt,
        public readonly string $updatedAt,
        public readonly string $createdAt,
    ) {}

    /** @param array<string,mixed> $row */
    public static function fromRow(array $row): self
    {
        $decoded = json_decode((string) $row['profile_json'], true);

        return new self(
            (int) $row['id'],
            (int) $row['tenant_id'],
            (string) $row['email'],
            $row['display_name'] !== null ? (string) $row['display_name'] : null,
            (string) $row['source'],
            is_array($decoded) ? $decoded : [],
            $row['graph_synced_at'] !== null ? (string) $row['graph_synced_at'] : null,
            (string) $row['updated_at'],
            (string) $row['created_at'],
        );
    }

    public function toUserProfile(): UserProfile
    {
        return UserProfile::fromGraphResponse($this->raw);
    }

    /**
     * When the stored data was last known to be true: the last successful Graph
     * sync, or — for rows that never came from Graph — the last local edit.
     */
    public function storedAt(): string
    {
        return $this->graphSyncedAt ?? $this->updatedAt;
    }
}
