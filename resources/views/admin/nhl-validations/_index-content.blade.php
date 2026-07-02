<div class="{{ ($embedded ?? false) ? '' : 'py-6' }}">
    <div class="{{ ($embedded ?? false) ? '' : 'mx-auto max-w-7xl px-4 sm:px-6 lg:px-8' }}">
        <div class="mb-4 flex flex-wrap gap-2">
            @foreach(['failed' => 'Failed', 'invalidated' => 'Invalidated', 'incomplete' => 'Incomplete', 'approved' => 'Approved', 'accepted_exception' => 'Accepted', 'all' => 'All'] as $value => $label)
                <a
                    href="{{ route('admin.nhl-validations.index', array_filter(['status' => $value, 'admin_panel' => ($embedded ?? false) ? 1 : null])) }}"
                    class="inline-flex min-h-9 items-center rounded px-3 text-sm font-medium {{ $status === $value ? 'bg-gray-900 text-white' : 'bg-white text-gray-700 hover:bg-gray-50' }}"
                >
                    {{ $label }}
                </a>
            @endforeach
        </div>

        <div class="overflow-hidden bg-white shadow-sm">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left font-semibold text-gray-700">Game</th>
                        <th class="px-4 py-3 text-left font-semibold text-gray-700">Status</th>
                        <th class="px-4 py-3 text-left font-semibold text-gray-700">Deltas</th>
                        <th class="px-4 py-3 text-left font-semibold text-gray-700">Checked</th>
                        <th class="px-4 py-3 text-right font-semibold text-gray-700">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 bg-white">
                    @forelse($validations as $validation)
                        <tr>
                            <td class="px-4 py-3">
                                <div class="font-semibold text-gray-900">{{ $validation->nhl_game_id }}</div>
                                <div class="text-xs text-gray-500">
                                    {{ optional($validation->game)->game_date ?? 'No game record' }}
                                </div>
                            </td>
                            <td class="px-4 py-3">
                                <span class="inline-flex rounded bg-gray-100 px-2 py-1 text-xs font-medium text-gray-700">
                                    {{ str_replace('_', ' ', $validation->status) }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-gray-900">{{ $validation->mismatch_count }}</td>
                            <td class="px-4 py-3 text-gray-600">{{ optional($validation->checked_at)->toDateTimeString() ?? 'N/A' }}</td>
                            <td class="px-4 py-3 text-right">
                                @if($embedded ?? false)
                                    <div class="flex items-center justify-end gap-2">
                                        <button
                                            type="button"
                                            class="inline-flex min-h-8 items-center justify-center rounded border border-gray-300 bg-white px-2 text-xs font-semibold text-gray-700 shadow-sm transition-colors duration-150 hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-60"
                                            data-validation-rebuild
                                            data-validation-id="{{ $validation->id }}"
                                            data-validation-rebuild-url="{{ route('admin.nhl-validations.rebuild-game', $validation) }}"
                                        >
                                            <span data-validation-rebuild-label>Re Run</span>
                                        </button>
                                        <button
                                            type="button"
                                            class="inline-flex h-8 w-8 items-center justify-center rounded text-gray-500 transition-colors duration-150 hover:bg-gray-100 hover:text-gray-900"
                                            data-validation-toggle
                                            data-validation-id="{{ $validation->id }}"
                                            data-validation-url="{{ route('admin.nhl-validations.show', ['validation' => $validation->id, 'admin_panel' => 1]) }}"
                                            aria-expanded="false"
                                            aria-controls="validation-detail-{{ $validation->id }}"
                                            title="Toggle validation details"
                                        >
                                            <span class="sr-only">Toggle validation details</span>
                                            <svg class="h-4 w-4 transition-transform duration-300 ease-out motion-reduce:transition-none" data-validation-caret viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                                <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.168l3.71-3.938a.75.75 0 1 1 1.08 1.04l-4.25 4.5a.75.75 0 0 1-1.08 0l-4.25-4.5a.75.75 0 0 1 .02-1.06Z" clip-rule="evenodd" />
                                            </svg>
                                        </button>
                                    </div>
                                @else
                                    <a href="{{ route('admin.nhl-validations.show', $validation) }}" class="font-medium text-gray-900 underline decoration-gray-300 underline-offset-4 hover:decoration-gray-900">
                                        Review
                                    </a>
                                @endif
                            </td>
                        </tr>
                        @if($embedded ?? false)
                            <tr
                                id="validation-detail-{{ $validation->id }}"
                                data-validation-detail-row="{{ $validation->id }}"
                                class="hidden bg-gray-50"
                            >
                                <td colspan="5" class="px-4 py-0">
                                    <div
                                        data-validation-detail-shell="{{ $validation->id }}"
                                        class="grid grid-rows-[0fr] opacity-0 transition-[grid-template-rows,opacity] duration-300 ease-out motion-reduce:transition-none"
                                    >
                                        <div class="min-h-0 overflow-hidden">
                                            <div class="py-4">
                                                <div
                                                    data-validation-detail-content="{{ $validation->id }}"
                                                    class="overflow-hidden rounded-md border border-gray-200 bg-white"
                                                ></div>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        @endif
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-8 text-center text-sm text-gray-500">No validations found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-4">
            {{ $validations->links() }}
        </div>
    </div>
</div>
