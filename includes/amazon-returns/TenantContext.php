<?php
declare(strict_types=1);

final readonly class SvAmazonTenantContext
{
    public function __construct(
        private int $tenantId,
        private int $amazonConnectionId,
        private ?int $actorId = null
    ) {
        if ($tenantId < 1 || $amazonConnectionId < 1 || ($actorId !== null && $actorId < 1)) {
            throw new InvalidArgumentException('Tenant context identifiers must be positive integers.');
        }
    }

    public function tenantId(): int { return $this->tenantId; }

    public function amazonConnectionId(): int { return $this->amazonConnectionId; }

    public function actorId(): ?int { return $this->actorId; }

    public function scopeKey(): string
    {
        return 'tenant:' . $this->tenantId . '|connection:' . $this->amazonConnectionId;
    }

    public function withActor(int $actorId): self
    {
        return new self($this->tenantId, $this->amazonConnectionId, $actorId);
    }
}
