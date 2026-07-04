<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\DraftPick;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class DraftPickMade
{
    use Dispatchable;
    use SerializesModels;

    /**
     * @param array<string,mixed> $pick
     */
    public function __construct(
        public int $draftId,
        public int $draftPickId,
        public ?int $platformLeagueId,
        public array $pick,
    ) {
    }

    public static function fromDraftPick(DraftPick $draftPick): self
    {
        return new self(
            (int) $draftPick->draft_id,
            (int) $draftPick->id,
            $draftPick->draft?->platform_league_id ? (int) $draftPick->draft->platform_league_id : null,
            [
                'provider_pick_key' => (string) $draftPick->provider_pick_key,
                'overall_pick' => $draftPick->overall_pick,
                'round' => $draftPick->round,
                'pick_in_round' => $draftPick->pick_in_round,
                'platform_team_id' => $draftPick->platform_team_id,
                'provider_team_id' => $draftPick->provider_team_id,
                'player_id' => $draftPick->player_id,
                'provider_player_id' => $draftPick->provider_player_id,
            ],
        );
    }
}
