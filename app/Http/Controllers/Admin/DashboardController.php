<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FantraxPlayer;
use App\Models\ImportRun;
use App\Models\Player;
use App\Services\AdminImports;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Str;

class DashboardController extends Controller
{
    public function __construct(private AdminImports $imports)
    {
    }

    public function index(Request $request)
    {
        if ($request->wantsJson() && $request->query('section') === 'players') {
            return $this->players($request);
        }

        $imports = $this->imports->sources()->map(function (array $source) {
            $lastRun = ImportRun::query()
                ->where('source', $source['key'])
                ->latest('ran_at')
                ->first();

            return [
                'key' => $source['key'],
                'label' => $source['label'],
                'last_run' => $lastRun?->ran_at?->toDateTimeString(),
                'run_url' => route('admin.imports.run', ['key' => $source['key']]),
            ];
        });

        $unmatchedPlayersCount = FantraxPlayer::query()->whereNull('player_id')->count();
        $hasPlayers = Player::query()->exists();

        $events = collect(Schedule::events())->map(function ($event) {
            return [
                'command' => $event->command ?? $event->description,
                'expression' => $event->expression,
                'next' => optional($event->nextRunDate())->toDateTimeString(),
                'last' => method_exists($event, 'lastRunDate') ? optional($event->lastRunDate())->toDateTimeString() : null,
            ];
        });

        return view('admin.dashboard', [
            'imports' => $imports,
            'unmatchedPlayersCount' => $unmatchedPlayersCount,
            'events' => $events,
            'hasPlayers' => $hasPlayers,
        ]);
    }

    protected function players(Request $request): JsonResponse
    {
        $perPage = (int) $request->integer('per_page', 25);
        $perPage = max(5, min($perPage, 100));

        $page = (int) $request->integer('page', 1);
        $page = max($page, 1);

        $filter = Str::of($request->string('filter')->toString())->trim();

        $query = Player::query();

        if ($filter->isNotEmpty()) {
            $term = '%' . $filter . '%';
            $query->where(function ($builder) use ($term) {
                $builder
                    ->where('full_name', 'like', $term)
                    ->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", [$term])
                    ->orWhere('first_name', 'like', $term)
                    ->orWhere('last_name', 'like', $term);
            });
        }

        $total = $query->count();
        $offset = ($page - 1) * $perPage;

        $players = $query
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->offset($offset)
            ->limit($perPage)
            ->get([
                'id',
                'first_name',
                'last_name',
                'full_name',
                'position',
                'team_abbrev',
            ]);

        return response()->json([
            'data' => $players,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'has_more' => ($offset + $perPage) < $total,
            ],
        ]);
    }
}
