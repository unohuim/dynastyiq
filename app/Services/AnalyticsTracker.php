<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AnalyticsEvent;
use App\Models\AnalyticsIdentityLink;
use App\Models\AnalyticsSession;
use App\Models\AnalyticsVisitor;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Resolves first-party analytics identity and persists browser events.
 */
final class AnalyticsTracker
{
    public const VISITOR_COOKIE = 'diq_visitor_id';

    public const SESSION_COOKIE = 'diq_session_id';

    private const SESSION_TIMEOUT_MINUTES = 30;

    private const COOKIE_MINUTES = 60 * 24 * 400;

    /**
     * Persist a validated batch of analytics events.
     *
     * @param array<int,array<string,mixed>> $events
     * @return array{visitor_id:string,session_id:string,cookie_minutes:int,accepted:int}
     */
    public function track(Request $request, array $events): array
    {
        $now = CarbonImmutable::now();
        $path = $this->shortString($request->input('path') ?: $request->headers->get('referer'));
        $visitorUuid = $this->uuidCookie($request, self::VISITOR_COOKIE) ?? (string) Str::uuid();
        $sessionUuid = $this->uuidCookie($request, self::SESSION_COOKIE) ?? (string) Str::uuid();

        return DB::transaction(function () use ($request, $events, $now, $path, $visitorUuid, $sessionUuid): array {
            $visitor = $this->resolveVisitor($request, $visitorUuid, $path, $now);
            $session = $this->resolveSession($request, $visitor, $sessionUuid, $path, $now);
            $userId = $request->user()?->id;

            if ($userId !== null) {
                $this->linkVisitorToUser($visitor, (int) $userId, $now);
                $session->forceFill(['user_id' => (int) $userId])->save();
            }

            $accepted = 0;

            foreach ($events as $event) {
                if (! is_array($event)) {
                    continue;
                }

                $eventPath = $this->shortString($event['path'] ?? $path);
                $eventName = $this->shortString($event['event_name'] ?? '', 120);

                if ($eventName === '') {
                    continue;
                }

                AnalyticsEvent::query()->create([
                    'analytics_visitor_id' => $visitor->id,
                    'analytics_session_id' => $session->id,
                    'user_id' => $userId !== null ? (int) $userId : $visitor->user_id,
                    'event_name' => $eventName,
                    'path' => $eventPath,
                    'referrer' => $this->shortString($event['referrer'] ?? null),
                    'properties' => $this->properties($event['properties'] ?? null),
                    'occurred_at' => $this->occurredAt($event['occurred_at'] ?? null, $now),
                ]);

                $visitor->forceFill([
                    'last_seen_at' => $now,
                    'last_path' => $eventPath,
                ])->save();

                $session->forceFill([
                    'last_seen_at' => $now,
                    'last_path' => $eventPath,
                ])->save();

                $this->applySessionEvent($session, $eventName, $event);

                $accepted++;
            }

            return [
                'visitor_id' => $visitor->anonymous_id,
                'session_id' => $session->session_uuid,
                'cookie_minutes' => self::COOKIE_MINUTES,
                'accepted' => $accepted,
            ];
        });
    }

    private function resolveVisitor(
        Request $request,
        string $visitorUuid,
        ?string $path,
        CarbonImmutable $now,
    ): AnalyticsVisitor {
        $fingerprints = $this->fingerprints($request);

        /** @var AnalyticsVisitor $visitor */
        $visitor = AnalyticsVisitor::query()->firstOrCreate(
            ['anonymous_id' => $visitorUuid],
            [
                'first_seen_at' => $now,
                'last_seen_at' => $now,
                'first_path' => $path,
                'last_path' => $path,
                'ip_hash' => $fingerprints['ip_hash'],
                'user_agent_hash' => $fingerprints['user_agent_hash'],
            ],
        );

        if (! $visitor->wasRecentlyCreated) {
            $visitor->forceFill([
                'last_seen_at' => $now,
                'last_path' => $path ?: $visitor->last_path,
                'ip_hash' => $visitor->ip_hash ?: $fingerprints['ip_hash'],
                'user_agent_hash' => $visitor->user_agent_hash ?: $fingerprints['user_agent_hash'],
            ])->save();
        }

        return $visitor;
    }

