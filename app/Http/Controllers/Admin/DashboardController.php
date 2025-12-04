<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\FantraxPlayer;
use App\Models\ImportRun;
use App\Models\NhlGame;
use App\Models\Player;
use App\Models\User;
use App\Services\AdminImports;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schedule;

class DashboardController extends Controller
{
    public function __construct(private AdminImports $imports)
    {
    }

    public function index()
    {
        $seeded = User::query()->count() > 0;

        $initialized = $seeded
            && Player::query()->count() > 0
            && FantraxPlayer::query()->count() > 0
            && Contract::query()->count() > 0;

        $latestGameDate = NhlGame::query()->max('game_date');
        $latestGameDate = $latestGameDate ? Carbon::parse($latestGameDate) : null;
        $upToDate = $initialized
            && optional($latestGameDate)?->greaterThanOrEqualTo(currentSeasonEndDate());

        $imports = $this->imports->sources()->map(function (array $source) {
            $lastRun = ImportRun::query()
                ->where('source', $source['key'])
                ->latest('ran_at')
                ->first();

            return [
                'key' => $source['key'],
                'label' => $source['label'],
                'last_run' => $lastRun?->ran_at,
            ];
        });

        $unmatchedCount = FantraxPlayer::query()->whereNull('player_id')->count();

        $events = collect(Schedule::events())->map(function ($event) {
            return [
                'command' => $event->command ?? $event->description,
                'expression' => $event->expression,
                'next' => optional($event->nextRunDate())->toDateTimeString(),
                'last' => method_exists($event, 'lastRunDate') ? optional($event->lastRunDate())->toDateTimeString() : null,
            ];
        });

        return view('admin.dashboard', [
            'seeded' => $seeded,
            'initialized' => $initialized,
            'upToDate' => $upToDate,
            'imports' => $imports,
            'unmatchedCount' => $unmatchedCount,
            'events' => $events,
        ]);
    }
}
