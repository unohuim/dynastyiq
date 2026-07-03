<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\FantraxDraftPick;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class FantraxDraftPickMade
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public int $platformLeagueId,
        public int $draftPickId,
        public array $pick,
    ) {
    }

    public static function fromDraftPick(FantraxDraftPick $draftPick): self
    {
        return new self(
            (int) $draftPick->platform_league_id,
            (int) $draftPick->id,
            [
                'provider_pick_key' => (string) $draftPick->provider_pick_key,
                'overall_pick' => $draftPick->overall_pick,
                'round' => $draftPick->round,
                'pick_in_round' => $draftPick->pick_in_round,
                'fantrax_team_id' => $draftPick->fantrax_team_id,
                'fantrax_player_id' => $draftPick->fantrax_player_id,
            ],
        );
    }
}
