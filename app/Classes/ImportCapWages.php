<?php

declare(strict_types=1);

namespace App\Classes;

use App\Models\Player;
use App\Models\Contract;
use App\Models\ContractSeason;
use App\Traits\HasAPITrait;
use Illuminate\Support\Facades\Log;

/**
 * Class ImportCapWages
 *
 * Fetches a single player’s contract detail from CapWages
 * and upserts both contracts and their seasons into the database,
 * storing a derived `season_key` (e.g. 20152016) and a human‑friendly `label` (e.g. "2015‑16").
 */
class ImportCapWages
{
    use HasAPITrait;

    /**
     * Import contract data (including seasons) for the given player slug.
     */
    public function importBySlug(string $slug): void
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
            return;
        }

        $data  = $response['data'] ?? [];
        $nhlId = $data['nhlId'] ?? null;

        $player = $nhlId
            ? Player::where('nhl_id', $nhlId)->first()
            : null;

        if (! $player) {
            Log::warning("No local Player for NHL ID {$nhlId}", ['slug' => $slug]);
            return;
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
                    $start = (int) $m[1];        // e.g. 2015
                    // Always next calendar year
                    $end   = $start + 1;         // 2016
                    $seasonKey = $start * 10000 + $end; // 2015*10000 + 2016 = 20152016
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
