@php
    $fantraxSecret = auth()->user()->fantraxSecret?->secret ?? null;
@endphp


<div x-data="{ open: false, connected: @js((bool)$fantraxSecret) }" class="space-y-2">
  {{-- Row --}}
  <button type="button"
          @click="open = !open"
          class="w-full group flex items-center gap-3 px-3 py-2 rounded-xl hover:bg-white/5">
    {{-- icon --}}
    <svg class="h-5 w-5 text-gray-300" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
      <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 6.75h15m-15 4.5h15m-15 4.5h15"/>
    </svg>
    <span class="text-sm">Fantrax</span>

    <div class="ml-auto flex items-center gap-2">
      <span x-show="connected" class="inline-flex items-center rounded-full bg-emerald-500/15 px-2 py-0.5 text-[11px] font-medium text-emerald-300">
        Connected
      </span>
      <span x-show="!connected" class="inline-flex items-center rounded-md bg-indigo-500/15 px-2 py-0.5 text-[11px] font-medium text-indigo-300">
        Connect
      </span>

      {{-- caret --}}
      <svg x-show="!open" class="h-4 w-4 text-gray-400" viewBox="0 0 20 20" fill="none" stroke="currentColor">
        <path d="M7 5l6 5-6 5" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
      </svg>
      <svg x-show="open" class="h-4 w-4 text-gray-400" viewBox="0 0 20 20" fill="none" stroke="currentColor">
        <path d="M5 7l5 6 5-6" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
      </svg>
    </div>
  </button>

  {{-- Inline form --}}
  <div x-data="{ saving:false, error:'' }" x-show="open" x-cloak class="ml-11 mr-3">
    <form @submit.prevent="
        saving=true; error='';
        fetch('{{ route('integrations.fantrax.save') }}', {
          method: 'POST',
          headers: {
            'X-CSRF-TOKEN': '{{ csrf_token() }}',
            'Accept': 'application/json'
          },
          body: new FormData($event.target)
        })
        .then(async r => {
          if (!r.ok) throw await r.json();
          return r.json();
        })
        .then(data => {
          connected = true;         // updates the badge
          open = false;             // collapse the inline form

          // 🔥 notify the rest of the app (e.g., navbar)
          window.dispatchEvent(new CustomEvent('fantrax:connected'));
        })
        .catch(e => {
          error = (e?.errors?.fantrax_secret_key?.[0]) ?? 'Unable to save key.';
        })
        .finally(() => saving=false);
      " class="flex items-end gap-2">

      @csrf
      <div class="flex-1">
        <div class="flex items-center justify-between mb-1" x-data="{ helpOpen:false }">
          <label class="block text-xs text-gray-400">Secret Key ID</label>
          

          {{-- modal remains as you have it --}}
        </div>

        <input name="fantrax_secret_key" type="password"
               placeholder="Enter your Fantrax Secret Key"
               value="{{ $fantraxSecret }}"
               class="w-full rounded-lg bg-[#0D1526] text-sm text-gray-100 placeholder-gray-500 border border-white/10 focus:border-indigo-500 focus:ring-indigo-500 px-3 py-2" />
        <p x-show="error" class="mt-1 text-[11px] text-red-300" x-text="error"></p>
      </div>

      <button type="submit"
              :disabled="saving"
              class="inline-flex items-center rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-50">
        <span x-show="!saving">Save</span>
        <span x-show="saving">Saving…</span>
      </button>
    </form>
</div>

</div>
