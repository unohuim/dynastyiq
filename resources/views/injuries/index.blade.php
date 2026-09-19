<x-app-layout>
    <main class="mx-auto max-w-6xl px-4 py-6 sm:px-6 lg:px-8">
        <div class="mb-6">
            <h1 class="text-2xl font-semibold tracking-normal text-gray-950">NHL Injuries</h1>
            <p class="mt-1 text-sm text-gray-600">Current player availability synthesized from CBS Sports and RotoWire reports.</p>
        </div>

        <div class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-600">
                        <tr><th class="px-4 py-3">Player</th><th class="px-4 py-3">Team</th><th class="px-4 py-3">Availability</th><th class="px-4 py-3">Injury</th><th class="px-4 py-3">Anticipated return</th><th class="px-4 py-3">First reported</th><th class="px-4 py-3">Last updated</th><th class="px-4 py-3">Evidence</th></tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($injuries as $injury)
                            <tr>
                                <td class="px-4 py-3 font-medium text-gray-950">{{ $injury['player_name'] }}</td>
                                <td class="px-4 py-3 text-gray-700">{{ $injury['team_abbrev'] ?? '—' }}</td>
                                <td class="px-4 py-3 text-gray-700">{{ str($injury['availability'])->headline() }}</td>
                                <td class="px-4 py-3 text-gray-700">{{ $injury['body_part'] ?? $injury['status'] ?? '—' }}</td>
                                <td class="px-4 py-3 text-gray-700">{{ $injury['anticipated_return_text'] ?? $injury['anticipated_return_date'] ?? 'Unknown' }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $injury['first_reported_at'] ? \Illuminate\Support\Carbon::parse($injury['first_reported_at'])->timezone(config('app.timezone'))->format('M j, Y') : 'Unknown' }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $injury['status_updated_at'] ? \Illuminate\Support\Carbon::parse($injury['status_updated_at'])->timezone(config('app.timezone'))->format('M j, Y') : 'Unknown' }}</td>
                                <td class="px-4 py-3 text-gray-700">{{ str($injury['evidence_level'])->headline() }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="px-4 py-10 text-center text-sm text-gray-600">No current injuries have been imported.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </main>
</x-app-layout>
