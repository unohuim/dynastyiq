<?php

namespace App\Jobs;

use App\Classes\ImportNHLPlayer;
//use App\Events\ImportStreamEvent;
use App\Models\Player;
use App\Traits\HasAPITrait;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Bus\Batchable;

class ImportNHLPlayerJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use SerializesModels;
    use HasAPITrait;
    use Batchable;

    protected string $playerId;
    protected bool $isProspect;

    public const TAG_IMPORT = 'import-nhl-player';

    /**
     * Create a new job instance.
     *
     * @param string $playerId
     * @param bool $isProspect
     */
    public function __construct(string $playerId, bool $isProspect = false)
    {
        $this->playerId = $playerId;
        $this->isProspect = $isProspect;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $player = Player::find($this->playerId);

        $fullName = $player?->full_name ?? "Player {$this->playerId}";
        $position = $player?->position ?? 'N/A';
        $teamAbbrev = $player?->team_abbrev ?? 'N/A';

        // ImportStreamEvent::dispatch(
        //     'nhl',
        //     "Importing {$fullName}, {$position} – {$teamAbbrev}",
        //     'started'
        // );

        (new ImportNHLPlayer())->import($this->playerId, $this->isProspect);
    }


    public function tags(): array
    {
        return [self::TAG_IMPORT, "nhl_player_id: {$this->playerId}"];
    }
}
