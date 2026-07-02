<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Normalizes NHL play-by-play event semantics shared by summaries and imports.
 */
class NhlPbpEventNormalizer
{
    public function isShootout(mixed $event): bool
    {
        return ($event->period_type ?? null) === 'SO';
    }

    public function isBoxscoreComparable(mixed $event): bool
    {
        return ! $this->isShootout($event);
    }

    public function isShotOnGoal(mixed $event): bool
    {
        if (! $this->isBoxscoreComparable($event)) {
            return false;
        }

        if (($event->type_desc_key ?? null) === 'shot-on-goal') {
            return true;
        }

        return ($event->type_desc_key ?? null) === 'goal' && $this->goalCountsAsShotOnGoal($event);
    }

    public function isShotAttempt(mixed $event): bool
    {
        if (! $this->isBoxscoreComparable($event)) {
            return false;
        }

        return $this->isShotOnGoal($event)
            || in_array(($event->type_desc_key ?? null), ['missed-shot', 'blocked-shot'], true);
    }

    public function isUnblockedShotAttempt(mixed $event): bool
    {
        if (! $this->isBoxscoreComparable($event)) {
            return false;
        }

        return $this->isShotOnGoal($event) || ($event->type_desc_key ?? null) === 'missed-shot';
    }

    public function normalizedPenaltyMinutes(mixed $event): int
    {
        $minutes = (int) ($event->duration ?? 0);

        if (strtoupper((string) ($event->penalty_type_code ?? '')) === 'MAT') {
            return $minutes + 10;
        }

        return $minutes;
    }

    public function isEmptyNetAgainst(mixed $event, ?int $homeTeamId, ?int $awayTeamId): bool
    {
        if ($this->isShootout($event)) {
            return false;
        }

        $ownerTeamId = (int) ($event->event_owner_team_id ?? 0);
        if (!$ownerTeamId || !$homeTeamId || !$awayTeamId) {
            return false;
        }

        $situationCode = substr((string) ($event->situation_code ?? ''), 0, 4);
        if (strlen($situationCode) < 4) {
            return false;
        }

        if ($ownerTeamId === $homeTeamId) {
            return $situationCode[0] === '0';
        }

        if ($ownerTeamId === $awayTeamId) {
            return $situationCode[3] === '0';
        }

        return false;
    }

    public function goalCountsAsShotOnGoal(mixed $event): bool
    {
        return !empty($event->shot_type);
    }

    public function boxscoreSogSqlPredicate(string $alias = 'p'): string
    {
        return "(COALESCE({$alias}.period_type,'') <> 'SO' AND {$this->shotOnGoalSql($alias)})";
    }

    public function shotAttemptSqlPredicate(string $alias = 'p'): string
    {
        return "(COALESCE({$alias}.period_type,'') <> 'SO' "
            . "AND ({$this->shotOnGoalSql($alias)} OR {$alias}.type_desc_key IN ('missed-shot','blocked-shot')))";
    }

    public function unblockedShotAttemptSqlPredicate(string $alias = 'p'): string
    {
        return "(COALESCE({$alias}.period_type,'') <> 'SO' "
            . "AND ({$this->shotOnGoalSql($alias)} OR {$alias}.type_desc_key = 'missed-shot'))";
    }

    public function penaltyMinutesSqlExpression(string $alias = 'p'): string
    {
        return "(COALESCE({$alias}.duration,0) + CASE WHEN UPPER(COALESCE({$alias}.penalty_type_code,'')) = 'MAT' THEN 10 ELSE 0 END)";
    }

    private function shotOnGoalSql(string $alias): string
    {
        return "({$alias}.type_desc_key = 'shot-on-goal' "
            . "OR ({$alias}.type_desc_key = 'goal' "
            . "AND {$alias}.shot_type IS NOT NULL))";
    }
}
