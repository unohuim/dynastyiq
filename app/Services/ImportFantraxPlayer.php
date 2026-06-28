<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\FantraxPlayer;
use App\Models\PlayerExternalIdentity;
use Illuminate\Support\Facades\Log;

/**
 * Resolves a Fantrax entry to a Player and upserts fantrax_players.
 */
class ImportFantraxPlayer
{
    public function __construct(
        private readonly ?PlayerIdentityResolver $identityResolver = null,
    ) {
    }

    /**
     * Process a single Fantrax entry.
     *
     * @param array<string,mixed> $entry
     */
    public function syncOne(array $entry): string
    {
        if (empty($entry['fantraxId'])) {
            Log::warning('[Fantrax] Skipping entry; missing required fields', [
                'fantraxId' => $entry['fantraxId'] ?? null,
                'name'      => $entry['name'] ?? null,
            ]);
            return 'skipped';
        }

        if ($this->isTeamAggregateEntry($entry)) {
            Log::info('[Fantrax] Skipping team aggregate entry', [
                'fantraxId' => $entry['fantraxId'] ?? null,
                'name' => $entry['name'] ?? null,
                'position' => $entry['position'] ?? null,
            ]);
            return 'skipped';
        }

        $existing = FantraxPlayer::where('fantrax_id', $entry['fantraxId'])->first();
        $resolver = $this->identityResolver ?? app(PlayerIdentityResolver::class);
        $identity = $resolver->upsertFantraxIdentity($entry);
        $knownPlayer = $existing?->player;
        $identity = $resolver->resolveNonAuthorityIdentity($identity, $knownPlayer);

        $pid = $identity->match_status === PlayerExternalIdentity::STATUS_MATCHED
            ? $identity->player_id
            : null;

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

        return 'successful';
    }

    /**
     * Determine whether a Fantrax row represents a team total rather than a player.
     *
     * @param array<string,mixed> $entry
     */
    private function isTeamAggregateEntry(array $entry): bool
    {
        $name = mb_strtolower(trim((string) ($entry['name'] ?? '')));
        $position = mb_strtolower(trim((string) ($entry['position'] ?? '')));
        $shortName = mb_strtolower(trim((string) ($entry['shortName'] ?? '')));

        return $name === 'team' || $position === 'tm' || $shortName === 'tm';
    }
}