    private function resolveSession(
        Request $request,
        AnalyticsVisitor $visitor,
        string $sessionUuid,
        ?string $path,
        CarbonImmutable $now,
    ): AnalyticsSession {
        $fingerprints = $this->fingerprints($request);

        /** @var AnalyticsSession|null $session */
        $session = AnalyticsSession::query()
            ->where('session_uuid', $sessionUuid)
            ->where('analytics_visitor_id', $visitor->id)
            ->first();

        if (
            $session instanceof AnalyticsSession
            && $session->last_seen_at !== null
            && $session->last_seen_at->lt($now->subMinutes(self::SESSION_TIMEOUT_MINUTES))
        ) {
            $session->forceFill(['ended_at' => $session->last_seen_at])->save();
            $session = null;
            $sessionUuid = (string) Str::uuid();
        }

        if (! $session instanceof AnalyticsSession) {
            /** @var AnalyticsSession $session */
            $session = AnalyticsSession::query()->create([
                'analytics_visitor_id' => $visitor->id,
                'user_id' => $visitor->user_id,
                'session_uuid' => $sessionUuid,
                'started_at' => $now,
                'last_seen_at' => $now,
                'landing_path' => $path,
                'last_path' => $path,
                'referrer' => $this->shortString($request->headers->get('referer')),
                'ip_hash' => $fingerprints['ip_hash'],
                'user_agent_hash' => $fingerprints['user_agent_hash'],
            ]);
        }

        return $session;
    }

    private function linkVisitorToUser(AnalyticsVisitor $visitor, int $userId, CarbonImmutable $now): void
    {
        if ((int) $visitor->user_id !== $userId) {
            $visitor->forceFill(['user_id' => $userId])->save();
        }

        AnalyticsIdentityLink::query()->updateOrCreate(
            [
                'analytics_visitor_id' => $visitor->id,
                'user_id' => $userId,
            ],
            [
                'method' => 'authenticated_request',
                'linked_at' => $now,
            ],
        );

        AnalyticsSession::query()
            ->where('analytics_visitor_id', $visitor->id)
            ->whereNull('user_id')
            ->update(['user_id' => $userId, 'updated_at' => $now]);

        AnalyticsEvent::query()
            ->where('analytics_visitor_id', $visitor->id)
            ->whereNull('user_id')
            ->update(['user_id' => $userId, 'updated_at' => $now]);
    }

    /**
     * @return array{ip_hash:?string,user_agent_hash:?string}
     */
    private function fingerprints(Request $request): array
    {
        return [
            'ip_hash' => $this->hashValue($request->ip()),
            'user_agent_hash' => $this->hashValue($request->userAgent()),
        ];
    }

    private function hashValue(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return hash_hmac('sha256', $value, (string) config('app.key'));
    }

    private function uuidCookie(Request $request, string $name): ?string
    {
        $value = (string) $request->cookie($name, '');

        return Str::isUuid($value) ? $value : null;
    }

    private function shortString(mixed $value, int $limit = 2048): ?string
    {
        if (! is_scalar($value) || $value === '') {
            return null;
        }

        return Str::limit((string) $value, $limit, '');
    }

    /**
     * @return array<string,mixed>|null
     */
    private function properties(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        return array_slice($value, 0, 50, true);
    }

    private function occurredAt(mixed $value, CarbonImmutable $fallback): CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return $fallback;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return $fallback;
        }
    }

    /**
     * @param array<string,mixed> $event
     */
    private function applySessionEvent(AnalyticsSession $session, string $eventName, array $event): void
    {
        if ($eventName !== 'heartbeat') {
            return;
        }

        $seconds = (int) data_get($event, 'properties.engaged_seconds', 0);

        if ($seconds <= 0) {
            return;
        }

        $session->increment('engaged_seconds', min($seconds, 60));
    }
}
