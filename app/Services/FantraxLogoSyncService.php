<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PlatformLeague;
use App\Models\PlatformTeam;
use App\Support\FantraxLogoBrowserProfile;
use Symfony\Component\Process\Process;

/**
 * Syncs Fantrax team logos through the authenticated browser profile.
 */
final class FantraxLogoSyncService
{
    private const DEBUG_DUMP_RELATIVE_PATH = 'docs/api_responses/fantrax_logos.txt';

    public function __construct(
        private readonly FantraxLogoBrowserProfile $profile,
    ) {
    }

    /**
     * Sync logos for the given local platform league IDs.
     *
     * @param array<int,int> $platformLeagueIds
     * @return array{
     *     configured:bool,
     *     ready:bool,
     *     queued:bool,
     *     ran:bool,
     *     skipped_reason:string|null,
     *     attempted_league_count:int,
     *     candidate_count:int,
     *     selected_league_candidate_count:int,
     *     updated_team_count:int,
     *     replaced_derived_team_count:int,
     *     matched_candidate_count:int,
     *     unmatched_candidate_count:int,
     *     skipped_candidate_count:int,
     *     candidate_results:array<int,array<string,mixed>>,
     *     failed_league_count:int,
     *     errors:array<int,array{league_id:int,message:string}>
     * }
     */
    public function syncForPlatformLeagueIds(array $platformLeagueIds): array
    {
        $this->initializeDebugDump($platformLeagueIds);

        $state = $this->profile->status();
        $summary = [
            'configured' => $state['configured'],
            'ready' => $state['ready'],
            'queued' => false,
            'ran' => false,
            'skipped_reason' => null,
            'attempted_league_count' => 0,
            'candidate_count' => 0,
            'selected_league_candidate_count' => 0,
            'updated_team_count' => 0,
            'replaced_derived_team_count' => 0,
            'matched_candidate_count' => 0,
            'unmatched_candidate_count' => 0,
            'skipped_candidate_count' => 0,
            'candidate_results' => [],
            'failed_league_count' => 0,
            'errors' => [],
        ];

        if ($platformLeagueIds === []) {
            $summary['skipped_reason'] = 'no_fantrax_leagues';
            $this->appendDebugDump('Skipped logo sync: no Fantrax leagues were provided.');

            return $summary;
        }

        if (! $state['ready']) {
            $summary['skipped_reason'] = $state['configured']
                ? 'browser_profile_not_ready'
                : 'browser_profile_not_configured';
            $this->appendDebugDump('Skipped logo sync: ' . $summary['skipped_reason']);

            return $summary;
        }

        $leagues = PlatformLeague::query()
            ->whereIn('id', $platformLeagueIds)
            ->where('platform', 'fantrax')
            ->get(['id', 'platform_league_id']);

        foreach ($leagues as $league) {
            $summary['attempted_league_count']++;
            $this->appendDebugDump("Inspecting Fantrax league {$league->platform_league_id}.");

            try {
                $candidates = $this->inspectLeague($league->platform_league_id);
            } catch (\Throwable $e) {
                $summary['failed_league_count']++;
                $summary['errors'][] = [
                    'league_id' => (int) $league->id,
                    'message' => $e->getMessage(),
                ];
                $this->appendDebugDump("Fantrax league {$league->platform_league_id} failed: {$e->getMessage()}");

                continue;
            }

            $summary['candidate_count'] += count($candidates);
            $persisted = $this->persistCandidates($league, $candidates);
            $summary['selected_league_candidate_count'] += $persisted['selected_league_candidate_count'];
            $summary['updated_team_count'] += $persisted['updated_team_count'];
            $summary['replaced_derived_team_count'] += $persisted['replaced_derived_team_count'];
            $summary['matched_candidate_count'] += $persisted['matched_candidate_count'];
            $summary['unmatched_candidate_count'] += $persisted['unmatched_candidate_count'];
            $summary['skipped_candidate_count'] += $persisted['skipped_candidate_count'];
            $summary['candidate_results'] = [
                ...$summary['candidate_results'],
                ...$persisted['candidate_results'],
            ];
            $this->appendDebugDump(sprintf(
                'Fantrax league %s yielded %d candidate(s), selected %d candidate(s), matched %d candidate(s), unmatched %d candidate(s), skipped %d candidate(s), updated %d team(s), replaced %d derived URL(s).',
                $league->platform_league_id,
                count($candidates),
                $persisted['selected_league_candidate_count'],
                $persisted['matched_candidate_count'],
                $persisted['unmatched_candidate_count'],
                $persisted['skipped_candidate_count'],
                $persisted['updated_team_count'],
                $persisted['replaced_derived_team_count'],
            ));
        }

        $summary['ran'] = true;

        return $summary;
    }

