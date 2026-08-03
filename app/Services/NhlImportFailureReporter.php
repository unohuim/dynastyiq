<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Captures NHL game import failures with Sentry tags and local metadata.
 */
class NhlImportFailureReporter
{
    private const CATEGORY_INFRA = 'infra';
    private const CATEGORY_PROVIDER = 'provider';
    private const CATEGORY_DATA_INTEGRITY = 'data_integrity';
    private const CATEGORY_CODE = 'code';

    /**
     * Capture a failed NHL import stage and return metadata safe to persist.
     *
     * @param array<string,mixed> $context
     * @return array{sentry_event_id:string|null,failure_category:string,retryable:bool}
     */
    public function capture(Throwable $throwable, int $gameId, string $stage, ?int $runId, array $context = []): array
    {
        $category = $this->classify($throwable);
        $retryable = $this->isRetryable($throwable, $category);
        $eventId = null;

        try {
            \Sentry\withScope(function (\Sentry\State\Scope $scope) use (
                $throwable,
                $gameId,
                $stage,
                $runId,
                $category,
                $retryable,
                $context,
                &$eventId
            ): void {
                $scope->setTag('domain', 'nhl-game-import');
                $scope->setTag('game_id', (string) $gameId);
                $scope->setTag('run_id', $runId !== null ? (string) $runId : 'none');
                $scope->setTag('import_type', $stage);
                $scope->setTag('failure_category', $category);
                $scope->setTag('retryable', $retryable ? 'true' : 'false');
                $scope->setTag('app_env', app()->environment());
                $scope->setTag('release', (string) (config('sentry.release') ?: 'unknown'));

                $scope->setContext('nhl_import', array_filter([
                    'game_id' => $gameId,
                    'run_id' => $runId,
                    'stage' => $stage,
                    'failure_category' => $category,
                    'retryable' => $retryable,
                    'exception' => $throwable::class,
                    'message' => $throwable->getMessage(),
                    'code' => $throwable->getCode(),
                    'machine' => gethostname() ?: null,
                    'job_class' => $context['job_class'] ?? null,
                    'attempt' => $context['attempt'] ?? null,
                    'queue' => $context['queue'] ?? null,
                ], static fn ($value): bool => $value !== null));

                $captured = \Sentry\captureException($throwable);
                $eventId = $captured !== null ? (string) $captured : null;
            });
        } catch (Throwable $reportingThrowable) {
            Log::warning('Failed to capture NHL import failure in Sentry.', [
                'game_id' => $gameId,
                'stage' => $stage,
                'run_id' => $runId,
                'message' => $reportingThrowable->getMessage(),
            ]);
        }

        return [
            'sentry_event_id' => $eventId,
            'failure_category' => $category,
            'retryable' => $retryable,
        ];
    }

    private function classify(Throwable $throwable): string
    {
        $message = $throwable->getMessage();

        if ($throwable instanceof RequestException) {
            return self::CATEGORY_PROVIDER;
        }

        if ($throwable instanceof BroadcastException || $this->containsInfraMessage($message)) {
            return self::CATEGORY_INFRA;
        }

        if ($throwable instanceof QueryException) {
            $sqlState = (string) ($throwable->errorInfo[0] ?? '');

            if (str_starts_with($sqlState, '23')) {
                return self::CATEGORY_DATA_INTEGRITY;
            }

            if (str_starts_with($sqlState, '08') || $this->containsInfraMessage($message)) {
                return self::CATEGORY_INFRA;
            }
        }

        return self::CATEGORY_CODE;
    }

    private function isRetryable(Throwable $throwable, string $category): bool
    {
        if ($category === self::CATEGORY_INFRA) {
            return true;
        }

        if ($throwable instanceof RequestException) {
            $status = $throwable->response?->status();

            return $status === 429 || ($status !== null && $status >= 500);
        }

        return false;
    }

    private function containsInfraMessage(string $message): bool
    {
        $normalized = strtolower($message);

        foreach ([
            'connection refused',
            'database system is shutting down',
            'redis is loading',
            'loading redis',
            'could not connect',
            'failed to connect',
            'server has gone away',
            'connection to server',
        ] as $needle) {
            if (str_contains($normalized, $needle)) {
                return true;
            }
        }

        return false;
    }
}
