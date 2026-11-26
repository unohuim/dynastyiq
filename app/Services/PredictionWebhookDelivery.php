<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\DeliverPredictionWebhookJob;
use App\Models\WebhookDelivery;
use App\Models\WebhookSubscription;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/** Revision-bound webhook outbox with independent delivery claims and bounded HTTP work. */
class PredictionWebhookDelivery
{
    private const MAX_ATTEMPTS = 5;

    /** Stage enabled subscribers atomically with publication; subscription CRUD never calls this. */
    public function stage(int $revisionId): void
    {
        if (! Schema::hasTable('webhook_deliveries')) {
            return;
        }
        DB::transaction(function () use ($revisionId): void {
            $revision = DB::table('nhl_game_prediction_revisions')->where('id', $revisionId)->first();
            if ($revision === null || ! $revision->prediction_available || ! $revision->is_canonical) {
                return;
            }
            $game = DB::table('nhl_games')->where('nhl_game_id', $revision->nhl_game_id)->first();
            $prediction = json_decode($revision->payload, true, 512, JSON_THROW_ON_ERROR)['prediction'] ?? [];
            if ($game === null) {
                return;
            }
            $subscriptions = WebhookSubscription::query()->with('apiClient')->where('is_enabled', true)
                ->whereHas('apiClient', fn ($q) => $q->whereNull('revoked_at'))->get();
            foreach ($subscriptions as $subscription) {
                if (! $subscription->apiClient?->hasScope('nhl-stats:read')
                    || ! in_array('prediction.available', $subscription->events ?? [], true)) {
                    continue;
                }
                $eventId = (string) Str::uuid();
                $transport = $this->discordUrl($subscription->receiving_url) ? 'discord' : 'json';
                $body = ['id' => $eventId, 'event' => 'prediction.available',
                    'occurred_at' => CarbonImmutable::parse($revision->predicted_at, 'UTC')->toIso8601String(),
                    'data' => ['nhl_game_id' => (int) $game->nhl_game_id, 'prediction_revision_id' => $revisionId,
                        'game_date' => $game->game_date, 'away_team' => $game->away_team_abbrev,
                        'home_team' => $game->home_team_abbrev, 'predicted_at' => $revision->predicted_at,
                        'winner' => $prediction['winner'] ?? null, 'predicted_score' => $prediction['predicted_score'] ?? null,
                        'away_win_probability' => $prediction['away_win_probability'] ?? null,
                        'home_win_probability' => $prediction['home_win_probability'] ?? null,
                        'public_confidence_score' => $revision->public_confidence_score === null ? null : (float) $revision->public_confidence_score,
                        'days_away' => $revision->days_away === null ? null : (int) $revision->days_away,
                        'game_url' => rtrim((string) config('app.url'), '/').'/games/'.$game->nhl_game_id]];
                $delivery = WebhookDelivery::query()->firstOrCreate([
                    'webhook_subscription_id' => $subscription->id, 'prediction_revision_id' => $revisionId,
                    'event' => 'prediction.available',
                ], ['event_id' => $eventId, 'transport' => $transport, 'destination' => $subscription->receiving_url,
                    'payload' => $body, 'status' => 'pending', 'next_attempt_at' => now()]);
                if ($delivery->wasRecentlyCreated) {
                    DB::afterCommit(fn () => $this->dispatch((int) $delivery->id));
                }
            }
        });
    }

    /** Broker errors leave durable work for the scheduler instead of failing the saved prediction. */
    private function dispatch(int $id): void
    {
        try {
            DeliverPredictionWebhookJob::dispatch($id);
            WebhookDelivery::query()->whereKey($id)->whereIn('status', ['pending', 'retrying'])
                ->update(['queued_at' => now()]);
        } catch (Throwable) {
            Log::warning('Prediction webhook dispatch deferred', ['delivery_id' => $id]);
        }
    }

    /** Recover due or abandoned deliveries in small batches, without replaying historical predictions. */
    public function dispatchDue(): void
    {
        if (! Schema::hasTable('webhook_deliveries')) {
            return;
        }
        $ids = DB::transaction(function (): array {
            $rows = WebhookDelivery::query()->where(function ($q): void {
                $q->where(function ($due): void {
                    $due->whereIn('status', ['pending', 'retrying'])->where('next_attempt_at', '<=', now())
                        ->where(fn ($queued) => $queued->whereNull('queued_at')->orWhere('queued_at', '<', now()->subMinutes(5)));
                })->orWhere(fn ($lost) => $lost->where('status', 'delivering')->where('claim_expires_at', '<=', now()));
            })->orderBy('id')->limit(50)->lock('for update skip locked')->get();
            foreach ($rows as $row) {
                $row->update(['status' => 'retrying', 'queued_at' => now(), 'next_attempt_at' => now(),
                    'claim_token' => null, 'claim_expires_at' => null]);
            }
            return $rows->pluck('id')->all();
        });
        foreach ($ids as $id) {
            $this->dispatch((int) $id);
        }
    }

