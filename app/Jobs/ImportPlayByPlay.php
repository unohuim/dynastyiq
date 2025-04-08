<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Bus\Batchable;
use App\Models\PlayByPlay;
use App\Traits\HasClockTimeTrait;


class ImportPlayByPlay implements ShouldQueue
{
    use Queueable, HasClockTimeTrait, Batchable;

    public $tries = 5;
    
    protected $game;
    protected $play;
    protected $strength;


    /**
     * Create a new job instance.
     */
    public function __construct($game, $play, $strength)
    {
        $this->game = $game;
        $this->play = $play;
        $this->strength = $strength;

    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $pbp = PlayByPlay::where('nhl_event_id', $this->play['eventId'])->where('nhl_game_id', $this->game['id'])->first();
        if(is_null($pbp)) $pbp = new PlayByPlay();


        //game details
        $pbp->nhl_game_id = $this->game['id'];
        $pbp->season_id = $this->game['season'];
        $pbp->game_date = $this->game['gameDate'];
        $pbp->game_type = $this->game['gameType'];
        $pbp->away_team_id = $this->game['awayTeam']['id'];
        $pbp->home_team_id = $this->game['homeTeam']['id'];
        $pbp->away_team_abbrev = $this->game['awayTeam']['abbrev'];
        $pbp->home_team_abbrev = $this->game['homeTeam']['abbrev'];

        //all play details
        $pbp->period = $this->play['periodDescriptor']['number'];
        $pbp->nhl_event_id = $this->play['eventId'];
        $pbp->time_in_period = $this->play['timeInPeriod'];
        $pbp->time_remaining = $this->play['timeRemaining'];
        $pbp->seconds_remaining = $this->getSeconds($pbp->time_remaining);
        $pbp->seconds_in_period = $this->getSeconds($pbp->time_in_period);
        $pbp->seconds_in_game = ((($pbp->period -1) * 20 * 60) + $pbp->seconds_in_period);

        if(isset($this->play['details']['eventOwnerTeamId'])) $pbp->event_owner_team_id = $this->play['details']['eventOwnerTeamId'];


        //pp or sh
        $pbp->strength = $this->strength;

        $pbp->period_type = $this->play['periodDescriptor']['periodType'];
        $pbp->situation_code = $this->play['situationCode'];
        $pbp->type_code = $this->play['typeCode'];
        $pbp->type_desc_key = $this->play['typeDescKey'];
        $pbp->sort_order = $this->play['sortOrder'];
        $pbp->home_team_defending_side = $this->play['homeTeamDefendingSide'];



        //generic details
        if(isset($this->play['details']['xCoord'])) $pbp->x_coord = $this->play['details']['xCoord'];
        if(isset($this->play['details']['yCoord'])) $pbp->y_coord = $this->play['details']['yCoord'];
        if(isset($this->play['details']['zoneCode'])) $pbp->zone_code = $this->play['details']['zoneCode'];
        if(isset($this->play['details']['shotType'])) $pbp->shot_type = $this->play['details']['shotType'];
        if(isset($this->play['details']['reason'])) $pbp->reason = $this->play['details']['reason'];
        if(isset($this->play['details']['secondaryReason'])) $pbp->secondary_reason = $this->play['details']['secondaryReason'];
        if(isset($this->play['details']['playerId'])) $pbp->player_id = $this->play['details']['playerId'];


        //faceoffs
        if(isset($this->play['details']['winningPlayerId'])) $pbp->fo_winning_player_id = $this->play['details']['winningPlayerId'];
        if(isset($this->play['details']['losingPlayerId'])) $pbp->fo_losing_player_id = $this->play['details']['losingPlayerId'];

        //sog
        if(isset($this->play['details']['shootingPlayerId'])) $pbp->shooting_player_id = $this->play['details']['shootingPlayerId'];
        if(isset($this->play['details']['goalieInNetId'])) $pbp->goalie_in_net_player_id = $this->play['details']['goalieInNetId'];
        if(isset($this->play['details']['blockingPlayerId'])) $pbp->blocking_player_id = $this->play['details']['blockingPlayerId'];
        if(isset($this->play['details']['homeSOG'])) $pbp->home_sog = $this->play['details']['homeSOG'];
        if(isset($this->play['details']['awaySOG'])) $pbp->away_sog = $this->play['details']['awaySOG'];

        //goal
        if(isset($this->play['details']['scoringPlayerId'])) $pbp->scoring_player_id = $this->play['details']['scoringPlayerId'];
        if(isset($this->play['details']['scoringPlayerTotal'])) $pbp->scoring_player_total = $this->play['details']['scoringPlayerTotal'];
        if(isset($this->play['details']['assist1PlayerId'])) $pbp->assist1_player_id = $this->play['details']['assist1PlayerId'];
        if(isset($this->play['details']['assist1PlayerTotal'])) $pbp->assist1_player_total = $this->play['details']['assist1PlayerTotal'];
        if(isset($this->play['details']['assist2PlayerId'])) $pbp->assist2_player_id = $this->play['details']['assist2PlayerId'];
        if(isset($this->play['details']['assist2PlayerTotal'])) $pbp->assist2_player_total = $this->play['details']['assist2PlayerTotal'];
        if(isset($this->play['details']['awayScore'])) $pbp->away_score = $this->play['details']['awayScore'];
        if(isset($this->play['details']['homeScore'])) $pbp->home_score = $this->play['details']['homeScore'];
        if(isset($this->play['details']['highlightClipSharingUrl'])) $pbp->highlight_clip_sharing_url = $this->play['details']['highlightClipSharingUrl'];
        if(isset($this->play['details']['highlightClip'])) $pbp->highlight_clip_id = $this->play['details']['highlightClip'];

        //hit
        if(isset($this->play['details']['hittingPlayerId'])) $pbp->hitting_player_id = $this->play['details']['hittingPlayerId'];
        if(isset($this->play['details']['hitteePlayerId'])) $pbp->hittee_player_id = $this->play['details']['hitteePlayerId'];

        //penalties
        if(isset($this->play['details']['committedByPlayerId'])) $pbp->committed_by_player_id = $this->play['details']['committedByPlayerId'];
        if(isset($this->play['details']['drawnByPlayerId'])) $pbp->drawn_by_player_id = $this->play['details']['drawnByPlayerId'];
        if(isset($this->play['details']['descKey'])) $pbp->desc_key = $this->play['details']['descKey'];
        if(isset($this->play['details']['typeCode'])) $pbp->penalty_type_code = $this->play['details']['typeCode'];
        if(isset($this->play['details']['duration'])) $pbp->duration = ((int)$this->play['details']['duration'] * 60);

        $pbp->save();
    }
}
