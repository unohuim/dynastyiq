<x-app-layout>
  <div class="max-w-xl mx-auto p-6">
    <h2 class="text-xl font-semibold mb-4">Choose Discord Server(s)</h2>

    <form method="POST" action="{{ route('discord-server.attach') }}" class="space-y-3">
      @csrf

      <div class="space-y-2">
        @foreach ($guilds as $g)
          <label class="flex items-center gap-3 p-3 border rounded-lg cursor-pointer hover:bg-slate-50">
            <input type="checkbox" name="guild_ids[]" value="{{ $g['id'] }}">
            <span class="font-medium">{{ $g['name'] }}</span>
          </label>
        @endforeach
      </div>

      <div class="pt-3">
        <button class="rounded-lg bg-indigo-600 text-white px-4 py-2">Connect selected</button>
        <a href="{{ route('communities.index') }}" class="ml-3 text-slate-600">Cancel</a>
      </div>
    </form>
  </div>
</x-app-layout>
