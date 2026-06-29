<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\NhlPlayerTransaction;
use App\Models\Player;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Displays public NHL-domain player transaction history.
 */
class NhlPlayerTransactionController extends BaseController
{
    private const HIDDEN_PUBLIC_TYPES = [
        'draft',
        'drafted',
        'transfer',
    ];

    /**
     * Render the public transactions page shell.
     */
    public function index(): View
    {
        return view('transactions.index', [
            'payloadUrl' => route('transactions.payload'),
        ]);
    }

    /**
     * Return transaction rows and filter metadata for the JavaScript renderer.
     */
    public function payload(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['nullable', 'string', 'max:80'],
            'sort' => ['nullable', 'in:date_desc,date_asc'],
            'q' => ['nullable', 'string', 'max:120'],
        ]);

        $sort = (string) ($validated['sort'] ?? 'date_desc');
        $type = trim((string) ($validated['type'] ?? ''));
        $search = trim((string) ($validated['q'] ?? ''));

        $query = NhlPlayerTransaction::query()
            ->with([
                'player:id,full_name,first_name,last_name,position,team_abbrev,head_shot_url',
                'player.contracts.seasons',
                'externalIdentity:id,display_name,team,position',
            ]);
        $this->applyPublicVisibility($query);

        if ($type !== '') {
            $query->where('transaction_type', $type);
        }

        if ($search !== '') {
            $query->where(function (Builder $query) use ($search): void {
                $query->where('description', 'like', '%' . $search . '%')
                    ->orWhereHas('player', function (Builder $playerQuery) use ($search): void {
                        $playerQuery->where('full_name', 'like', '%' . $search . '%')
                            ->orWhere('first_name', 'like', '%' . $search . '%')
                            ->orWhere('last_name', 'like', '%' . $search . '%');
                    })
                    ->orWhereHas('externalIdentity', function (Builder $identityQuery) use ($search): void {
                        $identityQuery->where('display_name', 'like', '%' . $search . '%');
                    });
            });
        }

        $transactions = $this->applyDateSort($query, $sort)
            ->limit(500)
            ->get()
            ->map(fn (NhlPlayerTransaction $transaction): array => $this->transactionPayload($transaction))
            ->values();

        return response()->json([
            'transactions' => $transactions,
            'filters' => [
                'types' => $this->transactionTypes(),
                'applied' => [
                    'type' => $type !== '' ? $type : null,
                    'sort' => $sort,
                    'q' => $search,
                ],
            ],
            'meta' => [
                'count' => $transactions->count(),
                'limit' => 500,
            ],
        ]);
    }

    private function applyDateSort(Builder $query, string $sort): Builder
    {
        $direction = $sort === 'date_asc' ? 'asc' : 'desc';

        return $query
            ->orderByRaw('CASE WHEN transaction_date IS NULL THEN 1 ELSE 0 END ASC')
            ->orderBy('transaction_date', $direction)
            ->orderBy('updated_at', 'desc')
            ->orderBy('id', 'desc');
    }

    /**
     * Hide transaction types that are imported for audit context but not shown publicly.
     */
    private function applyPublicVisibility(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query->whereNull('transaction_type')
                ->orWhereNotIn('transaction_type', self::HIDDEN_PUBLIC_TYPES);
        });
    }

    /**
     * @return array<int,array{value:string,label:string,count:int}>
     */
    private function transactionTypes(): array
    {
        return NhlPlayerTransaction::query()
            ->selectRaw('transaction_type, count(*) as aggregate')
            ->whereNotNull('transaction_type')
            ->whereNotIn('transaction_type', self::HIDDEN_PUBLIC_TYPES)
            ->groupBy('transaction_type')
            ->orderBy('transaction_type')
            ->get()
            ->map(fn (NhlPlayerTransaction $transaction): array => [
                'value' => (string) $transaction->transaction_type,
                'label' => $this->typeLabel((string) $transaction->transaction_type),
                'count' => (int) $transaction->aggregate,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string,mixed>
     */
    private function transactionPayload(NhlPlayerTransaction $transaction): array
    {
        $date = $transaction->transaction_date;
        $player = $transaction->player;
        $identity = $transaction->externalIdentity;
        $playerName = $player?->full_name
            ?: trim((string) ($identity?->display_name ?? ''));
        $playerName = $playerName !== '' ? $playerName : $this->fallbackPlayerName($transaction);

        return [
            'id' => $transaction->id,
            'date' => $date?->toDateString(),
            'dateLabel' => $this->dateLabel($date),
            'type' => $transaction->transaction_type,
            'typeLabel' => $this->typeLabel($transaction->transaction_type),
            'description' => $transaction->description,
            'fromTeam' => $transaction->from_team,
            'toTeam' => $transaction->to_team,
            'source' => $transaction->source,
            'sourceLabel' => $this->typeLabel($transaction->source),
            'player' => [
                'id' => $player?->id,
                'name' => $playerName,
                'team' => $player?->team_abbrev ?? $identity?->team,
                'position' => $player?->position ?? $identity?->position,
                'avatarUrl' => $player?->head_shot_url,
                'initials' => $this->initials($playerName),
                'contractSummary' => $this->contractSummary($player),
            ],
        ];
    }

    private function dateLabel(?Carbon $date): string
    {
        return $date?->format('M j, Y') ?? 'Unknown date';
    }

    /**
     * Convert provider/source keys into display labels.
     */
    private function typeLabel(?string $value): string
    {
        $value = trim((string) $value);

        return $value !== '' ? Str::of($value)->replace(['_', '-'], ' ')->title()->toString() : 'Unknown';
    }

    private function fallbackPlayerName(NhlPlayerTransaction $transaction): string
    {
        $slug = data_get($transaction->raw_payload, 'slug');

        if (is_string($slug) && trim($slug) !== '') {
            return Str::of($slug)->replace('-', ' ')->title()->toString();
        }

        return 'Unlinked player';
    }

    /**
     * Format the latest canonical contract as "$5.45M x 4 yrs (2031)".
     */
    private function contractSummary(?Player $player): ?string
    {
        $contract = $player?->contracts
            ?->sortBy([
                ['signing_date', 'desc'],
                ['id', 'desc'],
            ])
            ->first();

        if ($contract === null || $contract->seasons->isEmpty()) {
            return null;
        }

        $seasons = $contract->seasons
            ->where('season_key', '>=', $this->currentSeasonKey())
            ->sortBy('season_key')
            ->values();

        if ($seasons->isEmpty()) {
            return null;
        }

        $aav = (int) ($seasons->firstWhere('aav', '>', 0)?->aav ?? $seasons->first()?->aav ?? 0);
        $lastSeasonKey = (int) ($seasons->max('season_key') ?? 0);
        $lastYear = $lastSeasonKey > 0 ? $lastSeasonKey % 10000 : null;

        if ($aav <= 0 || $lastYear === null) {
            return null;
        }

        $years = $seasons->count();

        return sprintf(
            '$%sM x %d %s (%d)',
            $this->millionsLabel($aav),
            $years,
            $years === 1 ? 'yr' : 'yrs',
            $lastYear,
        );
    }

    /**
     * Convert source minor units into a compact millions label without trailing zeros.
     */
    private function millionsLabel(int $value): string
    {
        return rtrim(rtrim(number_format($value / 1_000_000, 2, '.', ''), '0'), '.');
    }

    private function currentSeasonKey(): int
    {
        $year = Carbon::now()->year;

        return (($year - 1) * 10000) + $year;
    }

    private function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $initials = collect($parts)
            ->filter()
            ->take(2)
            ->map(fn (string $part): string => mb_strtoupper(mb_substr($part, 0, 1)))
            ->implode('');

        return $initials !== '' ? $initials : 'DI';
    }
}
