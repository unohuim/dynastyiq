{{-- resources/views/communities/index.blade.php --}}
@php
    /** @var \Illuminate\Support\Collection|\App\Models\Organization[] $communities */
    $activeCommunity = $activeCommunity ?? $communities->first();
@endphp

<x-app-layout>
    <div class="px-4 py-6 sm:px-6 lg:px-8">
        @if ($communities->isEmpty())
            <section class="rounded-lg border border-slate-200 bg-white p-10 text-center text-slate-700">
                <h2 class="text-xl font-semibold text-slate-900">No communities yet</h2>
                <p class="mt-2 text-sm text-slate-600">
                    Communities you own or belong to will appear here.
                </p>
            </section>
        @else
            @include('communities._desktop', [
                'communities' => $communities,
                'activeCommunity' => $activeCommunity,
                'fantraxConnected' => $fantraxConnected,
                'fantraxOptions' => $fantraxOptions,
                'initialMembers' => $initialMembers,
                'initialTiers' => $initialTiers,
            ])
        @endif
    </div>
</x-app-layout>
