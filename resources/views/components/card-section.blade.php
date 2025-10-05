{{-- resources/views/components/card-section.blade.php --}}
<section
    @if ($isAccordian) x-data="{ open: @js($open) }" @endif
    {{ $attributes->class('lg:col-span-2 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm relative flex flex-col') }}
    @if ($isAccordian && ($centerWhenClosed ?? false)) x-bind:class="{'justify-center': !open}" @endif
>
    <div
        class="flex items-center justify-between cursor-pointer select-none"
        @if($isAccordian) x-on:click="open = !open" :class="open ? 'mb-3' : 'mb-0'" @else class="mb-3" @endif
    >
        <div class="flex items-center gap-3">
            @if (!empty($avatarUrl))
                <img src="{{ $avatarUrl }}" alt="{{ $avatarAlt ?? 'Avatar' }}" class="h-6 w-6 rounded-full object-cover ring-1 ring-slate-200">
            @endif
            <h3 class="{{ $titleClass ?? 'text-sm font-semibold tracking-wider text-slate-600 uppercase' }}">
                {{ $title }}
            </h3>
        </div>

        @if ($isAccordian)
            <button type="button"
                    x-on:click.stop="open = !open"   {{-- caret toggles; prevent bubbling to header --}}
                    aria-label="Toggle section"
                    class="p-1 rounded-md text-slate-500 hover:text-slate-700 focus:outline-none focus:ring-2 focus:ring-indigo-200">
                <svg viewBox="0 0 20 20" fill="currentColor"
                     class="h-4 w-4 transition-transform duration-200"
                     :class="{ 'rotate-180': !open }" aria-hidden="true">
                    <path fill-rule="evenodd"
                          d="M5.23 7.21a.75.75 0 011.06.02L10 10.94l3.71-3.71a.75.75 0 111.06 1.06l-4.24 4.24a.75.75 0 01-1.06 0L5.21 8.29a.75.75 0 01.02-1.08z"
                          clip-rule="evenodd"/>
                </svg>
            </button>
        @endif
    </div>

    <div @if ($isAccordian) x-cloak x-show="open" x-transition @endif>
        {{ $slot }}
    </div>
</section>
