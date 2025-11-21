@php
    $patreonAccount = $currentOrg?->providerAccounts->firstWhere('provider', 'patreon');
    $status = $patreonAccount?->status ?? 'disconnected';
    $statusCopy = [
        'connected' => 'Online',
        'offline' => 'Offline',
        'disconnected' => 'Not connected',
    ][$status] ?? ucfirst($status);
    $badgeClass = match($status) {
        'connected' => 'bg-emerald-100 text-emerald-800',
        'offline' => 'bg-amber-100 text-amber-800',
        default => 'bg-slate-100 text-slate-700',
    };
@endphp

<section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
    <div class="mb-3 flex items-center justify-between">
        <div>
            <h3 class="text-sm font-semibold tracking-wider text-slate-600 uppercase">Memberships</h3>
            <p class="text-xs text-slate-600">Connect Patreon to sync members and tiers.</p>
        </div>
        <span class="rounded-full px-3 py-1 text-xs font-semibold {{ $badgeClass }}">{{ $statusCopy }}</span>
    </div>

    <div class="space-y-3 text-sm text-slate-700">
        <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
            <div class="flex items-center justify-between text-xs text-slate-600">
                <span>Last sync</span>
                <span>{{ $patreonAccount?->last_synced_at ? $patreonAccount->last_synced_at->diffForHumans() : 'Never' }}</span>
            </div>
            <div class="mt-1 flex items-center justify-between text-xs text-slate-600">
                <span>Last webhook</span>
                <span>{{ $patreonAccount?->last_webhook_at ? $patreonAccount->last_webhook_at->diffForHumans() : 'None yet' }}</span>
            </div>
        </div>

        <div class="flex flex-wrap gap-2">
            @if($patreonAccount)
                <form method="POST" action="{{ route('patreon.sync', $currentOrg->id) }}">
                    @csrf
                    <button type="submit" class="rounded-xl bg-indigo-600 px-3 py-2 text-xs font-semibold text-white hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-300">Sync now</button>
                </form>
                <form method="POST" action="{{ route('patreon.disconnect', $currentOrg->id) }}">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-slate-200">Disconnect</button>
                </form>
            @else
                <a href="{{ route('patreon.redirect', $currentOrg->id) }}" class="inline-flex items-center gap-2 rounded-xl bg-indigo-600 px-3 py-2 text-xs font-semibold text-white hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-300">
                    Connect Patreon
                </a>
            @endif
        </div>

        <div class="rounded-xl border border-dashed border-slate-300 p-3 text-xs text-slate-600">
            Auto-sync via webhooks with nightly fallback. When offline, we pause updates but keep access unchanged.
        </div>
    </div>
</section>
