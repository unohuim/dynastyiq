<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Dedupe imported NHL play-by-play rows and enforce the provider natural key.
     */
    public function up(): void
    {
        DB::transaction(function (): void {
            $this->createRepairLedger();
            $this->buildDuplicateMap();
            $this->recordAffectedGames();
            $this->repointEventUnitLinks();
            $this->repointPbpSourceMismatches();
            $this->repointShotAttemptFactContext();
            $this->deleteDuplicateShotAttemptFacts();
            $this->deleteDuplicatePlayByPlays();
        });

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX IF NOT EXISTS play_by_plays_nhl_game_event_unique
            ON play_by_plays (nhl_game_id, nhl_event_id)
            WHERE nhl_event_id IS NOT NULL
        SQL);
    }

    /**
     * Remove the play-by-play natural-key constraint.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS play_by_plays_nhl_game_event_unique');
        DB::statement('DROP TABLE IF EXISTS nhl_play_by_play_dedupe_repairs');
    }

    /**
     * Create a persistent ledger of games affected by PBP dedupe.
     */
    private function createRepairLedger(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS nhl_play_by_play_dedupe_repairs (
                id BIGSERIAL PRIMARY KEY,
                nhl_game_id BIGINT NOT NULL UNIQUE,
                duplicate_rows_deleted INTEGER NOT NULL DEFAULT 0,
                rebuild_queued_at TIMESTAMP(0) WITHOUT TIME ZONE NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NULL
            )
        SQL);
    }

    /**
     * Build a temporary duplicate-to-canonical PBP row map.
     */
    private function buildDuplicateMap(): void
    {
        DB::statement(<<<'SQL'
            CREATE TEMPORARY TABLE duplicate_play_by_play_rows ON COMMIT DROP AS
            SELECT duplicate_rows.id AS duplicate_id,
                   canonical_rows.keep_id
            FROM play_by_plays duplicate_rows
            INNER JOIN (
                SELECT nhl_game_id,
                       nhl_event_id,
                       MIN(id) AS keep_id
                FROM play_by_plays
                WHERE nhl_event_id IS NOT NULL
                GROUP BY nhl_game_id, nhl_event_id
                HAVING COUNT(*) > 1
            ) canonical_rows
                ON canonical_rows.nhl_game_id = duplicate_rows.nhl_game_id
               AND canonical_rows.nhl_event_id = duplicate_rows.nhl_event_id
            WHERE duplicate_rows.id <> canonical_rows.keep_id
        SQL);

        DB::statement('CREATE INDEX duplicate_play_by_play_rows_duplicate_id_idx ON duplicate_play_by_play_rows (duplicate_id)');
        DB::statement('CREATE INDEX duplicate_play_by_play_rows_keep_id_idx ON duplicate_play_by_play_rows (keep_id)');
    }

    /**
     * Persist affected game ids for post-migration rebuilds.
     */
    private function recordAffectedGames(): void
    {
        DB::statement(<<<'SQL'
            INSERT INTO nhl_play_by_play_dedupe_repairs (
                nhl_game_id,
                duplicate_rows_deleted,
                created_at,
                updated_at
            )
            SELECT play_by_plays.nhl_game_id,
                   COUNT(*)::INTEGER AS duplicate_rows_deleted,
                   CURRENT_TIMESTAMP(0),
                   CURRENT_TIMESTAMP(0)
            FROM duplicate_play_by_play_rows duplicate_rows
            INNER JOIN play_by_plays
                ON play_by_plays.id = duplicate_rows.duplicate_id
            GROUP BY play_by_plays.nhl_game_id
            ON CONFLICT (nhl_game_id) DO UPDATE
            SET duplicate_rows_deleted = EXCLUDED.duplicate_rows_deleted,
                updated_at = CURRENT_TIMESTAMP(0)
        SQL);
    }

    /**
     * Repoint event-to-unit links without violating the existing unique pair.
     */
    private function repointEventUnitLinks(): void
    {
        DB::statement(<<<'SQL'
            DELETE FROM event_unit_shifts duplicate_links
            USING duplicate_play_by_play_rows duplicate_rows
            WHERE duplicate_links.event_id = duplicate_rows.duplicate_id
              AND EXISTS (
                  SELECT 1
                  FROM event_unit_shifts existing_links
                  WHERE existing_links.event_id = duplicate_rows.keep_id
                    AND existing_links.unit_shift_id = duplicate_links.unit_shift_id
                  )
        SQL);

        DB::statement(<<<'SQL'
            DELETE FROM event_unit_shifts duplicate_links
            USING duplicate_play_by_play_rows duplicate_rows
            WHERE duplicate_links.event_id = duplicate_rows.duplicate_id
              AND EXISTS (
                  SELECT 1
                  FROM event_unit_shifts peer_links
                  INNER JOIN duplicate_play_by_play_rows peer_duplicate_rows
                      ON peer_duplicate_rows.duplicate_id = peer_links.event_id
                  WHERE peer_duplicate_rows.keep_id = duplicate_rows.keep_id
                    AND peer_links.unit_shift_id = duplicate_links.unit_shift_id
                    AND peer_links.id < duplicate_links.id
              )
        SQL);

        DB::statement(<<<'SQL'
            UPDATE event_unit_shifts links
            SET event_id = duplicate_rows.keep_id
            FROM duplicate_play_by_play_rows duplicate_rows
            WHERE links.event_id = duplicate_rows.duplicate_id
        SQL);
    }

    /**
     * Repoint HTML/API mismatch rows to canonical PBP rows.
     */
    private function repointPbpSourceMismatches(): void
    {
        DB::statement(<<<'SQL'
            UPDATE nhl_pbp_source_mismatches mismatches
            SET play_by_play_id = duplicate_rows.keep_id
            FROM duplicate_play_by_play_rows duplicate_rows
            WHERE mismatches.play_by_play_id = duplicate_rows.duplicate_id
        SQL);
    }

    /**
     * Repoint contextual shot-attempt references to canonical PBP rows.
     */
    private function repointShotAttemptFactContext(): void
    {
        if (! $this->tableExists('nhl_shot_attempts_facts')) {
            return;
        }

        DB::statement(<<<'SQL'
            UPDATE nhl_shot_attempts_facts facts
            SET previous_play_by_play_id = duplicate_rows.keep_id
            FROM duplicate_play_by_play_rows duplicate_rows
            WHERE facts.previous_play_by_play_id = duplicate_rows.duplicate_id
        SQL);

        if ($this->columnExists('nhl_shot_attempts_facts', 'rush_sequence_origin_play_by_play_id')) {
            DB::statement(<<<'SQL'
                UPDATE nhl_shot_attempts_facts facts
                SET rush_sequence_origin_play_by_play_id = duplicate_rows.keep_id
                FROM duplicate_play_by_play_rows duplicate_rows
                WHERE facts.rush_sequence_origin_play_by_play_id = duplicate_rows.duplicate_id
            SQL);
        }
    }

    /**
     * Delete shot-attempt facts sourced from duplicate PBP rows before deleting those rows.
     */
    private function deleteDuplicateShotAttemptFacts(): void
    {
        if (! $this->tableExists('nhl_shot_attempts_facts')) {
            return;
        }

        DB::statement(<<<'SQL'
            DELETE FROM nhl_shot_attempts_facts facts
            USING duplicate_play_by_play_rows duplicate_rows
            WHERE facts.play_by_play_id = duplicate_rows.duplicate_id
        SQL);
    }

    /**
     * Delete duplicate PBP rows after child references are safe.
     */
    private function deleteDuplicatePlayByPlays(): void
    {
        DB::statement(<<<'SQL'
            DELETE FROM play_by_plays duplicate_rows
            USING duplicate_play_by_play_rows mapped_rows
            WHERE duplicate_rows.id = mapped_rows.duplicate_id
        SQL);
    }

    /**
     * Determine whether a table exists in the current connection.
     */
    private function tableExists(string $table): bool
    {
        return (bool) DB::table('information_schema.tables')
            ->where('table_schema', 'public')
            ->where('table_name', $table)
            ->exists();
    }

    /**
     * Determine whether a column exists in the current connection.
     */
    private function columnExists(string $table, string $column): bool
    {
        return (bool) DB::table('information_schema.columns')
            ->where('table_schema', 'public')
            ->where('table_name', $table)
            ->where('column_name', $column)
            ->exists();
    }
};
