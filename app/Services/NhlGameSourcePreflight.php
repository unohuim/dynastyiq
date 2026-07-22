<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\NhlGameSourceStatus;
use App\Traits\HasAPITrait;
use Illuminate\Support\Facades\Http;

class NhlGameSourcePreflight
{
    use HasAPITrait;

    /**
     * Check all source feeds required before a game import pipeline can start.
     *
     * @return array{allowed: bool, core_allowed: bool, on_ice_allowed: bool, statuses: array<int, array<string, mixed>>, message: string|null, core_message: string|null, on_ice_message: string|null}
     */
    public function check(int $gameId): array
    {
        $statuses = [
            $this->checkConfiguredSource($gameId, NhlGameSourceStatus::SOURCE_PBP),
            $this->checkConfiguredSource($gameId, NhlGameSourceStatus::SOURCE_BOXSCORE),
            $this->checkShifts($gameId),
        ];

        foreach ($statuses as $status) {
            NhlGameSourceStatus::query()->updateOrCreate(
                [
                    'nhl_game_id' => $gameId,
                    'source' => $status['source'],
                ],
                [
                    'status' => $status['status'],
                    'reason' => $status['reason'],
                    'url' => $status['url'],
                    'details' => $status['details'],
                    'checked_at' => now(),
                ]
            );
        }

        return $this->resultFromStatuses($statuses);
    }

    /**
     * Use stored source rows when complete, otherwise run a fresh source check.
     *
     * @return array{allowed: bool, core_allowed: bool, on_ice_allowed: bool, statuses: array<int, array<string, mixed>>, message: string|null, core_message: string|null, on_ice_message: string|null}
     */
    public function storedOrCheck(int $gameId): array
    {
        $rows = NhlGameSourceStatus::query()
            ->where('nhl_game_id', $gameId)
            ->whereIn('source', [
                NhlGameSourceStatus::SOURCE_PBP,
                NhlGameSourceStatus::SOURCE_BOXSCORE,
                NhlGameSourceStatus::SOURCE_SHIFTS,
            ])
            ->get();

        if ($rows->count() < 3) {
            return $this->check($gameId);
        }

        return $this->resultFromStatuses($rows->map(fn (NhlGameSourceStatus $status): array => [
            'nhl_game_id' => $gameId,
            'source' => $status->source,
            'status' => $status->status,
            'reason' => $status->reason,
            'url' => $status->url,
            'details' => $status->details,
        ])->values()->all());
    }

    /**
     * Return the exact shiftcharts URL used by the NHL shift source.
     */
    public function shiftsUrl(int $gameId): string
    {
        return "https://api.nhle.com/stats/rest/en/shiftcharts?cayenneExp=gameId={$gameId}";
    }

    /**
     * Return whether the stored shiftcharts source is available.
     *
     * Games without a stored source row are treated as available for legacy validations.
     */
    public function storedShiftsAvailable(int $gameId): bool
    {
        $status = NhlGameSourceStatus::query()
            ->where('nhl_game_id', $gameId)
            ->where('source', NhlGameSourceStatus::SOURCE_SHIFTS)
            ->value('status');

        if ($status !== NhlGameSourceStatus::STATUS_AVAILABLE) {
            $htmlToiStatus = NhlGameSourceStatus::query()
                ->where('nhl_game_id', $gameId)
                ->where('source', NhlGameSourceStatus::SOURCE_HTML_TOI)
                ->value('status');

            if ($htmlToiStatus === NhlGameSourceStatus::STATUS_AVAILABLE) {
                return true;
            }
        }

        return $status === null || $status === NhlGameSourceStatus::STATUS_AVAILABLE;
    }

