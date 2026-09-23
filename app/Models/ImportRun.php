<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Throwable;

class ImportRun extends Model
{
    protected $guarded = [];

    protected $casts = [
        'ran_at' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'duration_seconds' => 'integer',
        'total_records' => 'integer',
        'processed_records' => 'integer',
        'successful_records' => 'integer',
        'failed_records' => 'integer',
        'skipped_records' => 'integer',
        'estimated_cost_usd' => 'decimal:6',
        'options' => 'array',
        'meta' => 'array',
    ];

    public function setProgressTotal(?int $total, ?string $label = null): void
    {
        $this->update([
            'total_records' => $total,
            'progress_label' => $label,
        ]);
    }

    public function incrementProgressTotal(int $amount = 1, ?string $label = null): void
    {
        $updates = [
            'total_records' => DB::raw('COALESCE(total_records, 0) + ' . max(1, $amount)),
        ];

        if ($label !== null) {
            $updates['progress_label'] = $label;
        }

        $this->newQuery()
            ->whereKey($this->getKey())
            ->update($updates);
    }

    public function recordProcessed(string $result = 'successful'): void
    {
        $column = match ($result) {
            'failed' => 'failed_records',
            'skipped' => 'skipped_records',
            default => 'successful_records',
        };

        $this->newQuery()
            ->whereKey($this->getKey())
            ->incrementEach([
                'processed_records' => 1,
                $column => 1,
            ]);
    }

    public function markCompleted(): void
    {
        $finishedAt = now();

        $this->update([
            'status' => 'completed',
            'ran_at' => $finishedAt,
            'finished_at' => $finishedAt,
            'duration_seconds' => $this->durationSeconds($finishedAt),
        ]);
    }

    /**
     * Preserve targeted lineup review feedback alongside usage metadata atomically.
     *
     * @param array<string,mixed>|null $post
     */
    public function recordLineupReview(?string $activity = null, ?array $post = null): void
    {
        DB::transaction(function () use ($activity, $post): void {
            $run = self::query()->whereKey($this->getKey())->lockForUpdate()->first();
            if ($run === null || $run->source !== 'nhl-anticipated-lineups' || ! ($run->options['targeted_refresh'] ?? false)) {
                return;
            }
            $meta = $run->meta ?? [];
            $review = $meta['lineup_review'] ?? ['activity' => 'Queued for lineup search…', 'posts' => []];
            if ($activity !== null) {
                $review['activity'] = $activity;
            }
            if ($post !== null) {
                $posts = collect($review['posts'])->keyBy('id');
                $posts->put((string) $post['id'], $post);
                $review['posts'] = $posts->values()->all();
            }
            $meta['lineup_review'] = $review;
            $run->update(['meta' => $meta]);
        });
    }

    public function markFailed(Throwable|string $error): void
    {
        $finishedAt = now();

        $this->update([
            'status' => 'failed',
            'ran_at' => $finishedAt,
            'finished_at' => $finishedAt,
            'duration_seconds' => $this->durationSeconds($finishedAt),
            'error_message' => $error instanceof Throwable ? $error->getMessage() : $error,
        ]);
    }

    private function durationSeconds(mixed $finishedAt): int
    {
        $startedAt = $this->started_at ?? $this->created_at ?? $finishedAt;
        $start = (float) $startedAt->format('U.u');
        $finish = (float) $finishedAt->format('U.u');

        return max(1, (int) ceil($finish - $start));
    }
}
