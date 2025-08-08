<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Player;
use App\Models\Contract;
use App\Traits\HasAPITrait;
use Illuminate\Support\Facades\Log;
use App\Exceptions\PlayerNotFoundException;
use App\Exceptions\CapWagesPlayerNotFoundException;
use App\Jobs\ImportNHLPlayerJob;


/**
 * Class ImportCapWagesPlayer
 *
 * Handles import of a single player's contract details from CapWages API.
 */
class ImportCapWagesPlayer
{
    use HasAPITrait;

    /**
     * Import contract data (including seasons) for a given NHL player ID.
     */
    public function syncBySlug(string $slug): void
    {
        try {
            $response = $this->getAPIData(
                'capwages',
                'player_detail',
                ['slug' => $slug]
            );
        } catch (\Exception $e) {
            Log::error("CapWages import failed for slug {$slug}", [
                'error' => $e->getMessage(),
            ]);
            throw new CapWagesPlayerNotFoundException("Player with slug {$slug} not found at CapWages.");
        }

        $data = $response['data'] ?? [];
        $nhlId = $data['nhlId'] ?? null;

        if(! $nhlId) {
            Log::warning("Cap wages player-slug {$slug} does not contain nhl_id");
            return;
        }

        $player = $nhlId
            ? Player::where('nhl_id', $nhlId)->first()
            : null;

        if (! $player) {
            Log::warning("No db Player for NHL ID {$nhlId}", ['slug' => $slug]);

            ImportNHLPlayerJob::dispatch($nhlId);
            throw new PlayerNotFoundException("from service: Player with NHL ID {$nhlId} not found in DB.");
        }

        foreach ($data['contracts'] ?? [] as $entry) {
            $contract = Contract::updateOrCreate(
                [
                    'player_id'     => $player->id,
                    'signing_date'  => $entry['signingDate'],
                    'contract_type' => $entry['contractType'],
                ],
                [
                    'contract_length' => $entry['contractLength'] ?? null,
                    'contract_value'  => $entry['contractValue']  ?? null,
                    'expiry_status'   => $entry['expiryStatus']   ?? null,
                    'signing_team'    => $entry['signingTeam']    ?? null,
                    'signed_by'       => $entry['signedBy']       ?? null,
                ]
            );

            foreach ($entry['seasons'] ?? [] as $seasonData) {
                $rawSeason = (string) ($seasonData['season'] ?? '');
                $label     = $rawSeason;
                $seasonKey = 0;

                if (preg_match('/^(\d{4})-(\d{2})$/', $rawSeason, $m)) {
                    $start = (int) $m[1];
                    $end   = $start + 1;
                    $seasonKey = $start * 10000 + $end;
                }

                $contract->seasons()->updateOrCreate(
                    ['season_key' => $seasonKey],
                    [
                        'label'               => $label,
                        'clause'              => $seasonData['clause']              ?? null,
                        'cap_hit'             => $seasonData['capHit']              ?? null,
                        'aav'                 => $seasonData['aav']                 ?? null,
                        'performance_bonuses' => $seasonData['performanceBonuses'] ?? 0,
                        'signing_bonuses'     => $seasonData['signingBonuses']     ?? 0,
                        'base_salary'         => $seasonData['baseSalary']         ?? null,
                        'total_salary'        => $seasonData['totalSalary']        ?? null,
                        'minors_salary'       => $seasonData['minorsSalary']       ?? null,
                    ]
                );
            }
        }
    }

}
