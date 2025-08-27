<button
    type="button"
    class="inline-flex items-center gap-2 rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500"
    @click.window="$dispatch('statsFilterRequested')"
    title="Filters are handled by the JS component."
>
    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path d="M3 5a1 1 0 011-1h12a1 1 0 01.8 1.6l-3.6 4.8a1 1 0 00-.2.6V15a1 1 0 01-1.447.894l-2-1A1 1 0 018 14v-3.6a1 1 0 00-.2-.6L4.2 5.6A1 1 0 013 5z"/></svg>
    Filter
</button>
