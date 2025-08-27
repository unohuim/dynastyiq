<button
    type="button"
    class="inline-flex items-center gap-2 rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500"
    @click.window="$dispatch('statsSortRequested')"
    title="Sorting UI is handled by the JS component."
>
    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path d="M3 5h10a1 1 0 100-2H3a1 1 0 100 2zm0 6h7a1 1 0 100-2H3a1 1 0 100 2zm0 6h4a1 1 0 100-2H3a1 1 0 100 2zM16.293 14.707a1 1 0 001.414 0L20 12.414V18a1 1 0 102 0v-5.586l2.293 2.293a1 1 0 001.414-1.414l-4-4a1 1 0 00-1.414 0l-4 4a1 1 0 000 1.414z"/></svg>
    Sort
</button>
