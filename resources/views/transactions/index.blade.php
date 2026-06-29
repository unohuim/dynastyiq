<x-app-layout>
    <div
        data-transactions-page
        data-payload-url="{{ $payloadUrl }}"
        class="mx-auto max-w-6xl px-4 py-6 sm:px-6 lg:px-8"
    >
        <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <h1 class="text-2xl font-semibold tracking-normal text-gray-950">Transactions</h1>
                <p class="mt-1 max-w-2xl text-sm text-gray-600">
                    NHL player movement history from imported provider records.
                </p>
            </div>

            <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                <label class="sr-only" for="transactions-search">Search transactions</label>
                <input
                    id="transactions-search"
                    data-transactions-search
                    type="search"
                    autocomplete="off"
                    placeholder="Search player or details"
                    class="h-10 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:w-64"
                >

                <label class="sr-only" for="transactions-type">Filter by type</label>
                <select
                    id="transactions-type"
                    data-transactions-type
                    class="h-10 rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                >
                    <option value="">All types</option>
                </select>

                <div class="inline-flex h-10 rounded-md border border-gray-300 bg-white p-0.5 shadow-sm">
                    <button
                        type="button"
                        data-transactions-sort="date_desc"
                        class="rounded px-3 text-sm font-medium text-gray-700"
                    >
                        Newest
                    </button>
                    <button
                        type="button"
                        data-transactions-sort="date_asc"
                        class="rounded px-3 text-sm font-medium text-gray-700"
                    >
                        Oldest
                    </button>
                </div>
            </div>
        </div>

        <div
            data-transactions-status
            class="mb-3 text-sm text-gray-600"
            role="status"
            aria-live="polite"
        >
            Loading transactions...
        </div>

        <div
            data-transactions-list
            class="overflow-visible"
        ></div>
    </div>
</x-app-layout>
