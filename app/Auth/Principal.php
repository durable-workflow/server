<?php

namespace App\Auth;

final class Principal
{
    /**
     * @var array<int, string>
     */
    private readonly array $roles;

    /**
     * @param  array<int, string>  $roles
     * @param  array<string, mixed>  $claims
     */
    public function __construct(
        private readonly string $subject,
        array $roles = [],
        private readonly string $method = 'none',
        private readonly ?string $tenant = null,
        private readonly array $claims = [],
        private readonly bool $legacyFullAccess = false,
    ) {
        $this->roles = self::normalizeRoles($roles);
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    public static function role(
        string $role,
        string $method,
        bool $legacyFullAccess = false,
        ?string $subject = null,
        ?string $tenant = null,
        array $claims = [],
    ): self {
        return new self(
            subject: $subject ?? "role:{$role}",
            roles: [$role],
            method: $method,
            tenant: $tenant,
            claims: $claims,
            legacyFullAccess: $legacyFullAccess,
        );
    }

    public function subject(): string
    {
        return $this->subject;
    }

    /**
     * @return array<int, string>
     */
    public function roles(): array
    {
        return $this->roles;
    }

    public function primaryRole(): ?string
    {
        return $this->roles[0] ?? null;
    }

    public function hasRole(string $role): bool
    {
        return in_array($role, $this->roles, true);
    }

    public function method(): string
    {
        return $this->method;
    }

    public function tenant(): ?string
    {
        return $this->tenant;
    }

    /**
     * @return array<string, mixed>
     */
    public function claims(): array
    {
        return $this->claims;
    }

    public function legacyFullAccess(): bool
    {
        return $this->legacyFullAccess;
    }

    /**
     * @return array<string, mixed>
     */
    public function toAuditContext(): array
    {
        return array_filter([
            'subject' => $this->subject,
            'roles' => $this->roles,
            'tenant' => $this->tenant,
            'claims' => $this->claims,
            'legacy_full_access' => $this->legacyFullAccess ?: null,
        ], static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []);
    }

    /**
     * @param  array<int, string>  $roles
     * @return array<int, string>
     */
    private static function normalizeRoles(array $roles): array
    {
        $normalized = [];

        foreach ($roles as $role) {
            $role = trim($role);

            if ($role !== '') {
                $normalized[] = $role;
            }
        }

        return array_values(array_unique($normalized));
    }
}
