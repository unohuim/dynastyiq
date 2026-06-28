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
