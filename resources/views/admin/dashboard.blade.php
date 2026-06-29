<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-1">
            <h2 class="text-xl font-semibold leading-tight text-gray-900">Admin Control Panel</h2>
            <p class="text-sm text-gray-600">Review player identity work and run supported data imports.</p>
        </div>
    </x-slot>

    @include('admin.operational', [
        'imports' => $imports,
        'hasPlayers' => $hasPlayers,
        'hasFantraxPlayers' => $hasFantraxPlayers,
    ])
</x-app-layout>
