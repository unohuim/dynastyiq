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

        $existing = FantraxPlayer::where('fantrax_id', $entry['fantraxId'])->first();

        // Do not reassign if already linked
        $player = null;
        $pid    = $existing?->player_id;

        if ($pid === null) {
            $player = $this->resolvePlayer($entry);
            $pid    = $player?->id;

            if ($pid !== null) {
                $claimed = FantraxPlayer::where('player_id', $pid)
                    ->where('fantrax_id', '!=', $entry['fantraxId'])
                    ->exists();

                if ($claimed) {
                    Log::warning('[Fantrax] Player already linked to another Fantrax record', [
                        'fantraxId' => $entry['fantraxId'],
                        'playerId'  => $pid,
                    ]);
                    $pid = null;
                }
            }
        }

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

        if ($pid === null && (($entry['team'] ?? null) !== '(N/A)')) {
            Log::info('[Fantrax] Upserted FantraxPlayer without link', ['name' => $entry['name'] ?? null]);
        }
    }

    /**
     * Attempt to resolve a Player by fantrax_id and exact full name.
     *
     * @param array<string,mixed> $entry
     * @return Player|null
     */
    private function resolvePlayer(array $entry): ?Player
    {
        // Parse "Last, First"
        [$last, $first] = array_map(
            static fn ($p) => trim((string)$p),
            explode(',', (string)($entry['name'] ?? '')) + [1 => '']
        );

        if ($last === '' || $first === '') {
            return null;
        }

        $fullName = strtolower(trim($first . ' ' . $last));

        return Player::query()
            ->whereRaw('lower(full_name) = ?', [$fullName])
            ->first();
    }
}
