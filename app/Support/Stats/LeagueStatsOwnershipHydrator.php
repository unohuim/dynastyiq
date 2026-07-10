<?php

declare(strict_types=1);

namespace App\Support\Stats;

use App\Models\NhlSeasonStat;
use App\Models\PlatformLeague;
use App\Models\Player;
use Illuminate\Support\Facades\Cache;

/**
 * Adds fantasy league ownership metadata to stats payload rows.
 */
final class LeagueStatsOwnershipHydrator
{
    private const OWNERSHIP_CACHE_TTL_SECONDS = 300;

    /**
     * @param array<string,mixed> $payload
     * @param array<string,float>|null $timings
     * @return array<string,mixed>
     */
    public function hydrate(array $payload, PlatformLeague $league, ?int $userId = null, ?array &$timings = null): array
    {
        $last = hrtime(true);
        $mark = static function (string $key) use (&$timings, &$last): void {
            if ($timings === null) {
                return;
            }

            $now = hrtime(true);
            $timings[$key] = round(($now - $last) / 1_000_000, 2);
            $last = $now;
        };

        $ownership = $this->leagueOwnership($league, $userId);
        $mark('ownership_map_ms');

        $ownershipByPlayerId = $ownership['by_player_id'];
        $ownershipByNhlId = $ownership['by_nhl_id'];
        $seenPlayerIds = [];
        $seenNhlIds = [];
        $usesCustomCap = (bool) data_get($league, 'settings.custom_cap', false);

        if ($usesCustomCap) {
            $payload = $this->applyCustomCapHeadingLabels($payload);
        }

        $payload['data'] = collect($payload['data'] ?? [])
            ->map(function (mixed $row) use ($ownershipByPlayerId, $ownershipByNhlId, &$seenPlayerIds, &$seenNhlIds): mixed {
                if (! is_array($row)) {
                    return $row;
                }

                $playerId = (string) ($row['player_id'] ?? '');
                $nhlPlayerId = (string) ($row['nhl_player_id'] ?? '');

                if ($playerId !== '') {
                    $seenPlayerIds[$playerId] = true;
                }

                if ($nhlPlayerId !== '') {
                    $seenNhlIds[$nhlPlayerId] = true;
                }

                $rowOwnership = $playerId !== ''
                    ? ($ownershipByPlayerId[$playerId] ?? null)
                    : null;
                $rowOwnership ??= $nhlPlayerId !== '' ? ($ownershipByNhlId[$nhlPlayerId] ?? null) : null;

                $row = array_merge($row, [
                    'fantasy_team_id' => $rowOwnership['fantasy_team_id'] ?? null,
                    'fantasy_team_name' => $rowOwnership['fantasy_team_name'] ?? null,
                    'fantasy_team_avatar_url' => $rowOwnership['fantasy_team_avatar_url'] ?? null,
                    'fantasy_team_is_user_team' => $rowOwnership['fantasy_team_is_user_team'] ?? false,
                    'roster_slot' => $rowOwnership['roster_slot'] ?? null,
                    'roster_status' => $rowOwnership['roster_status'] ?? null,
                    'roster_group' => $rowOwnership['roster_group'] ?? null,
                    'roster_sort_order' => $rowOwnership['roster_sort_order'] ?? null,
                    'roster_group_sort_order' => $rowOwnership['roster_group_sort_order'] ?? null,
                    'roster_status_sort_order' => $rowOwnership['roster_status_sort_order'] ?? null,
                ]);

                return $this->applyCustomCapContractFields($row, $rowOwnership);
            })
            ->values()
            ->all();
        $mark('ownership_decorate_ms');

        if (in_array((string) ($league->platform ?? ''), ['fantrax', 'yahoo'], true)) {
            $missingRows = collect($ownership['roster_rows'])
                ->reject(static function (array $row) use ($seenPlayerIds, $seenNhlIds): bool {
                    $playerId = (string) ($row['player_id'] ?? '');
                    $nhlPlayerId = (string) ($row['nhl_player_id'] ?? '');

                    return ($playerId !== '' && isset($seenPlayerIds[$playerId]))
                        || ($nhlPlayerId !== '' && isset($seenNhlIds[$nhlPlayerId]));
                })
                ->values()
                ->all();
            $missingRows = $this->hydrateRosterOnlyRowsFromSeasonStats($missingRows, $payload);
            $missingRows = collect($missingRows)
                ->map(fn (array $row): array => $this->applyCustomCapContractFields($row, $row))
                ->values()
                ->all();

            $payload['data'] = array_values(array_merge($payload['data'], $missingRows));
        }
        $mark('ownership_missing_rows_ms');

        return $payload;
    }

