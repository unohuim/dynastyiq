<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $this->updateImportTypeConstraint([
            'pbp',
            'summary',
            'shifts',
            'boxscore',
            'validate-summary',
            'shift-units',
            'connect-events',
            'html-pbp-verify',
            'sum-game-units',
        ]);

        $this->backfillHtmlPbpVerifyProgressRows();

        Schema::table('nhl_game_validations', function (Blueprint $table): void {
            $table->string('resolution', 64)->nullable()->after('approved_by');
            $table->text('resolution_note')->nullable()->after('resolution');
        });

        Schema::create('nhl_pbp_source_mismatches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('validation_id')->constrained('nhl_game_validations')->cascadeOnDelete();
            $table->foreignId('play_by_play_id')->nullable()->constrained('play_by_plays')->nullOnDelete();
            $table->string('nhl_event_id')->nullable()->index();
            $table->string('mismatch_type', 64)->index();
            $table->string('severity', 16)->index();
            $table->unsignedTinyInteger('period')->nullable();
            $table->string('time_in_period', 16)->nullable();
            $table->string('source_url', 500)->nullable();
            $table->json('api_event')->nullable();
            $table->json('html_event')->nullable();
            $table->timestamps();

            $table->index(['validation_id', 'severity']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('nhl_pbp_source_mismatches');

        Schema::table('nhl_game_validations', function (Blueprint $table): void {
            $table->dropColumn(['resolution', 'resolution_note']);
        });

        DB::table('nhl_import_progress')
            ->where('import_type', 'html-pbp-verify')
            ->delete();

        $this->updateImportTypeConstraint([
            'pbp',
            'summary',
            'shifts',
            'boxscore',
            'validate-summary',
            'shift-units',
            'connect-events',
            'sum-game-units',
        ]);
    }

    /**
     * Update database-specific import type constraints.
     *
     * @param array<int,string> $values
     */
    private function updateImportTypeConstraint(array $values): void
    {
        $driver = DB::getDriverName();
        $quoted = collect($values)->map(fn (string $value): string => "'{$value}'")->implode(', ');

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE nhl_import_progress DROP CONSTRAINT IF EXISTS nhl_import_progress_import_type_check');
            DB::statement(
                "ALTER TABLE nhl_import_progress ADD CONSTRAINT nhl_import_progress_import_type_check CHECK (
                    import_type IN ({$quoted})
                )"
            );
        }

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE nhl_import_progress MODIFY import_type ENUM({$quoted}) NOT NULL");
        }
    }

    /**
     * Seed the new stage for existing progress rows without reopening completed downstream work.
     */
    private function backfillHtmlPbpVerifyProgressRows(): void
    {
        DB::statement(
            "INSERT INTO nhl_import_progress (
                run_id,
                season_id,
                game_date,
                game_id,
                game_type,
                import_type,
                items_count,
                status,
                discovered_at,
                created_at,
                updated_at
            )
            SELECT
                progress.run_id,
                progress.season_id,
                progress.game_date,
                progress.game_id,
                progress.game_type,
                'html-pbp-verify',
                0,
                CASE
                    WHEN EXISTS (
                        SELECT 1
                        FROM nhl_import_progress downstream
                        WHERE downstream.game_id = progress.game_id
                            AND (
                                downstream.run_id = progress.run_id
                                OR (downstream.run_id IS NULL AND progress.run_id IS NULL)
                            )
                            AND downstream.import_type IN ('sum-game-units', 'validate-summary')
                            AND downstream.status IN ('completed', 'skipped', 'error')
                    ) THEN 'skipped'
                    ELSE 'scheduled'
                END,
                progress.discovered_at,
                CURRENT_TIMESTAMP,
                CURRENT_TIMESTAMP
            FROM nhl_import_progress progress
            WHERE progress.import_type = 'connect-events'
                AND NOT EXISTS (
                    SELECT 1
                    FROM nhl_import_progress existing
                    WHERE existing.game_id = progress.game_id
                        AND (
                            existing.run_id = progress.run_id
                            OR (existing.run_id IS NULL AND progress.run_id IS NULL)
                        )
                        AND existing.import_type = 'html-pbp-verify'
                )"
        );
    }
};
