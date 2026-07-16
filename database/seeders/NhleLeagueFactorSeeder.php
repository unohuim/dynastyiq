<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\NhleLeagueFactor;
use Illuminate\Database\Seeder;

/**
 * Seeds versioned NHLe league translation factors.
 */
final class NhleLeagueFactorSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $metadata = [
            'source' => 'nl_ice_data',
            'source_version' => '2026',
            'model_name' => 'Full-network NHLe',
            'model_window' => '2021-22 through 2025-26',
            'source_url' => 'https://thibaudchatel.substack.com/p/nhle-update-for-2026',
        ];

        foreach ($this->factors() as $factor) {
            NhleLeagueFactor::query()->updateOrCreate(
                [
                    'source' => $metadata['source'],
                    'source_version' => $metadata['source_version'],
                    'source_league_name' => $factor['source_league_name'],
                ],
                array_merge($metadata, [
                    'mapped_league_codes' => $this->mappedLeagueCodes($factor),
                    'points_factor' => $factor['points_factor'],
                    'win_shares_factor' => $factor['win_shares_factor'],
                    'notes' => $factor['notes'] ?? null,
                ])
            );
        }
    }

    /**
     * @param array{source_league_name:string,points_factor:string,win_shares_factor:string,notes?:string} $factor
     * @return array<int,string>
     */
    private function mappedLeagueCodes(array $factor): array
    {
        $league = $factor['source_league_name'];
        $aliases = [
            'NL' => ['NLA'],
            'Czech' => ['CZECHIA', 'CZE'],
            'Liiga' => ['FINLAND'],
            'Allsvenskan' => ['HOCKEYALLSVENSKAN'],
            'Hockey East' => ['HE', 'HOCKEYEAST'],
            'Big Ten' => ['B10', 'BIGTEN'],
            'Atlantic Hockey' => ['AHA', 'ATLANTICHOCKEY'],
            'Independent' => ['NCAA', 'NCAA INDEPENDENT'],
            'Magnus' => ['LIGUE MAGNUS'],
            'SL' => ['SWEDEN3'],
            'J20 Nationell' => ['J20', 'J20NATIONELL'],
            'U20 Finland' => ['FINLAND U20'],
            'U20 Swiss' => ['SWISS U20'],
            'U20 Swiss Top' => ['SWISS U20 TOP'],
            'DNL U20' => ['DNL'],
        ];

        return array_values(array_unique(array_merge([$league], $aliases[$league] ?? [])));
    }

    /**
     * Return manually transcribed 2026 NL Ice Data NHLe factors.
     *
     * @return array<int,array{source_league_name:string,points_factor:string,win_shares_factor:string,notes?:string}>
     */
    private function factors(): array
    {
        return [
            ['source_league_name' => 'NHL', 'points_factor' => '1.00', 'win_shares_factor' => '1.00', 'notes' => 'Baseline'],
            ['source_league_name' => 'SHL', 'points_factor' => '0.64', 'win_shares_factor' => '0.51'],
            ['source_league_name' => 'KHL', 'points_factor' => '0.63', 'win_shares_factor' => '0.47'],
            ['source_league_name' => 'AHL', 'points_factor' => '0.56', 'win_shares_factor' => '0.50'],
            ['source_league_name' => 'NL', 'points_factor' => '0.54', 'win_shares_factor' => '0.36', 'notes' => 'Swiss NL'],
            ['source_league_name' => 'Czech', 'points_factor' => '0.45', 'win_shares_factor' => '0.30', 'notes' => 'Czech Extraliga'],
            ['source_league_name' => 'Liiga', 'points_factor' => '0.40', 'win_shares_factor' => '0.32'],
            ['source_league_name' => 'Allsvenskan', 'points_factor' => '0.38', 'win_shares_factor' => '0.25', 'notes' => 'HockeyAllsvenskan'],
            ['source_league_name' => 'VHL', 'points_factor' => '0.37', 'win_shares_factor' => '0.21'],
            ['source_league_name' => 'DEL', 'points_factor' => '0.36', 'win_shares_factor' => '0.32'],
            ['source_league_name' => 'Hockey East', 'points_factor' => '0.34', 'win_shares_factor' => '0.23', 'notes' => 'NCAA division'],
            ['source_league_name' => 'Big Ten', 'points_factor' => '0.29', 'win_shares_factor' => '0.24', 'notes' => 'NCAA division'],
            ['source_league_name' => 'Slovakia', 'points_factor' => '0.29', 'win_shares_factor' => '0.19'],
            ['source_league_name' => 'ICEHL', 'points_factor' => '0.29', 'win_shares_factor' => '0.22'],
            ['source_league_name' => 'NCHC', 'points_factor' => '0.29', 'win_shares_factor' => '0.23', 'notes' => 'NCAA division'],
            ['source_league_name' => 'Belarus', 'points_factor' => '0.26', 'win_shares_factor' => '0.18'],
            ['source_league_name' => 'ECHL', 'points_factor' => '0.26', 'win_shares_factor' => '0.19'],
            ['source_league_name' => 'WCHA', 'points_factor' => '0.25', 'win_shares_factor' => '0.17', 'notes' => 'NCAA division'],
            ['source_league_name' => 'Czech2', 'points_factor' => '0.24', 'win_shares_factor' => '0.14'],
            ['source_league_name' => 'USHL', 'points_factor' => '0.23', 'win_shares_factor' => '0.16'],
            ['source_league_name' => 'CCHA', 'points_factor' => '0.23', 'win_shares_factor' => '0.15', 'notes' => 'NCAA division'],
            ['source_league_name' => 'ECAC', 'points_factor' => '0.22', 'win_shares_factor' => '0.16', 'notes' => 'NCAA division'],
            ['source_league_name' => 'EIHL', 'points_factor' => '0.22', 'win_shares_factor' => '0.16'],
            ['source_league_name' => 'Atlantic Hockey', 'points_factor' => '0.21', 'win_shares_factor' => '0.15', 'notes' => 'NCAA division'],
            ['source_league_name' => 'DEL2', 'points_factor' => '0.21', 'win_shares_factor' => '0.14'],
            ['source_league_name' => 'Independent', 'points_factor' => '0.21', 'win_shares_factor' => '0.14', 'notes' => 'NCAA independents'],
            ['source_league_name' => 'OHL', 'points_factor' => '0.19', 'win_shares_factor' => '0.15'],
            ['source_league_name' => 'Denmark', 'points_factor' => '0.19', 'win_shares_factor' => '0.14'],
            ['source_league_name' => 'Norway', 'points_factor' => '0.19', 'win_shares_factor' => '0.13'],
            ['source_league_name' => 'Mestis', 'points_factor' => '0.18', 'win_shares_factor' => '0.12'],
            ['source_league_name' => 'SL', 'points_factor' => '0.18', 'win_shares_factor' => '0.11'],
            ['source_league_name' => 'Magnus', 'points_factor' => '0.18', 'win_shares_factor' => '0.13', 'notes' => 'France Ligue Magnus'],
            ['source_league_name' => 'Kazakhstan', 'points_factor' => '0.18', 'win_shares_factor' => '0.11'],
            ['source_league_name' => 'SPHL', 'points_factor' => '0.17', 'win_shares_factor' => '0.10'],
            ['source_league_name' => 'Poland', 'points_factor' => '0.17', 'win_shares_factor' => '0.11'],
            ['source_league_name' => 'WHL', 'points_factor' => '0.17', 'win_shares_factor' => '0.12'],
            ['source_league_name' => 'MHL', 'points_factor' => '0.16', 'win_shares_factor' => '0.10'],
            ['source_league_name' => 'HockeyEttan', 'points_factor' => '0.15', 'win_shares_factor' => '0.09'],
            ['source_league_name' => 'Erste Liga', 'points_factor' => '0.15', 'win_shares_factor' => '0.11'],
            ['source_league_name' => 'QMJHL', 'points_factor' => '0.14', 'win_shares_factor' => '0.10'],
            ['source_league_name' => 'USports', 'points_factor' => '0.14', 'win_shares_factor' => '0.09'],
            ['source_league_name' => 'BCHL', 'points_factor' => '0.13', 'win_shares_factor' => '0.10'],
            ['source_league_name' => 'Latvia', 'points_factor' => '0.13', 'win_shares_factor' => '0.09'],
            ['source_league_name' => 'U20 Finland', 'points_factor' => '0.12', 'win_shares_factor' => '0.07'],
            ['source_league_name' => 'J20 Nationell', 'points_factor' => '0.12', 'win_shares_factor' => '0.08'],
            ['source_league_name' => 'AlpsHL', 'points_factor' => '0.11', 'win_shares_factor' => '0.07'],
            ['source_league_name' => 'NAHL', 'points_factor' => '0.10', 'win_shares_factor' => '0.06'],
            ['source_league_name' => 'MyHL', 'points_factor' => '0.10', 'win_shares_factor' => '0.05'],
            ['source_league_name' => 'France2', 'points_factor' => '0.10', 'win_shares_factor' => '0.06'],
            ['source_league_name' => 'Slovakia2', 'points_factor' => '0.10', 'win_shares_factor' => '0.08'],
            ['source_league_name' => 'AJHL', 'points_factor' => '0.10', 'win_shares_factor' => '0.06'],
            ['source_league_name' => 'NCAA III', 'points_factor' => '0.09', 'win_shares_factor' => '0.06'],
            ['source_league_name' => 'Czech U20', 'points_factor' => '0.08', 'win_shares_factor' => '0.05'],
            ['source_league_name' => 'U20 Swiss', 'points_factor' => '0.08', 'win_shares_factor' => '0.04'],
            ['source_league_name' => 'OJHL', 'points_factor' => '0.07', 'win_shares_factor' => '0.04'],
            ['source_league_name' => 'France3', 'points_factor' => '0.05', 'win_shares_factor' => '0.03'],
            ['source_league_name' => 'DNL U20', 'points_factor' => '0.05', 'win_shares_factor' => '0.03'],
            ['source_league_name' => 'Slovakia U20', 'points_factor' => '0.04', 'win_shares_factor' => '0.03'],
            ['source_league_name' => 'U17 Swiss', 'points_factor' => '0.04', 'win_shares_factor' => '0.02'],
            ['source_league_name' => 'France U20', 'points_factor' => '0.04', 'win_shares_factor' => '0.02'],
            ['source_league_name' => 'U20 Swiss Top', 'points_factor' => '0.03', 'win_shares_factor' => '0.01'],
            ['source_league_name' => 'France U18', 'points_factor' => '0.02', 'win_shares_factor' => '0.01'],
        ];
    }
}