    /**
     * Return current fantasy owner metadata keyed by local and NHL player ids.
     *
     * @return array{by_player_id:array<string,array<string,mixed>>,by_nhl_id:array<string,array<string,mixed>>,roster_rows:array<int,array<string,mixed>>}
     */
    private function leagueOwnership(PlatformLeague $league, ?int $userId = null): array
    {
        $platform = (string) ($league->platform ?? '');
        $settingsHash = md5((string) json_encode($league->settings ?? []));
        $cacheKey = sprintf('stats_ownership:%s:%s:%s:%s', $league->id, $userId ?? 'guest', $platform, $settingsHash);

        return Cache::remember(
            $cacheKey,
            now()->addSeconds(self::OWNERSHIP_CACHE_TTL_SECONDS),
            fn (): array => $this->buildLeagueOwnership($league, $userId),
        );
    }

    /**
     * @return array{by_player_id:array<string,array<string,mixed>>,by_nhl_id:array<string,array<string,mixed>>,roster_rows:array<int,array<string,mixed>>}
     */
    private function buildLeagueOwnership(PlatformLeague $league, ?int $userId = null): array
    {
        $platform = (string) ($league->platform ?? '');
        $usesProviderSlotOrder = $platform === 'yahoo';
        $fillsConfiguredSlots = in_array($platform, ['fantrax', 'yahoo'], true);
        $usesCustomCap = (bool) data_get($league, 'settings.custom_cap', false);
        $fantraxContractDefinitions = $this->normalizeFantraxContractCodeDefinitions(
            data_get($league, 'settings.fantrax_contract_code_definitions', []),
        );
        $rosterSlots = $league->rosterSlots()
            ->get(['slot', 'count', 'sort_order']);
        $slotOrder = $rosterSlots
            ->pluck('sort_order', 'slot')
            ->map(static fn ($value): int => (int) $value)
            ->all();
        $teams = $league->teams()
            ->select('id', 'platform_team_id', 'name')
            ->with([
                'roster:id,nhl_id,full_name,first_name,last_name,position,pos_type,dob,team_abbrev,head_shot_url,is_goalie,status',
                'users' => static function ($query): void {
                    $query->wherePivot('is_active', true)
                        ->select('users.id')
                        ->with(['socialAccounts:id,user_id,avatar']);
                },
            ])
            ->get();

        $byPlayerId = [];
        $byNhlId = [];
        $rosterRows = [];

        foreach ($teams as $team) {
            $defaultAvatar = config('ui.default_team_avatar')
                ?: 'https://ui-avatars.com/api/?name=' . urlencode((string) $team->name) . '&background=E5E7EB&color=111827&size=64';
            $ownerAvatar = $defaultAvatar;

            foreach ($team->users as $user) {
                $avatar = optional($user->socialAccounts->first())->avatar;

                if (filled($avatar)) {
                    $ownerAvatar = (string) $avatar;
                    break;
                }
            }
            $isUserTeam = $userId !== null && $team->users->contains(static fn ($user): bool => (int) $user->id === $userId);

            $ownerPayload = [
                'fantasy_team_id' => (string) $team->platform_team_id,
                'fantasy_team_name' => (string) $team->name,
                'fantasy_team_avatar_url' => $ownerAvatar,
                'fantasy_team_is_user_team' => $isUserTeam,
            ];
            $teamSlotCounts = [];

            foreach ($team->roster as $player) {
                $slot = (string) ($player->pivot->slot ?? '');
                $slotKey = strtoupper(trim($slot));
                $rosterStatus = (string) ($player->pivot->status ?? '');
                $eligibility = $this->normalizeRosterEligibility($player->pivot->eligibility ?? null);
                $membershipMetadata = $this->normalizeRosterMetadata($player->pivot->metadata ?? null);
                $customCapPayload = $usesCustomCap
                    ? $this->fantraxCustomCapPayload($membershipMetadata, $fantraxContractDefinitions)
                    : [];
                $displaySlot = $usesProviderSlotOrder
                    ? ($slotKey !== '' ? $slotKey : (strtolower(trim($rosterStatus)) === 'na' ? 'NA' : ''))
                    : $this->displayRosterSlot($slot, $rosterStatus, $eligibility, (string) ($player->position ?? ''));
                $rosterGroup = $this->isMinorRosterRow($slot, $rosterStatus) ? 'minor' : 'active';
                $rosterSortOrder = $usesProviderSlotOrder
                    ? ($slotOrder[$slot] ?? $slotOrder[$slotKey] ?? $slotOrder[$displaySlot] ?? $this->fallbackRosterSlotOrder($displaySlot))
                    : ($rosterGroup === 'minor'
                        ? $this->minorRosterPositionSortOrder($eligibility)
                        : ($slotOrder[$slot] ?? $this->fallbackRosterSlotOrder($displaySlot)));
                $rosterPayload = array_merge($ownerPayload, [
                    'roster_slot' => $displaySlot,
                    'roster_status' => $rosterStatus,
                    'roster_group' => $rosterGroup,
                    'roster_sort_order' => $rosterSortOrder,
                    'roster_group_sort_order' => $usesProviderSlotOrder ? 0 : ($rosterGroup === 'minor' ? 1 : 0),
                    'roster_status_sort_order' => match ($rosterStatus) {
                        'active' => 10,
                        'bench' => 20,
                        'ir' => 30,
                        'na' => 40,
                        'taxi' => 50,
                        default => 90,
                    },
                ], $customCapPayload);
                $teamSlotCounts[$displaySlot] = ($teamSlotCounts[$displaySlot] ?? 0) + 1;

                $byPlayerId[(string) $player->id] = $rosterPayload;

                if (filled($player->nhl_id)) {
                    $byNhlId[(string) $player->nhl_id] = $rosterPayload;
                }

                $rosterRows[] = array_merge($this->rosterOnlyStatsRow($player), $rosterPayload);
            }

            if ($fillsConfiguredSlots) {
                foreach ($rosterSlots as $slotSetting) {
                    $slot = strtoupper(trim((string) $slotSetting->slot));
                    if ($this->normalizedFallbackSlot($slot) === 'MIN') {
                        continue;
                    }

                    $missingCount = max(0, (int) $slotSetting->count - (int) ($teamSlotCounts[$slot] ?? 0));

                    for ($i = 0; $i < $missingCount; $i++) {
                        $rosterRows[] = $this->emptyRosterSlotStatsRow(
                            $ownerPayload,
                            $slot,
                            (int) $slotSetting->sort_order,
                        );
                    }
                }
            }
        }

        return [
            'by_player_id' => $byPlayerId,
            'by_nhl_id' => $byNhlId,
            'roster_rows' => $rosterRows,
        ];
    }