    /**
     * Run the browser inspector for a Fantrax league.
     *
     * @return array<int,array<string,mixed>>
     */
    private function inspectLeague(string $fantraxLeagueId): array
    {
        $profilePath = $this->profile->path();

        if ($profilePath === null) {
            return [];
        }

        $candidates = [];
        $nodePath = $this->nodePath();
        $this->appendDebugDump("Resolved Node executable: {$nodePath}");

        foreach ($this->leagueUrls($fantraxLeagueId) as $url) {
            $this->appendDebugDump("Launching Chromium inspector for {$url}.");

            $command = [
                $nodePath,
                base_path('scripts/inspect-fantrax-network.mjs'),
                $url,
                '--profile',
                $profilePath,
                '--json',
                '--append-dump',
            ];

            if ($this->browserHeadless()) {
                $command[] = '--headless';
            } else {
                $command[] = '--wait-for-login';
            }

            $process = new Process($command, base_path());
            $process->setTimeout($this->browserHeadless() ? 20 : 210);
            $process->run();

            if (! $process->isSuccessful()) {
                $this->appendDebugDump("Chromium inspector failed for {$url}.\nSTDOUT:\n{$process->getOutput()}\nSTDERR:\n{$process->getErrorOutput()}");

                throw new \RuntimeException(trim($process->getErrorOutput()) ?: 'Fantrax browser logo inspection failed.');
            }

            $payload = json_decode(trim($process->getOutput()), true);

            if (! is_array($payload)) {
                $this->appendDebugDump("Chromium inspector returned invalid JSON for {$url}.\nSTDOUT:\n{$process->getOutput()}");

                throw new \RuntimeException('Fantrax browser logo inspection returned invalid JSON.');
            }

            $this->appendDebugDump(sprintf(
                'Chromium inspector returned %d candidate(s) for %s.',
                is_array($payload['logoCandidates'] ?? null) ? count($payload['logoCandidates']) : 0,
                $url,
            ));

            foreach (($payload['logoCandidates'] ?? []) as $candidate) {
                if (is_array($candidate)) {
                    $candidates[] = $candidate;
                }
            }

            if ($this->browserHardStopReason($process->getOutput()) !== null) {
                break;
            }
        }

        return $candidates;
    }

    private function browserHardStopReason(string $output): ?string
    {
        $payload = json_decode(trim($output), true);

        if (! is_array($payload)) {
            return null;
        }

        $markers = $payload['markers'] ?? [];

        if (! is_array($markers)) {
            return null;
        }

        foreach (['LOGIN_ROUTE_DETECTED', 'ACCESS_DENIED_DETECTED'] as $marker) {
            if (in_array($marker, $markers, true)) {
                return $marker;
            }
        }

        return null;
    }

