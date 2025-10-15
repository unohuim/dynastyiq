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

        if ($player === null && (($entry['team'] ?? null) !== '(N/A)')) {
            Log::info('[Fantrax] Upserted FantraxPlayer without link', ['name' => $entry['name'] ?? null]);
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
        // Parse "Last, First"
        [$last, $first] = array_map(
            static fn ($p) => trim((string)$p),
            explode(',', (string)($entry['name'] ?? '')) + [1 => '']
        );
        if ($last === '' || $first === '') return null;

        $team          = $this->normalizeTeam($entry['team'] ?? null);
        $posInitial    = $this->normalizePos($entry['position'] ?? null); // L/C/R/D/G (single letter)
        $firstVariants = $this->firstNameVariants($first);

        // Helpers
        $pick = static fn($qb) => (clone $qb)->limit(2)->pluck('id')->count();
        $one  = static fn($qb) => (($id = (clone $qb)->limit(1)->value('id')) ? Player::find($id) : null);

        // 0) Exact First + Last (fast path)
        $q0 = Player::query()
            ->whereRaw('lower(first_name) = lower(?)', [$first])
            ->whereRaw('lower(last_name)  = lower(?)', [$last]);
        $c = $pick($q0);
        if ($c === 1) return $one($q0);
        // If 0 or >1, continue with layered narrowing.

        // 1) Last name only
        $q = Player::query()->whereRaw('lower(last_name) = lower(?)', [$last]);
        $c = $pick($q);
        if ($c === 1) return $one($q);
        if ($c === 0) return null;

        // 2) Add first-name variants
        $q = (clone $q)->whereIn('first_name', $firstVariants);
        $c = $pick($q);
        if ($c === 1) return $one($q);
        if ($c === 0) return null;

        // 3) Add team (still keeping last + first variants)
        if ($team) {
            $q = (clone $q)->whereRaw('upper(team_abbrev) = upper(?)', [$team]);
            $c = $pick($q);
            if ($c === 1) return $one($q);
            if ($c === 0) return null;
        }

        // 4) Add position **first letter** only (keeps all prior constraints)
        if ($posInitial) {
            // left(position,1) works on Postgres & MySQL
            $q = (clone $q)->whereRaw('upper(left(position,1)) = ?', [$posInitial]);
            $c = $pick($q);
            if ($c === 1) return $one($q);
            if ($c === 0) return null;
        }

        // Still ambiguous
        return null;
    }

    private function normalizeTeam(?string $team): ?string
    {
        $t = strtoupper(trim((string)$team));
        return ($t === '' || $t === '(N/A)') ? null : $t;
    }

    private function normalizePos(?string $pos): ?string
    {
        $p = strtoupper(trim((string)$pos));
        if ($p === '') return null;
        // Map LW→L, RW→R, others → first character
        if ($p === 'LW') return 'L';
        if ($p === 'RW') return 'R';
        return mb_substr($p, 0, 1, 'UTF-8'); // C/D/G handled here
    }

    private function firstNameVariants(string $first): array
    {
        $map = config('name_variants.first_name_variants', []);
        $needle = strtolower($first);
        foreach ($map as $aliases) {
            $aliases = (array)$aliases;
            if (in_array($needle, array_map('strtolower', $aliases), true)) {
                return array_values(array_unique($aliases));
            }
        }
        return [$first];
    }




}