    /**
     * Fill appended roster-only rows from the same NHL season stat source as the table payload.
     *
     * @param array<int,array<string,mixed>> $rows
     * @param array<string,mixed> $payload
     * @return array<int,array<string,mixed>>
     */
    private function hydrateRosterOnlyRowsFromSeasonStats(array $rows, array $payload): array
    {
        if ($rows === []) {
            return [];
        }

        $season = (string) data_get($payload, 'meta.season', '');
        $gameType = (int) data_get($payload, 'meta.game_type', 2);

        if ($season === '') {
            return $rows;
        }

        $nhlPlayerIds = collect($rows)
            ->pluck('nhl_player_id')
            ->filter()
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();

        if ($nhlPlayerIds->isEmpty()) {
            return $rows;
        }

        $headingKeys = collect($payload['headings'] ?? [])
            ->filter(static fn (mixed $heading): bool => is_array($heading))
            ->pluck('key');
        $goalieColumnKeys = collect(data_get($payload, 'settings.columnGroups.goalie', []))
            ->filter(static fn (mixed $heading): bool => is_array($heading))
            ->pluck('key');
        $statKeys = $headingKeys
            ->merge($goalieColumnKeys)
            ->map(static fn (mixed $key): string => (string) $key)
            ->reject(static fn (string $key): bool => in_array($key, [
                '',
                '__rk',
                '__owner',
                'name',
                'player',
                'age',
                'team',
                'league',
                'pos',
                'pos_type',
                'contract_value',
                'contract_value_num',
                'contract_last_year',
                'contract_last_year_num',
                'avatar_url',
                'head_shot_url',
                'id',
                'nhl_player_id',
            ], true))
            ->unique()
            ->values();

        $statsByPlayerId = NhlSeasonStat::query()
            ->where('season_id', $season)
            ->where('game_type', $gameType)
            ->whereIn('nhl_player_id', $nhlPlayerIds->all())
            ->get()
            ->keyBy(static fn (NhlSeasonStat $stat): int => (int) $stat->nhl_player_id);

        return collect($rows)
            ->map(function (array $row) use ($statsByPlayerId, $statKeys): array {
                $stat = $statsByPlayerId->get((int) ($row['nhl_player_id'] ?? 0));

                if (! $stat instanceof NhlSeasonStat) {
                    return $row;
                }

                foreach ($statKeys as $key) {
                    $value = $stat->{$key} ?? null;

                    if ($value !== null) {
                        $row[$key] = is_numeric($value) ? (float) $value : $value;
                    }
                }

                if (isset($row['gp']) && is_float($row['gp']) && fmod($row['gp'], 1.0) === 0.0) {
                    $row['gp'] = (int) $row['gp'];
                }

                return $row;
            })
            ->values()
            ->all();
    }