    private function nodePath(): string
    {
        $configuredPath = trim((string) config('apiurls.fantrax.node_path', ''));

        if ($configuredPath !== '') {
            if (is_file($configuredPath) && is_executable($configuredPath)) {
                return $configuredPath;
            }

            $this->appendDebugDump("Configured FANTRAX_NODE_PATH is not executable: {$configuredPath}");
        }

        foreach ([
            '/opt/homebrew/bin/node',
            '/usr/local/bin/node',
            '/usr/bin/node',
        ] as $candidate) {
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return 'node';
    }

    /**
     * Fantrax routes that have been observed to emit team logo mappings.
     *
     * @return array<int,string>
     */
    private function leagueUrls(string $fantraxLeagueId): array
    {
        return [
            "https://www.fantrax.com/fantasy/league/{$fantraxLeagueId}/home;reload=1",
            "https://www.fantrax.com/fantasy/league/{$fantraxLeagueId}/team/preferences",
            "https://www.fantrax.com/fantasy/league/{$fantraxLeagueId}/standings",
        ];
    }

    /**
     * Persist explicit logo candidates for teams that exist locally.
     *
     * @param array<int,array<string,mixed>> $candidates
     * @return array{selected_league_candidate_count:int,updated_team_count:int,replaced_derived_team_count:int,matched_candidate_count:int,unmatched_candidate_count:int,skipped_candidate_count:int,candidate_results:array<int,array<string,mixed>>}
     */
    private function persistCandidates(PlatformLeague $fallbackLeague, array $candidates): array
    {
        $summary = [
            'selected_league_candidate_count' => 0,
            'updated_team_count' => 0,
            'replaced_derived_team_count' => 0,
            'matched_candidate_count' => 0,
            'unmatched_candidate_count' => 0,
            'skipped_candidate_count' => 0,
            'candidate_results' => [],
        ];

        foreach ($candidates as $candidate) {
            $teamId = $this->stringValue($candidate['teamId'] ?? null);
            $teamName = $this->stringValue(
                $candidate['teamName']
                    ?? $candidate['team']
                    ?? $candidate['name']
                    ?? null
            );
            $logoUrl = $this->logoUrl($candidate['logoUrl'] ?? null);
            $candidateLeagueId = $this->stringValue($candidate['leagueId'] ?? null);
            $result = [
                'league_id' => (string) $fallbackLeague->platform_league_id,
                'candidate_league_id' => $candidateLeagueId,
                'team_id' => $teamId,
                'team_name' => $teamName,
                'logo_url' => $logoUrl,
                'matched_platform_team_id' => null,
                'matched_platform_team_name' => null,
                'matched_by' => null,
                'skipped_reason' => null,
                'updated' => false,
            ];

            if ($logoUrl === null) {
                $result['skipped_reason'] = 'invalid_logo_url';
                $summary['skipped_candidate_count']++;
                $summary['candidate_results'][] = $result;

                continue;
            }

            if ($teamId === null && $teamName === null) {
                $result['skipped_reason'] = 'missing_team_identity';
                $summary['skipped_candidate_count']++;
                $summary['candidate_results'][] = $result;

                continue;
            }

            if ($candidateLeagueId !== null && $candidateLeagueId !== $fallbackLeague->platform_league_id) {
                $result['skipped_reason'] = 'outside_requested_league';
                $summary['skipped_candidate_count']++;
                $summary['candidate_results'][] = $result;

                continue;
            }

            $summary['selected_league_candidate_count']++;
            [$team, $matchedBy] = $this->resolveTeam($fallbackLeague, $teamId, $teamName);

            if ($team === null) {
                $result['skipped_reason'] = 'no_local_team_match';
                $summary['unmatched_candidate_count']++;
                $summary['candidate_results'][] = $result;

                continue;
            }

            $result['matched_platform_team_id'] = (int) $team->id;
            $result['matched_platform_team_name'] = (string) $team->name;
            $result['matched_by'] = $matchedBy;
            $summary['matched_candidate_count']++;

            if ($team->logo_url === $logoUrl) {
                $result['skipped_reason'] = 'already_current';
                $summary['skipped_candidate_count']++;
                $summary['candidate_results'][] = $result;

                continue;
            }

            if ($this->isDerivedTeamLogoUrl((string) $team->platform_team_id, $team->logo_url)) {
                $summary['replaced_derived_team_count']++;
            }

            $team->forceFill(['logo_url' => $logoUrl])->save();
            $summary['updated_team_count']++;
            $result['updated'] = true;
            $summary['candidate_results'][] = $result;
        }

        foreach ($summary['candidate_results'] as $result) {
            $this->appendDebugDump(sprintf(
                'Logo candidate league=%s candidate_team_id=%s candidate_team_name=%s logo_url=%s matched_team_id=%s matched_team_name=%s matched_by=%s updated=%s skipped_reason=%s',
                (string) ($result['league_id'] ?? ''),
                (string) ($result['team_id'] ?? ''),
                (string) ($result['team_name'] ?? ''),
                (string) ($result['logo_url'] ?? ''),
                (string) ($result['matched_platform_team_id'] ?? ''),
                (string) ($result['matched_platform_team_name'] ?? ''),
                (string) ($result['matched_by'] ?? ''),
                ($result['updated'] ?? false) === true ? 'true' : 'false',
                (string) ($result['skipped_reason'] ?? ''),
            ));
        }

        return $summary;
    }

    /**
     * Resolve a local team by provider team ID first, then by normalized provider team name.
     *
     * @return array{0:PlatformTeam|null,1:string|null}
     */
    private function resolveTeam(PlatformLeague $league, ?string $teamId, ?string $teamName): array
    {
        if ($teamId !== null) {
            $team = PlatformTeam::query()
                ->where('platform_league_id', $league->id)
                ->where('platform_team_id', $teamId)
                ->first(['id', 'platform_team_id', 'name', 'short_name', 'logo_url']);

            if ($team instanceof PlatformTeam) {
                return [$team, 'team_id'];
            }
        }

        $normalizedTeamName = $this->normalizeTeamName($teamName);

        if ($normalizedTeamName === '') {
            return [null, null];
        }

        $team = PlatformTeam::query()
            ->where('platform_league_id', $league->id)
            ->get(['id', 'platform_team_id', 'name', 'short_name', 'logo_url'])
            ->first(function (PlatformTeam $team) use ($normalizedTeamName): bool {
                return $this->normalizeTeamName($team->name) === $normalizedTeamName
                    || $this->normalizeTeamName($team->short_name) === $normalizedTeamName;
            });

        return $team instanceof PlatformTeam ? [$team, 'team_name'] : [null, null];
    }

    private function normalizeTeamName(mixed $value): string
    {
        $name = $this->stringValue($value);

        if ($name === null) {
            return '';
        }

        return str($name)
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', '')
            ->toString();
    }

    private function isDerivedTeamLogoUrl(string $teamId, ?string $logoUrl): bool
    {
        if ($logoUrl === null || $teamId === '') {
            return false;
        }

        return str_contains($logoUrl, "tmLogo_{$teamId}_");
    }

    private function stringValue(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function logoUrl(mixed $value): ?string
    {
        $url = $this->stringValue($value);

        if ($url === null) {
            return null;
        }

        if (str_starts_with($url, '/assets/')) {
            return "https://fantraximg.com{$url}";
        }

        return str_contains($url, 'fantraximg.com') ? $url : null;
    }

    private function browserHeadless(): bool
    {
        return filter_var(config('apiurls.fantrax.browser_headless', false), FILTER_VALIDATE_BOOL);
    }

    /**
     * Start the logo debug dump for the current refresh attempt.
     *
     * @param array<int,int> $platformLeagueIds
     */
    private function initializeDebugDump(array $platformLeagueIds): void
    {
        $path = base_path(self::DEBUG_DUMP_RELATIVE_PATH);
        $directory = dirname($path);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents($path, implode("\n", [
            'Fantrax logo sync debug dump',
            'Started at: ' . now()->toIso8601String(),
            'Platform league IDs: ' . json_encode(array_values($platformLeagueIds)),
            '',
        ]));
    }

    private function appendDebugDump(string $message): void
    {
        file_put_contents(
            base_path(self::DEBUG_DUMP_RELATIVE_PATH),
            '[' . now()->toIso8601String() . '] ' . $message . "\n",
            FILE_APPEND,
        );
    }
}
