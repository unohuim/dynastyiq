<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\FantraxPlayer;
use App\Models\Player;
use Illuminate\Support\Facades\Log;

/**
 * Resolves a Fantrax entry to a Player and upserts fantrax_players.
 */
class ImportFantraxPlayer
{
    /**
     * Process a single Fantrax entry.
     *
     * @param array<string,mixed> $entry
     */
    public function syncOne(array $entry): void
    {
        // Minimal validation
        if (empty($entry['fantraxId']) || empty($entry['name'])) {
            Log::warning('[Fantrax] Skipping entry; missing required fields', [
                'fantraxId' => $entry['fantraxId'] ?? null,
                'name'      => $entry['name'] ?? null,
            ]);
            return;
        }

        $player = $this->resolvePlayer($entry);
        $pid    = $player?->id;

        FantraxPlayer::updateOrCreate(
            ['fantrax_id' => $entry['fantraxId']],
            [
                'player_id'      => $pid,
                'statsinc_id'    => $entry['statsIncId']   ?? null,
                'rotowire_id'    => $entry['rotowireId']   ?? null,
                'sport_radar_id' => $entry['sportRadarId'] ?? null,
                'team'           => $entry['team']         ?? null,
                'name'           => $entry['name']         ?? null,
                'position'       => $entry['position']     ?? null,
                'raw_meta'       => $entry,
            ]
        );

        if ($player === null && $entry['team'] != "(N/A)") {
            Log::info('[Fantrax] Upserted FantraxPlayer without link', ['name'=> $entry['name']]);
        }
    }

    /**
     * Attempt to resolve a Player by fantrax_id, name, position, and team.
     *
     * @param array<string,mixed> $entry
     * @return Player|null
     */
    private function resolvePlayer(array $entry): ?Player
    {
        // 2) Parse "Last, First"
        [$last, $first] = array_map(
            static fn (string $part): string => trim($part),
            explode(',', (string)($entry['name'] ?? '')) + [1 => '']
        );

        // 3) First-name variants from config
        $variantsMap   = config('name_variants.first_name_variants', []);
        $firstLower    = strtolower($first);
        $firstVariants = [$first];

        foreach ($variantsMap as $aliases) {
            if (in_array($firstLower, array_map('strtolower', (array)$aliases), true)) {
                $firstVariants = (array)$aliases;
                break;
            }
        }

        // 4) Lookup by first/last (+ optional filters)
        $query = Player::query()
            ->whereIn('first_name', $firstVariants)
            ->where('last_name', $last);

        if (!empty($entry['position'])) {
            $first = mb_substr((string)$entry['position'], 0, 1, 'UTF-8');
            $query->whereRaw('substr(position, 1, 1) = ?', [$first]);
        }

        if (!empty($entry['team'])) {
            $query->where('team_abbrev', $entry['team']);
        }

        $player = $query->first();
        if ($player) {
            return $player;
        }

        // 5) Fallback to full_name
        $fullName = trim("{$first} {$last}");
        $query    = Player::query()->where('full_name', $fullName);

        if (!empty($entry['position'])) {
            $query->where('position', $entry['position']);
        }
        if (!empty($entry['team'])) {
            $query->where('team_abbrev', $entry['team']);
        }

        return $query->first();
    }
}
