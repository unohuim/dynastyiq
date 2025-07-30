<div
  x-data="$store.playerStats"
  x-init="window.__playerStats = @js($statsJson); $store.playerStats.init($el)"
  class="relative pb-24"
>


  {{-- 📱 Mobile --}}
  <div class="sm:hidden">
    @include('livewire.partials.stats-table-mobile')
  </div>

  {{-- 💻 Desktop --}}
  <div class="hidden sm:block">
    @include('livewire.partials.stats-table-desktop')
  </div>


  @push('scripts')
  <script>
    
  </script>
  @endpush


</div>
