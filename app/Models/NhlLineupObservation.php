<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/** Immutable public-source observation of an anticipated NHL lineup. */
class NhlLineupObservation extends Model
{
    /** @var array<int,string> */
    protected $guarded = [];

    /** @var array<string,string> */
    protected $casts = [
        'nhl_game_id' => 'integer',
        'team_id' => 'integer',
        'like_count' => 'integer',
        'reply_count' => 'integer',
        'repost_count' => 'integer',
        'view_count' => 'integer',
        'provider_published_at' => 'datetime',
        'observed_at' => 'datetime',
        'raw_evidence' => 'array',
    ];

    public function source(): BelongsTo
    {
        return $this->belongsTo(EvidenceSource::class, 'source_id');
    }

    public function players(): HasMany
    {
        return $this->hasMany(NhlLineupObservationPlayer::class);
    }

    public static function evidenceCutoff(string|Carbon $gameDate): Carbon
    {
        return Carbon::parse($gameDate, 'America/Toronto')->startOfDay()->subDay()->utc();
    }

    public function isEligibleForGameDate(string|Carbon $gameDate): bool
    {
        $timestamp = $this->provider_published_at ?? $this->observed_at;

        if ($timestamp === null || $timestamp->lt(self::evidenceCutoff($gameDate))) {
            return false;
        }
        if (data_get($this->raw_evidence, 'manual_override', false)
            || data_get($this->raw_evidence, 'official', false)) {
            return true;
        }

        return self::relativeDateMatches(
            (string) $this->post_text . "\n" . (string) data_get($this->raw_evidence, 'lineup_text', ''),
            $this->provider_published_at,
            $gameDate
        );
    }

    /** Anchor relative wording to publication, never to the time of ingestion. */
    public static function relativeDateMatches(string $text, ?Carbon $publishedAt, string|Carbon $gameDate): bool
    {
        preg_match_all('/\b(tonight|today|tomorrow)\b/iu', $text, $matches);
        if ($matches[1] === []) {
            return true;
        }
        if ($publishedAt === null) {
            return false;
        }
        $target = Carbon::parse($gameDate)->toDateString();
        foreach (array_unique(array_map('strtolower', $matches[1])) as $word) {
            $date = $publishedAt->copy()->setTimezone('America/Toronto')->startOfDay();
            if ($word === 'tomorrow') {
                $date->addDay();
            }
            if ($date->toDateString() !== $target) {
                return false;
            }
        }

        return true;
    }
}
