<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FantraxPlayer;
use App\Models\ImportRun;
use App\Models\Player;
use App\Models\User;
use App\Services\AdminImports;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DashboardController extends Controller
{
    public function __construct(
        private AdminImports $imports,
    ) {
    }

    public function index(Request $request)
    {
        if ($request->wantsJson() && $request->query('section') === 'players') {
            return $this->players($request);
        }

        $imports = $this->imports->sources()->map(function (array $source) {
            $lastRun = ImportRun::query()
                ->where('source', $source['key'])
                ->latest('ran_at')
                ->first();

            return [
                'key' => $source['key'],
                'label' => $source['label'],
                'group' => $source['group'] ?? 'player',
                'last_run' => ($lastRun?->finished_at ?? $lastRun?->started_at)?->toIso8601String(),
                'status' => $lastRun?->status,
                'started_at' => $lastRun?->started_at?->toIso8601String(),
                'finished_at' => $lastRun?->finished_at?->toIso8601String(),
                'duration_seconds' => $lastRun?->duration_seconds,
                'run_url' => $this->importRunUrl($source),
                'status_url' => route('admin.imports.status', ['key' => $source['key']]),
                'progress' => $lastRun ? $this->importProgressPayload($lastRun) : null,
            ];
        });

        $hasPlayers = Player::query()->exists();
        $hasFantraxPlayers = FantraxPlayer::query()->exists();

        return view('admin.dashboard', [
            'imports' => $imports,
            'hasPlayers' => $hasPlayers,
            'hasFantraxPlayers' => $hasFantraxPlayers,
            'users' => $this->usersPayload(),
        ]);
    }

    protected function players(Request $request): JsonResponse
    {
        $perPage = (int) $request->integer('per_page', 25);
        $perPage = max(5, min($perPage, 100));

        $page = (int) $request->integer('page', 1);
        $page = max($page, 1);

        $filter = Str::of($request->string('filter')->toString())->trim();

        $query = Player::query();

        if ($filter->isNotEmpty()) {
            $term = '%' . $filter . '%';
            $query->where(function ($builder) use ($term) {
                $builder
                    ->where('full_name', 'like', $term)
                    ->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", [$term])
                    ->orWhere('first_name', 'like', $term)
                    ->orWhere('last_name', 'like', $term);
            });
        }

        $total = $query->count();
        $offset = ($page - 1) * $perPage;

        $players = $query
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->offset($offset)
            ->limit($perPage)
            ->get([
                'id',
                'first_name',
                'last_name',
                'full_name',
                'position',
                'team_abbrev',
            ]);

        return response()->json([
            'data' => $players,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'has_more' => ($offset + $perPage) < $total,
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $source
     */
    private function importRunUrl(array $source): ?string
    {
        if (isset($source['run_route'])) {
            return route($source['run_route']);
        }

        if (isset($source['command'])) {
            return route('admin.imports.run', ['key' => $source['key']]);
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function importProgressPayload(ImportRun $importRun): array
    {
        $total = $importRun->total_records;
        $processed = $importRun->processed_records ?? 0;
        $dynamicTotal = (bool) ($importRun->meta['dynamic_total'] ?? false);

        return [
            'label' => $importRun->progress_label,
            'total_records' => $total,
            'processed_records' => $processed,
            'successful_records' => $importRun->successful_records ?? 0,
            'failed_records' => $importRun->failed_records ?? 0,
            'skipped_records' => $importRun->skipped_records ?? 0,
            'dynamic_total' => $dynamicTotal,
            'percentage' => $total && ! $dynamicTotal
                ? min(100, (int) floor(($processed / max(1, $total)) * 100))
                : null,
        ];
    }

    /**
     * Build the super-admin user directory payload.
     *
     * @return array<int,array<string,mixed>>
     */
    private function usersPayload(): array
    {
        $users = User::query()
            ->with([
                'roles:id,slug,name',
                'socialAccounts' => static function ($query): void {
                    $query->where('provider', 'discord')
                        ->select('id', 'user_id', 'provider', 'provider_user_id', 'nickname', 'name', 'avatar');
                },
            ])
            ->orderBy('name')
            ->orderBy('email')
            ->get([
                'id',
                'name',
                'email',
                'email_verified_at',
                'profile_photo_path',
                'created_at',
                'updated_at',
            ]);
        $lastActivityByUserId = DB::table('sessions')
            ->select('user_id', DB::raw('MAX(last_activity) as last_activity'))
            ->whereNotNull('user_id')
            ->groupBy('user_id')
            ->pluck('last_activity', 'user_id')
            ->mapWithKeys(static fn (mixed $lastActivity, mixed $userId): array => [
                (int) $userId => (int) $lastActivity,
            ])
            ->all();

        return $users
            ->map(fn (User $user): array => $this->userPayload($user, $lastActivityByUserId))
            ->values()
            ->all();
    }

    /**
     * @param array<int,int> $lastActivityByUserId
     * @return array<string,mixed>
     */
    private function userPayload(User $user, array $lastActivityByUserId): array
    {
        $lastActivity = $lastActivityByUserId[(int) $user->id] ?? null;
        $lastSeenAt = $lastActivity !== null ? Carbon::createFromTimestamp($lastActivity) : null;
        $presence = $this->presencePayload($lastActivity);
        $discordAccount = $user->socialAccounts instanceof EloquentCollection
            ? $user->socialAccounts->first()
            : null;

        return [
            'id' => (int) $user->id,
            'name' => (string) $user->name,
            'email' => (string) $user->email,
            'avatar_url' => $discordAccount?->avatar ?: $user->profile_photo_url,
            'discord_name' => $discordAccount?->nickname ?: $discordAccount?->name,
            'discord_user_id' => $discordAccount?->provider_user_id,
            'roles' => $user->roles
                ->pluck('slug')
                ->map(static fn (mixed $role): string => (string) $role)
                ->values()
                ->all(),
            'email_verified' => $user->email_verified_at !== null,
            'presence' => $presence,
            'last_seen_at' => $lastSeenAt?->toIso8601String(),
            'created_at' => $user->created_at?->toIso8601String(),
        ];
    }

    /**
     * Build DynastyIQ presence from the local sessions table.
     *
     * @return array{state:string,label:string,tone:string}
     */
    private function presencePayload(?int $lastActivity): array
    {
        if ($lastActivity === null) {
            return [
                'state' => 'offline',
                'label' => 'Offline',
                'tone' => 'slate',
            ];
        }

        $secondsSinceActivity = max(0, now()->timestamp - $lastActivity);

        if ($secondsSinceActivity <= 300) {
            return [
                'state' => 'online',
                'label' => 'Online',
                'tone' => 'green',
            ];
        }

        if ($secondsSinceActivity <= 1800) {
            return [
                'state' => 'recent',
                'label' => 'Recently active',
                'tone' => 'amber',
            ];
        }

        return [
            'state' => 'offline',
            'label' => 'Offline',
            'tone' => 'slate',
        ];
    }
}
