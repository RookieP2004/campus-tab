<?php

namespace App\Support\Tenancy;

use Closure;

/**
 * Which school the current request, job or command is working for.
 *
 * The tenant is set in exactly two ways:
 *   - by the `tenant` middleware, from the signed-in user's tenant_id
 *     (never from the request body or URL), or
 *   - by platform code that knows which school it is acting on, through
 *     run($tenantId, fn () => …).
 *
 * bypass() switches the tenant scope off for one closure. It exists for the
 * one documented exception (loading a token and its user before the school
 * is known) and must always name its reason. See docs/report/decisions/0005.
 *
 * Bound as a scoped singleton, so each request and each queued job starts
 * with an empty context.
 */
class TenantContext
{
    private ?string $tenantId = null;

    /** @var list<string> */
    private array $bypassReasons = [];

    public function set(string $tenantId): void
    {
        $this->tenantId = $tenantId;
    }

    public function clear(): void
    {
        $this->tenantId = null;
    }

    public function id(): ?string
    {
        return $this->tenantId;
    }

    public function has(): bool
    {
        return $this->tenantId !== null;
    }

    /**
     * The current tenant id; throws when none is set, so a query can never
     * silently run across every school.
     */
    public function require(): string
    {
        return $this->tenantId ?? throw new MissingTenantContext;
    }

    /**
     * Run a closure as the given school, then restore whatever was set before.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function run(string $tenantId, Closure $callback): mixed
    {
        $previous = $this->tenantId;
        $this->tenantId = $tenantId;

        try {
            return $callback();
        } finally {
            $this->tenantId = $previous;
        }
    }

    /**
     * Run a closure with the tenant scope switched off. Only for the
     * documented exceptions; the reason is kept for the query guard's log.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function bypass(string $reason, Closure $callback): mixed
    {
        $this->bypassReasons[] = $reason;

        try {
            return $callback();
        } finally {
            array_pop($this->bypassReasons);
        }
    }

    public function bypassing(): bool
    {
        return $this->bypassReasons !== [];
    }
}
