{{-- resources/views/nav/partials/_discord.blade.php --}}
@php
    $hasDiscord = auth()->user()?->socialAccounts()?->where('provider','discord')->exists();
    $inviteUrl  = config('services.discord.invite'); // set in services.php
@endphp

<a href="{{ $inviteUrl }}" target="_blank" rel="noopener"
   class="group flex items-center justify-between px-3 py-2 rounded-xl hover:bg-white/5">
    <div class="flex items-center gap-3">
        <img src="{{ asset('images/dynastyiq-shield.png') }}" alt="DynastyIQ" class="h-5 w-5 rounded-sm object-contain">
        <span class="text-sm">Our Discord</span>
    </div>
    <div class="flex items-center gap-2">
        @if($hasDiscord)
            <span class="text-[11px] px-2 py-0.5 rounded-full bg-emerald-500/15 text-emerald-200 ring-1 ring-emerald-400/20">
                Connected
            </span>
        @else
            <span class="text-[11px] px-2 py-0.5 rounded-full bg-indigo-500/15 text-indigo-200 ring-1 ring-indigo-400/20">
                Join
            </span>
        @endif
        <svg class="h-4 w-4 text-gray-400 group-hover:text-gray-300" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
        </svg>
    </div>
</a>
