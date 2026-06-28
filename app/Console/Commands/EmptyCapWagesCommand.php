<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\NhlPlayerTransaction;
use App\Models\PlayerExternalIdentity;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class EmptyCapWagesCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'cap:empty';

    /**
     * @var string
     */
    protected $description = 'Remove CapWages-owned imported data without deleting canonical players.';

    public function handle(): int
    {
        $counts = DB::transaction(function (): array {
            $counts = [
                'capwages_players' => DB::table('capwages_players')->count(),
                'nhl_player_transactions' => DB::table('nhl_player_transactions')
                    ->where('source', NhlPlayerTransaction::SOURCE_CAPWAGES)
                    ->count(),
                'player_external_identities' => DB::table('player_external_identities')
                    ->where('provider', PlayerExternalIdentity::PROVIDER_CAPWAGES)
                    ->count(),
            ];

            DB::table('capwages_players')->delete();
            DB::table('nhl_player_transactions')
                ->where('source', NhlPlayerTransaction::SOURCE_CAPWAGES)
                ->delete();
            DB::table('player_external_identities')
                ->where('provider', PlayerExternalIdentity::PROVIDER_CAPWAGES)
                ->delete();

            return $counts;
        });

        $this->info('Removed CapWages imported data.');
        $this->line("capwages_players: {$counts['capwages_players']}");
        $this->line("nhl_player_transactions: {$counts['nhl_player_transactions']}");
        $this->line("player_external_identities: {$counts['player_external_identities']}");
        $this->line('Canonical players and contracts were not deleted.');

        return self::SUCCESS;
    }
}