    /**
     * Build a league stats row for rostered players absent from the stats payload.
     */
    private function rosterOnlyStatsRow(Player $player): array
    {
        return [
            'name' => (string) ($player->full_name ?? trim(($player->first_name ?? '') . ' ' . ($player->last_name ?? ''))),
            'player_id' => (int) $player->id,
            'avatar_url' => $player->head_shot_url,
            'age' => $this->playerAge($player),
            'team' => $player->team_abbrev,
            'league' => null,
            'pos' => $player->is_goalie ? 'G' : $player->position,
            'pos_type' => $player->is_goalie ? 'G' : $player->pos_type,
            'is_goalie' => (bool) $player->is_goalie,
            'contract_value' => null,
            'contract_value_num' => null,
            'contract_last_year' => null,
            'contract_last_year_num' => null,
            'gp' => null,
            'nhl_player_id' => $player->nhl_id,
            'toi_seconds' => null,
            'toi' => null,
            'league_roster_only' => true,
        ];
    }

    /**
     * @param array<string,mixed> $ownerPayload
     */
    private function emptyRosterSlotStatsRow(array $ownerPayload, string $slot, int $sortOrder): array
    {
        $slot = strtoupper(trim($slot));
        $status = match ($slot) {
            'BN' => 'bench',
            'IR', 'IR+' => 'ir',
            'NA' => 'na',
            default => 'active',
        };

        return array_merge([
            'name' => '',
            'player_id' => null,
            'avatar_url' => null,
            'age' => null,
            'team' => null,
            'league' => null,
            'pos' => null,
            'pos_type' => null,
            'is_goalie' => false,
            'contract_value' => null,
            'contract_value_num' => null,
            'contract_last_year' => null,
            'contract_last_year_num' => null,
            'gp' => null,
            'nhl_player_id' => null,
            'toi_seconds' => null,
            'toi' => null,
            'league_roster_only' => true,
            'league_roster_placeholder' => true,
        ], $ownerPayload, [
            'roster_slot' => $slot,
            'roster_status' => $status,
            'roster_group' => 'active',
            'roster_sort_order' => $sortOrder,
            'roster_group_sort_order' => 0,
            'roster_status_sort_order' => match ($status) {
                'active' => 10,
                'bench' => 20,
                'ir' => 30,
                'na' => 40,
                default => 90,
            },
        ]);
    }

    /**
     * Apply custom Fantrax salary and contract-code fields to the stats contract columns.
     *
     * @param array<string,mixed> $row
     * @param array<string,mixed>|null $ownership
     * @return array<string,mixed>
     */
    private function applyCustomCapContractFields(array $row, ?array $ownership): array
    {
        if (! $ownership || ! isset($ownership['fantrax_salary_cap_hit'])) {
            return $row;
        }

        $capHit = (int) $ownership['fantrax_salary_cap_hit'];

        if ($capHit > 0) {
            $row['contract_value'] = '$' . number_format($capHit / 1_000_000, 2) . 'm';
            $row['contract_value_num'] = round($capHit / 1_000_000, 2);
        }

        $yearsRemaining = $ownership['fantrax_contract_years_remaining'] ?? null;
        $suffixMeansYears = (bool) ($ownership['fantrax_contract_suffix_years_remaining'] ?? false);
        $contractCode = trim((string) ($ownership['fantrax_contract_code'] ?? ''));

        if (! $suffixMeansYears || ! is_numeric($yearsRemaining) || (int) $yearsRemaining <= 0) {
            if ($contractCode !== '') {
                $row['contract_last_year'] = $contractCode;
            }

            return $row;
        }

        $seasonStartYear = $this->currentFantraxCustomCapSeasonStartYear();

        $finalStartYear = $seasonStartYear + (int) $yearsRemaining - 1;
        $finalEndYear = $finalStartYear + 1;

        $row['contract_last_year'] = $contractCode !== ''
            ? $contractCode
            : sprintf('%d-%02d', $finalStartYear, $finalEndYear % 100);
        $row['contract_last_year_num'] = $finalEndYear;

        return $row;
    }

