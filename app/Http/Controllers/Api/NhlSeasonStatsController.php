<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\NhlSeasonStatsPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Returns gner8-facing NHL season stats keyed by external identifiers.
 */
class NhlSeasonStatsController extends Controller
{
    /**
     * Return NHL season-level stats for gner8 ingestion.
     */
    public function __invoke(Request $request, NhlSeasonStatsPayload $payload): JsonResponse
    {
        $input = $request->validate([
            'season' => ['nullable', 'digits:8'],
            'game_type' => ['nullable', 'integer', 'min:1', 'max:4'],
            'stat_group' => ['nullable', 'string', Rule::in(['basic', 'on_ice', 'expected'])],
            'window_key' => ['nullable', 'string', Rule::in(['season', 'last_5', 'last_10', 'last_20'])],
        ]);

        return response()->json($payload->build(
            seasonKey: (string) ($input['season'] ?? $this->currentSeasonKey()),
            gameType: (int) ($input['game_type'] ?? 2),
            statGroup: $input['stat_group'] ?? null,
            windowKey: $input['window_key'] ?? null,
        ));
    }

    private function currentSeasonKey(): string
    {
        $now = now();
        $startYear = (int) $now->month >= 7 ? (int) $now->year : (int) $now->year - 1;

        return (string) (($startYear * 10000) + $startYear + 1);
    }
}
