{{-- resources/views/leagues/_panel.blade.php --}}
@php
  /** @var \App\Models\PlatformLeague $league */
  $displayId = $league->id;
@endphp

<div class="p-6">
  <div class="px-2 mb-4">
    <div class="text-sm font-semibold text-slate-900">{{ $league->name }}</div>
    <div class="mt-1 text-xs text-slate-500">ID: <span class="font-mono">{{ $displayId }}</span></div>
  </div>

  <x-card-section title="Teams" is-accordian="true" class="border-0">
    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
      @forelse ($teams as $team)
        @php
          $teamName = data_get($team, 'name', 'Team');
          $avatar   = data_get($team, 'owner_avatar_url');
          // Support both array payload (controller-mapped) and Eloquent relation
          $players  = is_array($team) ? collect($team['players'] ?? []) : collect($team->roster ?? []);
        @endphp

        <x-card-section :title="$teamName" :avatar-url="$avatar" :open="false" is-accordian="true" title-class="text-xs" class="-mt-1 px-2 py-2 rounded-2xl">
          <ul class="divide-y divide-slate-100">
            @forelse ($players as $p)
              @php
                $first = is_array($p) ? ($p['first_name'] ?? '') : ($p->first_name ?? '');
                $last  = is_array($p) ? ($p['last_name']  ?? '') : ($p->last_name  ?? '');
                $pos   = is_array($p) ? ($p['position']   ?? '') : ($p->position   ?? '');
                $age   = is_array($p) ? ($p['age'] ?? null)      : ($p->age() ?? null);
              @endphp
              <li class="flex items-center justify-between gap-4 px-3 py-2">
                <div class="min-w-0">
                  <div class="truncate text-sm font-medium text-slate-900">
                    {{ trim($first.' '.$last) }}
                  </div>
                  <div class="mt-0.5 text-xs text-slate-500">
                    {{ $pos }} @if(!is_null($age)) • Age {{ $age }} @endif
                  </div>
                </div>
              </li>
            @empty
              <li class="px-3 py-4 text-sm text-slate-500">No players.</li>
            @endforelse
          </ul>
        </x-card-section>
      @empty
        <div class="col-span-full px-3 py-6 text-center text-sm text-slate-500">No teams found.</div>
      @endforelse
    </div>
  </x-card-section>
</div>
