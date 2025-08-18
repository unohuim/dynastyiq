<?php

declare(strict_types=1);

namespace App\Classes;

use App\Models\FantraxPlayer;
use App\Models\Player;
use App\Traits\HasAPITrait;
use Illuminate\Support\Facades\Log;


/**
 * Class ImportFantraxPlayers
 *
 * Fetches Fantrax player metadata and upserts it into
 * the `fantrax_players` table, skipping entries without
 * a matching Player to preserve foreign key integrity.
 *
 * @package App\Classes
 */
class ImportFantraxPlayers
{
    use HasAPITrait;



    /**
     * Fetch and process all Fantrax player entries from the API.
     *
     * Each entry will be matched to an internal Player and
     * upserted into the fantrax_players table.
     *
     * @return void
     */
    public function import(): void
    {
        $entries = $this->getAPIData('fantrax', 'players');

        if (! is_array($entries)) {
            Log::error('[Fantrax] Unexpected response format', ['response' => $entries]);
            return;
        }

        $imported = 0;
        $skipped = 0;
        

        foreach ($entries as $entry) {
            $player = $this->resolvePlayer($entry);

            if ($player === null) {
                $pid = null;
                Log::warning('[Fantrax] Skipping entry; no Player match', ['entry' => $entry]);
                // $skipped++;
                // continue;
            } 
            else {
                $pid = $player->id;
                $player->fantrax_id = $entry['fantraxId'];
                $player->save();
            }

            FantraxPlayer::updateOrCreate(
                ['fantrax_id' => $entry['fantraxId'] ?? ''],
                [
                    'player_id'       => $pid,
                    'statsinc_id'     => $entry['statsIncId']     ?? null,
                    'rotowire_id'     => $entry['rotowireId']     ?? null,
                    'sport_radar_id'  => $entry['sportRadarId']   ?? null,
                    'team'            => $entry['team']           ?? null,
                    'name'            => $entry['name']           ?? null,
                    'position'        => $entry['position']       ?? null,
                    'raw_meta'        => $entry,
                ]
            );

            $imported++;
        }

        Log::info("[Fantrax] Import complete", [
            'imported' => $imported,
            'skipped'  => $skipped,
        ]);
    }



    /**
     * Attempt to resolve a Player by fantrax_id, name, position, and team.
     * Accounts for common first-name variants and falls back to full_name.
     *
     * @param array<string,mixed> $entry API data for one Fantrax player
     *
     * @return Player|null Matching Player model or null
     */
    private function resolvePlayer(array $entry): ?Player
    {
        // 1. Direct fantrax_id lookup
        if (! empty($entry['fantraxId'])) {
            $player = Player::where('fantrax_id', $entry['fantraxId'])->first();
            if ($player !== null) {
                return $player;
            }
        }

        // 2. Parse "Last, First"
        [$last, $first] = array_map(
            static fn (string $part): string => trim($part),
            explode(',', $entry['name'] ?? '') + [1 => '']
        );

        // 3. Determine first-name variants from config
        $variantsMap = config('name_variants.first_name_variants', []);

        $firstLower    = strtolower($first);
        $firstVariants = [$first];

        foreach ($variantsMap as $aliases) {
            if (in_array($firstLower, array_map('strtolower', $aliases), true)) {
                $firstVariants = $aliases;
                break;
            }
        }

        $firstLower    = strtolower($first);
        $firstVariants = [$first];

        foreach ($variantsMap as $canonical => $aliases) {
            if (in_array($firstLower, array_map('strtolower', $aliases), true)) {
                $firstVariants = $aliases;
                break;
            }
        }

        // 4. Lookup by first_name variants & last_name + optional filters
        $query = Player::query()
            ->whereIn('first_name', $firstVariants)
            ->where('last_name', $last);

        if (! empty($entry['position'])) {
            $query->where('position', $entry['position']);
        }

        if (! empty($entry['team'])) {
            $query->where('team_abbrev', $entry['team']);
        }

        $player = $query->first();
        if ($player !== null) {
            return $player;
        }

        // 5. Fallback to full_name ("First Last")
        $fullName = trim("{$first} {$last}");
        $query    = Player::query()->where('full_name', $fullName);

        if (! empty($entry['position'])) {
            $query->where('position', $entry['position']);
        }

        if (! empty($entry['team'])) {
            $query->where('team_abbrev', $entry['team']);
        }

        return $query->first();
    }
}
