<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PlayerExternalIdentity;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class EmptyYahooCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'yahoo:empty';

    /**
     * @var string
     */
    protected $description = 'Remove Yahoo-owned imported player data without deleting canonical players or OAuth connections.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $counts = DB::transaction(function (): array {
            $counts = [
                'yahoo_players' => DB::table('yahoo_players')->count(),
                'player_external_identities' => DB::table('player_external_identities')
                    ->where('provider', PlayerExternalIdentity::PROVIDER_YAHOO)
                    ->count(),
            ];

            DB::table('yahoo_players')->delete();
            DB::table('player_external_identities')
                ->where('provider', PlayerExternalIdentity::PROVIDER_YAHOO)
                ->delete();

            return $counts;
        });

        $this->info('Removed Yahoo imported player data.');
        $this->line("yahoo_players: {$counts['yahoo_players']}");
        $this->line("player_external_identities: {$counts['player_external_identities']}");
        $this->line('Canonical players and Yahoo OAuth connections were not deleted.');

        return self::SUCCESS;
    }
}
