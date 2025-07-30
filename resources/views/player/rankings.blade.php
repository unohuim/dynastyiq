<x-app-layout>

    
    <div class="max-w-7xl mx-auto py-8 px-4">
        <div class="mb-6">
            <h1 class="text-3xl font-semibold text-gray-800">Player Rankings</h1>

                    @if ($errors->any())
                        <div class="text-red-500">
                            <ul>
                                @foreach ($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
        </div>

        <!-- CSV Upload Form -->
        <div x-data="{ open: false }" class="mb-6">
            <button @click="open = !open" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">
                Upload CSV Rankings
            </button>

            <div x-show="open" class="mt-4 bg-white border rounded p-4 shadow">
                <form action="{{ route('player.rankings.upload') }}" method="POST" enctype="multipart/form-data">
                    @csrf
                    <div class="mb-4">
                        <label class="block text-gray-700">Ranking Type</label>
                        <input type="text" name="ranking_type" class="w-full border px-3 py-2 rounded" required>
                    </div>
                    <div class="mb-4">
                        <label class="block text-gray-700">CSV File</label>
                        <input type="file" name="file" class="w-full border px-3 py-2 rounded" accept=".csv" required>
                    </div>
                    <button type="submit" class="bg-green-600 text-white px-4 py-2 rounded hover:bg-green-700">
                        Upload
                    </button>


                </form>
            </div>
        </div>

        <!-- Livewire Player Rankings Table -->
        <livewire:player-rankings-table />
    </div>
</x-app-layout>