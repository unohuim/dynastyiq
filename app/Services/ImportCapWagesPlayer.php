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
 * Handles import of a single player's contract details from CapWages API.
 */
class ImportCapWagesPlayer
{
    use HasAPITrait;

    /**
     * Import contract data (including seasons) for a given player slug.
     */
    public function syncBySlug(string $slug): void
    {
        try {
            $response = $this->getAPIData('capwages', 'player_detail', ['slug' => $slug]);
        } catch (\Exception $e) {
            Log::error("CapWages import failed for slug {$slug}", ['error' => $e->getMessage()]);
            throw new CapWagesPlayerNotFoundException("Player with slug {$slug} not found at CapWages.");
        }

        $data  = $response['data'] ?? [];
        $nhlId = $data['nhlId'] ?? null;

        if (! $nhlId) {
            Log::warning("CapWages player slug {$slug} missing nhl_id");
            return;
        }

        $player = Player::where('nhl_id', $nhlId)->first();

        if (! $player) {
            Log::warning("No local Player for NHL ID {$nhlId}", ['slug' => $slug]);
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

                $parsed = $this->parseSeason($rawSeason);
                if ($parsed === null) {
                    Log::warning('Skipping season row; unparseable season string', [
                        'slug'      => $slug,
                        'nhl_id'    => $nhlId,
                        'rawSeason' => $rawSeason,
                    ]);
                    continue; // never insert season_key=0
                }

                [$seasonKey, $label, $suffix] = $parsed;

                $contract->seasons()->updateOrCreate(
                    ['season_key' => $seasonKey],
                    [
                        // label normalized to "YYYY-YY" (ASCII hyphen; <= 7 chars)
                        'label'               => $label,
                        // combine API clause with parsed suffix (e.g., "LY")
                        'clause'              => $this->mergeClause($seasonData['clause'] ?? null, $suffix),
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

    /**
     * Parse season strings like "2028–29 LY", "2015-16", "2015-2016".
     * Returns [seasonKey(int), label("YYYY-YY"), suffix|null] or null if unparseable.
     */
    private function parseSeason(string $raw): ?array
    {
        $s = trim($raw);
        if ($s === '') {
            return null;
        }

        // Normalize dashes to ASCII hyphen
        $sNorm = str_replace(["\u{2013}", "\u{2014}", "\u{2212}", "\u{2010}", '–', '—', '−', '-'], '-', $s);

        // Capture "YYYY-YY" or "YYYY-YYYY" at the start, allow trailing text (e.g., " LY", "(retained)")
        if (!preg_match('/^\s*(\d{4})\s*-\s*(\d{2}|\d{4})\b(.*)$/u', $sNorm, $m)) {
            return null;
        }

        $startYear = (int) $m[1];
        $endPart   = $m[2];
        $trailing  = trim($m[3]);

        // End year: if 2 digits, assume start+1; if 4 digits, trust it.
        $endYear = (strlen($endPart) === 2) ? ($startYear + 1) : (int) $endPart;

        // Canonical label "YYYY-YY" (exactly 7 chars)
        $label = sprintf('%04d-%02d', $startYear, $endYear % 100);

        // Derive suffix from trailing text, stripping wrappers
        $suffix = trim($trailing, " \t\n\r\0\x0B()[]");
        if ($suffix === '') {
            $suffix = null;
        }

        $seasonKey = ($startYear * 10000) + $endYear;

        return [$seasonKey, $label, $suffix];
    }

    /**
     * Merge API-provided clause with parsed suffix, truncating to fit varchar(255).
     */
    private function mergeClause(?string $apiClause, ?string $suffix): ?string
    {
        $parts = array_values(array_filter([trim((string) $apiClause) ?: null, $suffix], fn ($v) => $v !== null));
        if (empty($parts)) {
            return null;
        }
        $merged = implode(' | ', $parts);
        return mb_substr($merged, 0, 255);
    }
}
