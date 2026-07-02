<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-1">
            <h2 class="text-xl font-semibold leading-tight text-gray-900">Player Triage</h2>
            <p class="text-sm text-gray-600">Manual review inbox for provider player identities.</p>
        </div>
    </x-slot>

    <div class="py-4">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            @include('admin.player-triage', ['embedded' => false])
        </div>
    </div>
</x-app-layout>
