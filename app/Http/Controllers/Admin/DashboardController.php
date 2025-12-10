<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FantraxPlayer;
use App\Models\ImportRun;
use App\Services\AdminImports;
use App\Services\PlatformState;
use Illuminate\Support\Facades\Schedule;

class DashboardController extends Controller
{
    public function __construct(private AdminImports $imports, private PlatformState $platformState)
    {
    }

    public function index()
    {
        $seeded = $this->platformState->seeded();
        $initialized = $this->platformState->initialized();
        $upToDate = $this->platformState->upToDate();

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

        $unmatchedPlayersCount = FantraxPlayer::query()->whereNull('player_id')->count();

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
            'unmatchedPlayersCount' => $unmatchedPlayersCount,
            'events' => $events,
        ]);
    }
}