    /**
     * Relabel custom-cap contract columns without changing their existing keys.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function applyCustomCapHeadingLabels(array $payload): array
    {
        $payload['headings'] = collect($payload['headings'] ?? [])
            ->map(static function (mixed $heading): mixed {
                if (! is_array($heading) || ($heading['key'] ?? null) !== 'contract_last_year') {
                    return $heading;
                }

                $heading['label'] = 'Type';

                return $heading;
            })
            ->values()
            ->all();

        return $payload;
    }

    /**
     * Build normalized custom-cap payload from Fantrax roster metadata.
     *
     * @param array<string,mixed> $metadata
     * @param array<string,array<string,mixed>> $definitions
     * @return array<string,mixed>
     */
    private function fantraxCustomCapPayload(array $metadata, array $definitions): array
    {
        $payload = [];
        $salary = $metadata['fantrax_salary'] ?? null;

        if (is_numeric($salary)) {
            $raw = (int) $salary;
            $payload['fantrax_salary_raw'] = $raw;
            $payload['fantrax_salary_cap_hit'] = $raw * 1000;
        }

        $code = data_get($metadata, 'fantrax_contract.name');

        if (! is_string($code) || trim($code) === '') {
            return $payload;
        }

        $code = strtoupper(trim($code));
        $prefix = $code;
        $yearsRemaining = null;

        if (preg_match('/^([A-Z]+)(\d+)$/', $code, $matches)) {
            $prefix = $matches[1];
            $yearsRemaining = (int) $matches[2];
        }

        $definition = $definitions[$prefix] ?? null;

        $payload['fantrax_contract_code'] = $code;
        $payload['fantrax_contract_prefix'] = $prefix;
        $payload['fantrax_contract_years_remaining'] = $yearsRemaining;
        $payload['fantrax_contract_label'] = $definition['label'] ?? null;
        $payload['fantrax_contract_type'] = $definition['type'] ?? null;
        $payload['fantrax_contract_suffix_years_remaining'] = (bool) ($definition['suffix_years_remaining'] ?? false);

        return $payload;
    }

    /**
     * Normalize saved Fantrax contract-code definitions keyed by prefix.
     *
     * @param mixed $definitions
     * @return array<string,array<string,mixed>>
     */
    private function normalizeFantraxContractCodeDefinitions(mixed $definitions): array
    {
        if (! is_array($definitions)) {
            return [];
        }

        $normalized = [];

        foreach ($definitions as $prefix => $definition) {
            if (! is_array($definition)) {
                continue;
            }

            $prefix = strtoupper(preg_replace('/[^A-Z]/i', '', (string) $prefix) ?? '');
            $label = trim((string) ($definition['label'] ?? ''));
            $type = trim((string) ($definition['type'] ?? ''));

            if ($prefix === '' || ($label === '' && $type === '')) {
                continue;
            }

            $normalized[$prefix] = [
                'label' => $label,
                'type' => $type,
                'suffix_years_remaining' => (bool) ($definition['suffix_years_remaining'] ?? true),
            ];
        }

        return $normalized;
    }

