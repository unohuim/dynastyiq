<?php

declare(strict_types=1);

namespace App\DTO;

use App\Models\PlayerExternalIdentity;
use InvalidArgumentException;

/**
 * Value object describing the result of an identity matching decision.
 */
final readonly class PlayerIdentityMatchResult
{
    /**
     * Status values documented for external player identity matching.
     *
     * @var array<int,string>
     */
    private const VALID_STATUSES = [
        PlayerExternalIdentity::STATUS_MATCHED,
        PlayerExternalIdentity::STATUS_CANDIDATE,
        PlayerExternalIdentity::STATUS_UNMATCHED,
        PlayerExternalIdentity::STATUS_IGNORED,
        PlayerExternalIdentity::STATUS_CONFLICT,
    ];

    public function __construct(
        public string $status,
        public ?int $playerId = null,
        public ?int $confidence = null,
        public ?string $reason = null,
    ) {
        if (! in_array($status, self::VALID_STATUSES, true)) {
            throw new InvalidArgumentException("Unsupported player identity match status [{$status}].");
        }

        if ($status === PlayerExternalIdentity::STATUS_MATCHED && $playerId === null) {
            throw new InvalidArgumentException('Matched player identities must reference a canonical player.');
        }

        if ($confidence !== null && ($confidence < 0 || $confidence > 100)) {
            throw new InvalidArgumentException('Player identity match confidence must be between 0 and 100.');
        }
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

    /**
     * Create a candidate identity result.
     */
    public static function candidate(string $reason, ?int $confidence = null): self
    {
        return new self(
            PlayerExternalIdentity::STATUS_CANDIDATE,
            null,
            $confidence,
            $reason,
        );
    }
}
