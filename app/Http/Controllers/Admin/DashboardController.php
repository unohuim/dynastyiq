<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FantraxPlayer;
use App\Models\ImportRun;
use App\Models\Player;
use App\Services\AdminImports;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class DashboardController extends Controller
{
    public function __construct(
        private AdminImports $imports,
    ) {
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
                'group' => $source['group'] ?? 'player',
                'last_run' => ($lastRun?->finished_at ?? $lastRun?->started_at)?->toIso8601String(),
                'status' => $lastRun?->status,
                'started_at' => $lastRun?->started_at?->toIso8601String(),
                'finished_at' => $lastRun?->finished_at?->toIso8601String(),
                'duration_seconds' => $lastRun?->duration_seconds,
                'run_url' => $this->importRunUrl($source),
                'status_url' => route('admin.imports.status', ['key' => $source['key']]),
                'progress' => $lastRun ? $this->importProgressPayload($lastRun) : null,
            ];
        });

        $hasPlayers = Player::query()->exists();
        $hasFantraxPlayers = FantraxPlayer::query()->exists();

        return view('admin.dashboard', [
            'imports' => $imports,
            'hasPlayers' => $hasPlayers,
            'hasFantraxPlayers' => $hasFantraxPlayers,
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

    /**
     * @param array<string, mixed> $source
     */
    private function importRunUrl(array $source): ?string
    {
        if (isset($source['run_route'])) {
            return route($source['run_route']);
        }

        if (isset($source['command'])) {
            return route('admin.imports.run', ['key' => $source['key']]);
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function importProgressPayload(ImportRun $importRun): array
    {
        $total = $importRun->total_records;
        $processed = $importRun->processed_records ?? 0;
        $dynamicTotal = (bool) ($importRun->meta['dynamic_total'] ?? false);

        return [
            'label' => $importRun->progress_label,
            'total_records' => $total,
            'processed_records' => $processed,
            'successful_records' => $importRun->successful_records ?? 0,
            'failed_records' => $importRun->failed_records ?? 0,
            'skipped_records' => $importRun->skipped_records ?? 0,
            'dynamic_total' => $dynamicTotal,
            'percentage' => $total && ! $dynamicTotal
                ? min(100, (int) floor(($processed / max(1, $total)) * 100))
                : null,
        ];
    }
}
