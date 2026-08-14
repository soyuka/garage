<?php

declare(strict_types=1);

namespace App\Maintenance\Defaults;

/**
 * The outcome of {@see ServiceDefaultsResolver::resolve()}: a status, the baselines in play, and —
 * when the status is Ambiguous — the FACT KEY that would settle it, so the caller asks (or looks the
 * fact up) instead of guessing. {@see baseline()} is deliberately null on anything but Resolved:
 * there is no "best candidate" to fall back on.
 */
final readonly class ServiceDefaultsResolution
{
    /**
     * @param list<ServiceBaseline> $candidates
     * @param string|null           $discriminantKey   the fact key that settled it (Resolved) or would
     *                                                 settle it (Ambiguous); null when the split is by
     *                                                 vehicle segment, which no fact can answer
     * @param string|null           $discriminantValue the vehicle's fact value that selected the baseline
     */
    private function __construct(
        public ServiceDefaultsStatus $status,
        public string $type,
        public array $candidates,
        public ?string $discriminantKey = null,
        public ?string $discriminantValue = null,
        public ?string $reason = null,
    ) {
    }

    public static function resolved(string $type, ServiceBaseline $baseline, ?string $discriminantKey = null, ?string $discriminantValue = null): self
    {
        return new self(ServiceDefaultsStatus::Resolved, $type, [$baseline], $discriminantKey, $discriminantValue);
    }

    /**
     * @param list<ServiceBaseline> $candidates
     */
    public static function ambiguous(string $type, array $candidates, ?string $discriminantKey, string $reason): self
    {
        return new self(ServiceDefaultsStatus::Ambiguous, $type, $candidates, $discriminantKey, null, $reason);
    }

    public static function unknown(string $type, string $reason): self
    {
        return new self(ServiceDefaultsStatus::Unknown, $type, [], null, null, $reason);
    }

    /** The single applicable baseline, and only when one truly applies — never a pick among candidates. */
    public function baseline(): ?ServiceBaseline
    {
        return ServiceDefaultsStatus::Resolved === $this->status ? $this->candidates[0] : null;
    }
}