    /**
     * @return array<string, mixed>
     */
    private function checkConfiguredSource(int $gameId, string $source): array
    {
        $url = $this->getApiUrl('nhl', $source, ['gameId' => $gameId]);

        try {
            $payload = $this->getAPIData('nhl', $source, ['gameId' => $gameId]);
        } catch (\Throwable $exception) {
            return $this->status($gameId, $source, $url, NhlGameSourceStatus::STATUS_UNAVAILABLE, 'request_failed', [
                'message' => $exception->getMessage(),
            ]);
        }

        if ($source === NhlGameSourceStatus::SOURCE_PBP) {
            return $this->pbpStatus($gameId, $url, $payload);
        }

        return $this->boxscoreStatus($gameId, $url, $payload);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function pbpStatus(int $gameId, string $url, array $payload): array
    {
        $gameType = $payload['gameType'] ?? null;
        $eligibility = app(NhlGameImportEligibility::class);

        if (! $eligibility->allowsGameType($gameType)) {
            return $this->status($gameId, NhlGameSourceStatus::SOURCE_PBP, $url, NhlGameSourceStatus::STATUS_UNAVAILABLE, 'unsupported_game_type', [
                'game_type' => $gameType,
                'allowed_game_types' => $eligibility->allowedGameTypeList(),
            ]);
        }

        $plays = $payload['plays'] ?? [];

        if (! is_array($plays) || $plays === []) {
            return $this->status($gameId, NhlGameSourceStatus::SOURCE_PBP, $url, NhlGameSourceStatus::STATUS_EMPTY, 'empty_plays', [
                'game_type' => $gameType,
                'plays_count' => is_array($plays) ? count($plays) : 0,
            ]);
        }

        return $this->status($gameId, NhlGameSourceStatus::SOURCE_PBP, $url, NhlGameSourceStatus::STATUS_AVAILABLE, null, [
            'game_type' => $gameType,
            'plays_count' => count($plays),
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function boxscoreStatus(int $gameId, string $url, array $payload): array
    {
        $stats = $payload['playerByGameStats'] ?? [];
        $available = is_array($stats) && $stats !== [];

        return $this->status(
            $gameId,
            NhlGameSourceStatus::SOURCE_BOXSCORE,
            $url,
            $available ? NhlGameSourceStatus::STATUS_AVAILABLE : NhlGameSourceStatus::STATUS_EMPTY,
            $available ? null : 'empty_player_stats',
            [
                'has_player_by_game_stats' => $available,
                'away_groups' => is_array($stats['awayTeam'] ?? null) ? count($stats['awayTeam']) : 0,
                'home_groups' => is_array($stats['homeTeam'] ?? null) ? count($stats['homeTeam']) : 0,
            ]
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function checkShifts(int $gameId): array
    {
        $url = $this->shiftsUrl($gameId);

        try {
            $payload = Http::timeout(30)->acceptJson()->get($url)->throw()->json();
        } catch (\Throwable $exception) {
            return $this->status($gameId, NhlGameSourceStatus::SOURCE_SHIFTS, $url, NhlGameSourceStatus::STATUS_UNAVAILABLE, 'request_failed', [
                'message' => $exception->getMessage(),
            ]);
        }

        $data = is_array($payload) ? ($payload['data'] ?? []) : [];
        $count = is_array($data) ? count($data) : 0;
        $total = is_array($payload) ? ($payload['total'] ?? null) : null;
        $available = $count > 0;

        return $this->status(
            $gameId,
            NhlGameSourceStatus::SOURCE_SHIFTS,
            $url,
            $available ? NhlGameSourceStatus::STATUS_AVAILABLE : NhlGameSourceStatus::STATUS_EMPTY,
            $available ? null : 'empty_shiftcharts',
            [
                'data_count' => $count,
                'total' => $total,
            ]
        );
    }

    /**
     * @param array<string, mixed>|null $details
     * @return array<string, mixed>
     */
    private function status(
        int $gameId,
        string $source,
        string $url,
        string $status,
        ?string $reason,
        ?array $details = null
    ): array {
        return [
            'nhl_game_id' => $gameId,
            'source' => $source,
            'status' => $status,
            'reason' => $reason,
            'url' => $url,
            'details' => $details,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $statuses
     * @return array{allowed: bool, core_allowed: bool, on_ice_allowed: bool, statuses: array<int, array<string, mixed>>, message: string|null, core_message: string|null, on_ice_message: string|null}
     */
    private function resultFromStatuses(array $statuses): array
    {
        $blockedCore = array_values(array_filter(
            $statuses,
            fn (array $status): bool => in_array($status['source'], [
                NhlGameSourceStatus::SOURCE_PBP,
                NhlGameSourceStatus::SOURCE_BOXSCORE,
            ], true)
                && $status['status'] !== NhlGameSourceStatus::STATUS_AVAILABLE
        ));
        $blockedOnIce = array_values(array_filter(
            $statuses,
            fn (array $status): bool => $status['source'] === NhlGameSourceStatus::SOURCE_SHIFTS
                && $status['status'] !== NhlGameSourceStatus::STATUS_AVAILABLE
        ));
        $coreAllowed = $blockedCore === [];
        $onIceAllowed = true;
        $blocked = $blockedCore;

        return [
            'allowed' => $coreAllowed,
            'core_allowed' => $coreAllowed,
            'on_ice_allowed' => $onIceAllowed,
            'statuses' => $statuses,
            'message' => $blocked === [] ? null : $this->blockedMessage($blocked),
            'core_message' => $blockedCore === [] ? null : $this->blockedMessage($blockedCore),
            'on_ice_message' => $blockedOnIce === [] ? null : $this->blockedMessage($blockedOnIce),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $blocked
     */
    private function blockedMessage(array $blocked): string
    {
        $sources = array_map(
            fn (array $status): string => sprintf('%s:%s', $status['source'], $status['reason'] ?? $status['status']),
            $blocked
        );

        return 'NHL source preflight blocked import: ' . implode(', ', $sources);
    }
}
