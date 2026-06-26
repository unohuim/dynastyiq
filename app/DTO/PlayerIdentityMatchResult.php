<?php

declare(strict_types=1);

namespace App\DTO;

use App\Models\PlayerExternalIdentity;

/**
 * Value object describing the result of an identity matching decision.
 */
final readonly class PlayerIdentityMatchResult
{
    public function __construct(
        public string $status,
        public ?int $playerId = null,
        public ?int $confidence = null,
        public ?string $reason = null,
    ) {
    }

    /**
     * Create a matched identity result.
     */
    public static function matched(int $playerId, int $confidence = 100): self
    {
        return new self(
            PlayerExternalIdentity::STATUS_MATCHED,
            $playerId,
            $confidence,
        );
    }

    /**
     * Create an unmatched identity result.
     */
    public static function unmatched(string $reason): self
    {
        return new self(
            PlayerExternalIdentity::STATUS_UNMATCHED,
            null,
            null,
            $reason,
        );
    }
}
