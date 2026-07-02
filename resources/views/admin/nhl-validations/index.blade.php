<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-1">
            <h2 class="text-xl font-semibold leading-tight text-gray-900">Game Validation Triage</h2>
            <p class="text-sm text-gray-600">Summary totals compared against official boxscores.</p>
        </div>
    </x-slot>

    @if(session('status'))
        <div class="mx-auto mt-6 max-w-7xl px-4 sm:px-6 lg:px-8">
            <div class="border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
                {{ session('status') }}
            </div>
        </div>
    @endif

    @include('admin.nhl-validations._index-content', ['embedded' => false])
</x-app-layout>