    /**
     * Decode stored roster membership metadata.
     *
     * @return array<string,mixed>
     */
    private function normalizeRosterMetadata(mixed $metadata): array
    {
        if (is_array($metadata)) {
            return $metadata;
        }

        if (is_string($metadata) && trim($metadata) !== '') {
            $decoded = json_decode($metadata, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    /**
     * Return the current Fantrax custom-cap season start year.
     *
     * The custom salary season turns over on July 1, so June 30, 2026
     * belongs to 2025-26 and July 1, 2026 belongs to 2026-27.
     */
    private function currentFantraxCustomCapSeasonStartYear(): int
    {
        $today = now();

        return $today->month >= 7 ? $today->year : $today->year - 1;
    }

    /**
     * Normalize stored roster eligibility into a flat string list.
     *
     * @return array<int,string>
     */
    private function normalizeRosterEligibility(mixed $eligibility): array
    {
        if (is_string($eligibility)) {
            $decoded = json_decode($eligibility, true);
            $eligibility = json_last_error() === JSON_ERROR_NONE ? $decoded : [$eligibility];
        }

        if (! is_array($eligibility)) {
            return [];
        }

        return collect($eligibility)
            ->flatten()
            ->map(static fn (mixed $value): string => trim((string) $value))
            ->filter(static fn (string $value): bool => $value !== '')
            ->values()
            ->all();
    }

    /**
     * Return the display roster slot used for league roster ordering.
     *
     * @param array<int,string> $eligibility
     */
    private function displayRosterSlot(string $slot, string $status, array $eligibility, string $position): string
    {
        $slot = strtoupper(trim($slot));

        if ($this->isMinorRosterRow($slot, $status)) {
            return collect($eligibility)
                ->map(fn (string $value): string => $this->normalizedMinorPosition($value))
                ->first(static fn (string $value): bool => in_array($value, ['C', 'LW', 'RW', 'D', 'G'], true))
                ?: $this->normalizedMinorPosition($position)
                ?: 'MIN';
        }

        if ($slot !== '') {
            return match ($slot) {
                'BN' => 'BEN',
                'MINOR', 'MINORS', 'MINORS_ROSTER', 'MINORSROSTER' => 'MIN',
                default => $slot,
            };
        }

        if (strtolower(trim($status)) === 'na') {
            return 'MIN';
        }

        return collect($eligibility)
            ->map(static fn (string $value): string => strtoupper(trim($value)))
            ->first(static fn (string $value): bool => $value !== '' && ! in_array($value, [
                'F',
                'UTIL',
                'UTILS',
                'UTILITY',
                'UTL',
                'W/R/T',
            ], true)) ?: strtoupper(trim($position));
    }

    /**
     * Return provider-neutral fallback roster slot order.
     */
    private function fallbackRosterSlotOrder(string $slot): int
    {
        $order = [
            'C' => 10,
            'LW' => 20,
            'RW' => 30,
            'F' => 40,
            'D' => 50,
            'SKT' => 60,
            'G' => 70,
            'RES' => 80,
            'BEN' => 90,
            'IR' => 100,
            'MIN' => 110,
        ];

        return $order[$this->normalizedFallbackSlot($slot)] ?? 999;
    }

    /**
     * Return minor roster player ordering by actual hockey position.
     *
     * @param array<int,string> $eligibility
     */
    private function minorRosterPositionSortOrder(array $eligibility): int
    {
        $order = [
            'C' => 10,
            'LW' => 20,
            'RW' => 30,
            'D' => 40,
            'G' => 50,
        ];

        return collect($eligibility)
            ->map(fn (string $value): string => $this->normalizedMinorPosition($value))
            ->filter()
            ->map(static fn (string $value): int => $order[$value] ?? 999)
            ->min() ?? 999;
    }

    /**
     * Normalize provider position variants into the minor roster ordering vocabulary.
     */
    private function normalizedMinorPosition(string $position): string
    {
        $position = strtoupper(trim($position));

        return match ($position) {
            'L' => 'LW',
            'R' => 'RW',
            'LD', 'RD' => 'D',
            default => $position,
        };
    }

    /**
     * Normalize provider slot variants into fallback roster ordering vocabulary.
     */
    private function normalizedFallbackSlot(string $slot): string
    {
        $slot = strtoupper(trim($slot));

        return match ($slot) {
            'L' => 'LW',
            'R' => 'RW',
            'BN', 'BENCH' => 'BEN',
            'MINOR', 'MINORS', 'MINORS_ROSTER', 'MINORSROSTER' => 'MIN',
            'UTIL', 'UTILS', 'UTILITY', 'UTL', 'W/R/T' => 'F',
            default => $slot,
        };
    }

    /**
     * Determine whether a roster row belongs under the minor league separator.
     */
    private function isMinorRosterRow(string $slot, string $status): bool
    {
        return $this->normalizedFallbackSlot($slot) === 'MIN' || strtolower(trim($status)) === 'na';
    }

    private function playerAge($player): ?int
    {
        if (! $player) {
            return null;
        }

        if (method_exists($player, 'age')) {
            return $player->age();
        }

        if (! empty($player->dob)) {
            return \Illuminate\Support\Carbon::parse($player->dob)->age;
        }

        return null;
    }
}
