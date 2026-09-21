<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EvidenceSource;
use App\Models\NhlTeam;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Manages X accounts available to NHL anticipated-lineup discovery. */
class XLineupSourceController extends Controller
{
    /** Return X sources, their team scopes, and the available NHL teams. */
    public function index(): JsonResponse
    {
        return response()->json([
            'sources' => $this->sourcePayloads(),
            'teams' => NhlTeam::query()
                ->orderBy('abbrev')
                ->get(['nhl_id', 'abbrev', 'full_name'])
                ->map(fn (NhlTeam $team): array => [
                    'id' => (int) $team->nhl_id,
                    'abbrev' => $team->abbrev,
                    'name' => $team->full_name ?: $team->abbrev,
                ])->values(),
        ]);
    }

    /** Create an X source and activate its selected NHL team scopes. */
    public function store(Request $request): JsonResponse
    {
        $validated = $this->validated($request);
        $source = DB::transaction(function () use ($validated): EvidenceSource {
            $source = EvidenceSource::query()->create($this->sourceAttributes($validated));
            $this->syncScopes($source, $validated['team_ids']);

            return $source;
        });

        return response()->json(['source' => $this->sourcePayload($source->id)], 201);
    }

    /** Update an X source and its active NHL team scopes without deleting history. */
    public function update(Request $request, EvidenceSource $source): JsonResponse
    {
        abort_unless($source->platform === 'x', 404);
        $validated = $this->validated($request, $source);

        DB::transaction(function () use ($source, $validated): void {
            $source->update($this->sourceAttributes($validated, $source));
            $this->syncScopes($source, $validated['team_ids']);
        });

        return response()->json(['source' => $this->sourcePayload($source->id)]);
    }

    /** Deactivate every NHL team scope for an X source while retaining evidence. */
    public function destroy(EvidenceSource $source): JsonResponse
    {
        abort_unless($source->platform === 'x', 404);

        DB::table('source_scopes')
            ->where('source_id', $source->id)
            ->where('sport', 'hockey')
            ->where('league', 'NHL')
            ->update(['is_active' => false, 'updated_at' => now()]);

        return response()->json(['source' => $this->sourcePayload($source->id)]);
    }

    /** @return array{name:string,handle:string,team_ids:array<int,int>} */
    private function validated(Request $request, ?EvidenceSource $source = null): array
    {
        $request->merge(['handle' => ltrim(trim((string) $request->input('handle')), '@')]);
        $teamRules = $source === null ? ['required', 'array', 'min:1'] : ['present', 'array'];
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'handle' => [
                'required', 'string', 'max:255', 'regex:/^@?[A-Za-z0-9_]{1,15}$/',
                Rule::unique('sources', 'handle')->where('platform', 'x')->ignore($source?->id),
            ],
            'team_ids' => $teamRules,
            'team_ids.*' => ['integer', 'distinct', Rule::exists('nhl_teams', 'nhl_id')],
        ]);
        $validated['team_ids'] = array_map('intval', $validated['team_ids']);

        return $validated;
    }

    /**
     * @param array{name:string,handle:string,team_ids:array<int,int>} $validated
     *
     * @return array<string,mixed>
     */
    private function sourceAttributes(array $validated, ?EvidenceSource $source = null): array
    {
        return [
            'platform' => 'x',
            'name' => trim($validated['name']),
            'handle' => $validated['handle'],
            'canonical_url' => 'https://x.com/' . $validated['handle'],
            'platform_user_id' => $source?->handle === $validated['handle'] ? $source->platform_user_id : null,
            'first_seen_at' => $source?->first_seen_at ?? now(),
            'last_seen_at' => $source?->last_seen_at ?? now(),
        ];
    }

    /** @param array<int,int> $teamIds */
    private function syncScopes(EvidenceSource $source, array $teamIds): void
    {
        $teams = NhlTeam::query()->whereIn('nhl_id', $teamIds)->get(['nhl_id', 'abbrev']);
        DB::table('source_scopes')->where('source_id', $source->id)
            ->where('sport', 'hockey')->where('league', 'NHL')
            ->update(['is_active' => false, 'updated_at' => now()]);

        foreach ($teams as $team) {
            DB::table('source_scopes')->updateOrInsert([
                'source_id' => $source->id,
                'sport' => 'hockey',
                'league' => 'NHL',
                'team_id' => $team->nhl_id,
            ], [
                'team_abbrev' => $team->abbrev,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /** @return array<int,array<string,mixed>> */
    private function sourcePayloads(): array
    {
        return EvidenceSource::query()->where('platform', 'x')->orderBy('name')->get()
            ->map(fn (EvidenceSource $source): array => $this->sourcePayload($source->id))->all();
    }

    /** @return array<string,mixed> */
    private function sourcePayload(int $sourceId): array
    {
        $source = EvidenceSource::query()->findOrFail($sourceId);
        $scopes = DB::table('source_scopes')->where('source_id', $sourceId)
            ->where('sport', 'hockey')->where('league', 'NHL')->orderBy('team_abbrev')->get();
        $metrics = DB::table('source_metric_snapshots')->where('source_id', $sourceId)
            ->latest('observed_at')->first();

        return [
            'id' => $source->id,
            'name' => $source->name,
            'handle' => $source->handle,
            'canonical_url' => $source->canonical_url,
            'followers' => $metrics?->followers,
            'teams' => $scopes->map(fn (object $scope): array => [
                'id' => (int) $scope->team_id,
                'abbrev' => $scope->team_abbrev,
                'active' => (bool) $scope->is_active,
            ])->values()->all(),
            'active' => $scopes->contains(fn (object $scope): bool => (bool) $scope->is_active),
        ];
    }
}
