<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class BackfillLeagueCommissionersCommand extends Command
{
    protected $signature = 'leagues:backfill-commissioners {--include-org-admins : Also assign organization admins as league commissioners.}';

    protected $description = 'Backfill league-scoped commissioner roles for existing community leagues.';

    /**
     * Backfill league commissioner roles from existing community ownership.
     */
    public function handle(): int
    {
        $now = now();
        $rows = DB::table('organization_leagues as ol')
            ->join('organizations as o', 'o.id', '=', 'ol.organization_id')
            ->whereNotNull('o.owner_user_id')
            ->select([
                'ol.league_id',
                'o.owner_user_id as user_id',
            ])
            ->get()
            ->map(static fn (object $row): array => [
                'league_id' => (int) $row->league_id,
                'user_id' => (int) $row->user_id,
                'role' => 'commissioner',
                'permissions' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

        if ($this->option('include-org-admins')) {
            $adminRows = DB::table('organization_leagues as ol')
                ->join('role_user as ru', 'ru.organization_id', '=', 'ol.organization_id')
                ->join('roles as r', 'r.id', '=', 'ru.role_id')
                ->where('r.scope', 'organization')
                ->where('r.slug', 'admin')
                ->where('r.is_active', true)
                ->select([
                    'ol.league_id',
                    'ru.user_id',
                ])
                ->get()
                ->map(static fn (object $row): array => [
                    'league_id' => (int) $row->league_id,
                    'user_id' => (int) $row->user_id,
                    'role' => 'commissioner',
                    'permissions' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

            $rows = $rows->merge($adminRows);
        }

        $rows = $rows
            ->unique(static fn (array $row): string => $row['league_id'] . ':' . $row['user_id'] . ':' . $row['role'])
            ->values();

        if ($rows->isEmpty()) {
            $this->info('No league commissioner roles to backfill.');

            return self::SUCCESS;
        }

        $created = DB::table('league_user_roles')->insertOrIgnore($rows->all());

        $this->info("Backfilled {$created} league commissioner role(s).");

        return self::SUCCESS;
    }
}