    /** Claim one notification, make no network request under a database lock, then fence its result. */
    public function deliver(int $id): void
    {
        $delivery = DB::transaction(function () use ($id): ?WebhookDelivery {
            $row = WebhookDelivery::query()->lockForUpdate()->find($id);
            if ($row === null || in_array($row->status, ['delivered', 'failed', 'cancelled'], true)
                || ($row->status === 'delivering' && $row->claim_expires_at?->isFuture())
                || $row->next_attempt_at?->isFuture()) {
                return null;
            }
            $subscription = WebhookSubscription::query()->with('apiClient')->find($row->webhook_subscription_id);
            if ($subscription === null || ! $subscription->is_enabled || $subscription->apiClient === null
                || $subscription->apiClient->revoked_at !== null || ! $subscription->apiClient->hasScope('nhl-stats:read')
                || ! in_array($row->event, $subscription->events ?? [], true)
                || $subscription->receiving_url !== $row->destination) {
                $row->update(['status' => 'cancelled', 'last_error' => 'Subscription is disabled, changed or revoked.',
                    'claim_token' => null, 'claim_expires_at' => null]);
                return null;
            }
            if ($row->attempts >= self::MAX_ATTEMPTS) {
                $row->update(['status' => 'failed', 'last_error' => 'Delivery attempts exhausted.',
                    'claim_token' => null, 'claim_expires_at' => null]);
                return null;
            }
            $row->update(['status' => 'delivering', 'attempts' => $row->attempts + 1,
                'claim_token' => (string) Str::uuid(), 'claim_expires_at' => now()->addMinute(),
                'last_attempt_at' => now(), 'queued_at' => null]);
            return $row;
        });
        if ($delivery === null) {
            return;
        }
        $status = null;
        $retry = false;
        $delay = [30, 120, 600, 1800][min(3, $delivery->attempts - 1)];
        $messageId = null;
        $error = null;
        try {
            [$host, $address] = $this->destination($delivery->destination);
            $body = $delivery->transport === 'discord' ? $this->discordPayload($delivery->payload) : $delivery->payload;
            $raw = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $subscription = WebhookSubscription::query()->findOrFail($delivery->webhook_subscription_id);
            $timestamp = (string) now()->timestamp;
            $headers = ['X-DIQ-Event' => $delivery->event, 'X-DIQ-Delivery-Id' => $delivery->event_id,
                'X-DIQ-Timestamp' => $timestamp];
            if ($delivery->transport === 'json') {
                $headers['X-DIQ-Signature'] = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$raw, $subscription->signing_secret);
            }
            $url = $delivery->destination;
            if ($delivery->transport === 'discord') {
                $parts = parse_url($url);
                parse_str($parts['query'] ?? '', $query);
                $url = 'https://'.$host.$parts['path'].'?'.http_build_query([...$query, 'wait' => 'true']);
            }
            $pinned = str_contains($address, ':') ? '['.$address.']' : $address;
            $response = Http::withHeaders($headers)->withOptions(['allow_redirects' => false, 'proxy' => '',
                'curl' => [CURLOPT_RESOLVE => [$host.':443:'.$pinned]]])->connectTimeout(5)->timeout(15)
                ->withBody($raw, 'application/json')->send('POST', $url);
            $status = $response->status();
            if ($response->successful()) {
                $messageId = $delivery->transport === 'discord' ? $response->json('id') : null;
                if ($delivery->transport === 'discord' && (! is_string($messageId) || $messageId === '')) {
                    $error = 'Discord did not confirm a message ID.';
                    $retry = true;
                }
            } else {
                $error = 'HTTP '.$status.': notification was not accepted.';
                $retry = $status === 429 || $status === 408 || $status >= 500;
                if ($status === 429) {
                    $retryAfter = $response->header('Retry-After') ?? $response->json('retry_after');
                    if (is_numeric($retryAfter)) {
                        $delay = max(1, min(86400, (int) ceil((float) $retryAfter)));
                    } elseif (is_string($retryAfter) && strtotime($retryAfter) !== false) {
                        $delay = max(1, min(86400, strtotime($retryAfter) - now()->timestamp));
                    }
                }
            }
        } catch (RuntimeException $exception) {
            // Destination validation uses fixed messages; never store provider URLs or private tokens.
            $error = $exception->getMessage() === 'Destination is not a public HTTPS endpoint.'
                ? $exception->getMessage() : 'Unable to contact notification destination.';
            $retry = $error !== 'Destination is not a public HTTPS endpoint.';
        } catch (Throwable) {
            $error = 'Unable to contact notification destination.';
            $retry = true;
        }
        $pending = $error !== null && $retry && $delivery->attempts < self::MAX_ATTEMPTS;
        $updated = WebhookDelivery::query()->whereKey($id)->where('status', 'delivering')
            ->where('claim_token', $delivery->claim_token)->update([
                'status' => $error === null ? 'delivered' : ($pending ? 'retrying' : 'failed'),
                'http_status' => $status, 'last_error' => $error, 'remote_message_id' => $messageId,
                'delivered_at' => $error === null ? now() : null, 'next_attempt_at' => $pending ? now()->addSeconds($delay) : null,
                'claim_token' => null, 'claim_expires_at' => null,
            ]);
        if ($updated && $pending) {
            DB::afterCommit(function () use ($id, $delay): void {
                try {
                    DeliverPredictionWebhookJob::dispatch($id)->delay(now()->addSeconds($delay));
                    WebhookDelivery::query()->whereKey($id)->where('status', 'retrying')->update(['queued_at' => now()]);
                } catch (Throwable) {
                    Log::warning('Prediction webhook retry deferred', ['delivery_id' => $id]);
                }
            });
        }
    }

    /** Recognize only native incoming Discord webhook destinations, not arbitrary Discord pages. */
    private function discordUrl(string $url): bool
    {
        return in_array(strtolower((string) parse_url($url, PHP_URL_HOST)), ['discord.com', 'discordapp.com',
            'canary.discord.com', 'ptb.discord.com'], true)
            && preg_match('~^/api(?:/v[0-9]+)?/webhooks/[0-9]+/[A-Za-z0-9._-]+$~', (string) parse_url($url, PHP_URL_PATH)) === 1;
    }

    /** Resolve and pin a public destination so redirects and DNS rebinding cannot expose internal services. */
    private function destination(string $url): array
    {
        $parts = parse_url($url);
        $host = strtolower($parts['host'] ?? '');
        if ($parts === false || ($parts['scheme'] ?? '') !== 'https' || ($parts['port'] ?? 443) !== 443
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || ! preg_match('/^(?=.{1,253}$)[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$/', $host)
            || ! str_contains($host, '.') || filter_var($host, FILTER_VALIDATE_IP)) {
            throw new RuntimeException('Destination is not a public HTTPS endpoint.');
        }
        $addresses = $this->addresses($host);
        if ($addresses === []) {
            throw new RuntimeException('Unable to resolve destination.');
        }
        foreach ($addresses as $address) {
            if (\Symfony\Component\HttpFoundation\IpUtils::checkIp($address, [
                '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16',
                '172.16.0.0/12', '192.0.0.0/24', '192.168.0.0/16', '198.18.0.0/15', '224.0.0.0/4', '240.0.0.0/4',
                '::/128', '::1/128', 'fc00::/7', 'fe80::/10', 'ff00::/8',
            ]) || str_starts_with(strtolower($address), '::ffff:')
                || ! filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new RuntimeException('Destination is not a public HTTPS endpoint.');
            }
        }
        return [$host, $addresses[0]];
    }

    /** DNS is a narrow protected seam for deterministic delivery tests. */
    protected function addresses(string $host): array
    {
        $names = [$host];
        $addresses = [];
        for ($depth = 0; $depth < 4 && $names !== []; $depth++) {
            $next = [];
            foreach (array_unique($names) as $name) {
                foreach (dns_get_record($name, DNS_A | DNS_AAAA | DNS_CNAME) ?: [] as $record) {
                    if (isset($record['ip']) || isset($record['ipv6'])) {
                        $addresses[] = $record['ip'] ?? $record['ipv6'];
                    } elseif (isset($record['target'])) {
                        $next[] = $record['target'];
                    }
                }
            }
            $names = $next;
        }
        return array_values(array_unique($addresses));
    }

    /** Build a Discord-native message from public revision fields only; disable mention parsing. */
    private function discordPayload(array $payload): array
    {
        $data = $payload['data'];
        $winner = data_get($data, 'winner.team_abbrev') ?? 'Even matchup';
        $side = data_get($data, 'winner.side');
        $probability = in_array($side, ['away', 'home'], true) ? ($data[$side.'_win_probability'] ?? null) : null;
        $value = static fn ($number): string => is_numeric($number) ? number_format((float) $number, 1) : '—';
        return ['allowed_mentions' => ['parse' => []], 'embeds' => [[
            'title' => $data['away_team'].' @ '.$data['home_team'].' · '.$data['game_date'],
            'url' => $data['game_url'], 'description' => 'New DynastyIQ prediction: **'.$winner.'**',
            'color' => 3066993, 'fields' => [
                ['name' => 'Win probability', 'value' => $value($probability === null ? null : 100 * $probability).'%', 'inline' => true],
                ['name' => 'Public confidence', 'value' => $value($data['public_confidence_score']).'%', 'inline' => true],
                ['name' => 'Predicted score', 'value' => $value($data['predicted_score']['away'] ?? null).'–'.$value($data['predicted_score']['home'] ?? null), 'inline' => true],
            ], 'timestamp' => $payload['occurred_at'], 'footer' => ['text' => 'Revision '.$data['prediction_revision_id']],
        ]]];
    }
}
