<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PlayerExternalIdentity;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class EmptyFantraxCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'fx:empty';

    /**
     * @var string
     */
    protected $description = 'Remove Fantrax-owned imported player data without deleting canonical players or league connections.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $counts = DB::transaction(function (): array {
            $counts = [
                'fantrax_players' => DB::table('fantrax_players')->count(),
                'player_external_identities' => DB::table('player_external_identities')
                    ->where('provider', PlayerExternalIdentity::PROVIDER_FANTRAX)
                    ->count(),
            ];

            DB::table('fantrax_players')->delete();
            DB::table('player_external_identities')
                ->where('provider', PlayerExternalIdentity::PROVIDER_FANTRAX)
                ->delete();

            return $counts;
        });

        $this->info('Removed Fantrax imported player data.');
        $this->line("fantrax_players: {$counts['fantrax_players']}");
        $this->line("player_external_identities: {$counts['player_external_identities']}");
        $this->line('Canonical players and Fantrax league connections were not deleted.');

        return self::SUCCESS;
    }
}
