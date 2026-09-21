<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\ImportNhlAnticipatedLineupTeamJob;
use App\Models\ImportRun;
use App\Models\NhlCurrentLineup;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/** Queues one anticipated-lineup discovery job per eligible team. */
class ImportNhlAnticipatedLineupsCommand extends Command
{
    protected $signature = 'nhl:import-anticipated-lineups
        {--window=all : all, within-two-hours, or outside-two-hours}
        {--import-run-id= : Internal admin import run id}';

    protected $description = 'Queue anticipated-lineup discovery for teams playing today and tomorrow';

    public function handle(): int
    {
        $window = (string) $this->option('window');
        if (! in_array($window, ['all', 'within-two-hours', 'outside-two-hours'], true)) {
            $this->error('Window must be all, within-two-hours, or outside-two-hours.');
            return self::FAILURE;
        }

        $today = Carbon::now('America/Toronto')->startOfDay();
        $tomorrow = $today->copy()->addDay();
        $games = DB::table('nhl_games')
            ->whereBetween('game_date', [$today->toDateString(), $tomorrow->toDateString()])
            ->whereNotNull('start_time_utc')
            ->when($window === 'within-two-hours', fn ($query) => $query
                ->whereDate('game_date', $today->toDateString())
                ->where('start_time_utc', '<=', now()->addHours(2)))
            ->when($window === 'outside-two-hours', fn ($query) => $query->where('start_time_utc', '>', now()->addHours(2)))
            ->orderBy('start_time_utc')->get();

        $teamIds = DB::table('nhl_teams')->pluck('nhl_id', 'abbrev');
        $gamesById = $games->keyBy('nhl_game_id');
        $current = NhlCurrentLineup::query()->with('observation')
            ->whereIn('nhl_game_id', $games->pluck('nhl_game_id'))->get()
            ->filter(function (NhlCurrentLineup $lineup) use ($gamesById): bool {
                $game = $gamesById->get($lineup->nhl_game_id);

                return $game !== null && $lineup->observation !== null
                    && $lineup->observation->isEligibleForGameDate((string) $game->game_date);
            })
            ->keyBy(fn (object $row): string => $row->nhl_game_id . ':' . $row->team_id);
        $jobs = $games->flatMap(function (object $game) use ($teamIds, $current): array {
            $awayTeam = mb_strtoupper((string) $game->away_team_abbrev);
            $homeTeam = mb_strtoupper((string) $game->home_team_abbrev);
            $awayTeamId = $teamIds->get($awayTeam);
            $homeTeamId = $teamIds->get($homeTeam);
            $awayCurrent = $awayTeamId === null
                ? null
                : $current->get($game->nhl_game_id . ':' . $awayTeamId);
            $homeCurrent = $homeTeamId === null
                ? null
                : $current->get($game->nhl_game_id . ':' . $homeTeamId);
            $awayReported = (int) ($awayCurrent->source_count ?? 0) >= 1;
            $homeReported = (int) ($homeCurrent->source_count ?? 0) >= 1;

            if (! $awayReported || ! $homeReported) {
                return array_values(array_filter([
                    ! $awayReported && $awayTeam !== '' ? [$game, $awayTeam, $awayTeamId] : null,
                    ! $homeReported && $homeTeam !== '' ? [$game, $homeTeam, $homeTeamId] : null,
                ]));
            }

            return array_values(array_filter([
                $awayCurrent->evidence_status !== 'official' && (int) $awayCurrent->source_count < 2
                    ? [$game, $awayTeam, $awayTeamId] : null,
                $homeCurrent->evidence_status !== 'official' && (int) $homeCurrent->source_count < 2
                    ? [$game, $homeTeam, $homeTeamId] : null,
            ]));
        })->values();

        $this->writeLocalImportAudit($jobs, $window);
        $run = $this->importRun();
        $run?->setProgressTotal($jobs->count(), 'Team lineup searches');
        if ($jobs->isEmpty()) {
            $run?->markCompleted();
            $this->info('No eligible teams remain today or tomorrow.');
            return self::SUCCESS;
        }

        foreach ($jobs as [$game, $teamAbbrev, $teamId]) {
            if ($teamId === null) {
                $run?->recordProcessed('skipped');
                continue;
            }
            ImportNhlAnticipatedLineupTeamJob::dispatch(
                (int) $game->nhl_game_id,
                $teamAbbrev,
                (int) $teamId,
                $run?->id,
            );
        }

        $run?->refresh();
        if (($run?->processed_records ?? 0) >= ($run?->total_records ?? 1)) {
            $run?->markCompleted();
        }
        $this->info("Queued {$jobs->count()} team lineup searches.");

        return self::SUCCESS;
    }

    private function importRun(): ?ImportRun
    {
        return $this->option('import-run-id')
            ? ImportRun::query()->find((int) $this->option('import-run-id'))
            : null;
    }

    /** @param Collection<int,array{0:object,1:string,2:mixed}> $jobs */
    private function writeLocalImportAudit(Collection $jobs, string $window): void
    {
        if (! app()->environment('local')) {
            return;
        }

        $timestamp = Carbon::now('America/Toronto');
        $directory = base_path('docs/troubleshooting/lineups');
        File::ensureDirectoryExists($directory);
        $this->deleteGeneratedLocalAudits($directory);
        $filename = 'import_' . $timestamp->format('Ymd_His_u') . '.md';
        $entries = $jobs->map(function (array $job): string {
            [$game, $teamAbbrev, $teamId] = $job;
            $opponent = $teamAbbrev === mb_strtoupper((string) $game->home_team_abbrev)
                ? mb_strtoupper((string) $game->away_team_abbrev)
                : mb_strtoupper((string) $game->home_team_abbrev);

            return sprintf(
                '- `%s` vs `%s` — game `%s`, date `%s`, start `%s`, team ID `%s`, **%s**',
                $teamAbbrev,
                $opponent,
                (string) $game->nhl_game_id,
                (string) $game->game_date,
                (string) $game->start_time_utc,
                $teamId === null ? 'missing' : (string) $teamId,
                $teamId === null ? 'skipped: missing NHL team ID' : 'dispatched'
            );
        })->implode("\n");
        $markdown = implode("\n", [
            '# Anticipated lineup import',
            '',
            '- Started: ' . $timestamp->toIso8601String(),
            '- Window: `' . $window . '`',
            '- Eligible team jobs: ' . $jobs->count(),
            '',
            '## Teams and games',
            '',
            $entries !== '' ? $entries : '_No eligible team jobs._',
            '',
        ]);
        File::put($directory . '/' . $filename, $markdown);

        $jobs->pluck(1)->filter()->unique()->each(function (string $teamAbbrev) use ($filename): void {
            $teamDirectory = base_path('docs/troubleshooting/lineups/' . mb_strtoupper($teamAbbrev));
            File::ensureDirectoryExists($teamDirectory);
            File::put($teamDirectory . '/search.md', implode("\n", [
                '# ' . mb_strtoupper($teamAbbrev) . ' lineup searches',
                '',
                '- Import audit: `../' . $filename . '`',
                '',
            ]));
        });
    }

    private function deleteGeneratedLocalAudits(string $directory): void
    {
        $readme = $directory . '/README.md';
        $generated = collect(File::allFiles($directory))
            ->map(fn (\SplFileInfo $file): string => $file->getPathname())
            ->filter(fn (string $path): bool => mb_strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'md'
                && $path !== $readme)
            ->values()
            ->all();

        if ($generated !== []) {
            File::delete($generated);
        }
    }
}
