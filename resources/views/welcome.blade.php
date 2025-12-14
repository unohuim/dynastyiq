<x-app-layout>
  <section class="relative bg-gray-900 min-h-screen py-24 overflow-hidden">
    <div class="relative max-w-7xl mx-auto px-6 text-center">

      {{-- Ambient background glow --}}
      <div class="absolute inset-0 flex justify-center">
        <div class="w-[520px] h-[520px] rounded-full
                    bg-[radial-gradient(circle_at_center,rgba(99,102,241,0.15),transparent_65%)]
                    blur-2xl">
        </div>
      </div>

      {{-- Logo --}}
      <div class="relative z-10 mx-auto w-44 h-44 flex items-center justify-center">
        <img
          src="{{ asset('images/dynastyiq_logo.png') }}"
          alt="DynastyIQ"
          class="w-full h-full object-contain drop-shadow-[0_12px_30px_rgba(0,0,0,0.6)]"
        />
      </div>

      {{-- Launch badge --}}
      <div class="relative z-10 mt-6 flex justify-center">
        <span
          class="inline-flex items-center rounded-full
                 bg-indigo-500/10 px-4 py-1.5 text-sm font-medium text-indigo-300
                 ring-1 ring-indigo-400/20 backdrop-blur">
          Official Launch · February 2026
        </span>
      </div>

      <p class="relative z-10 mt-4 text-lg sm:text-xl text-gray-300">
        Nerdy Tools for Fantasy Fools
      </p>

      {{-- Discord CTA --}}
      <div class="relative z-10 mt-8">
        <a
          href="{{ config('services.discord.invite') }}"
          target="_blank"
          class="inline-flex items-center gap-3
                 rounded-xl px-7 py-3.5
                 bg-gradient-to-br from-indigo-500 to-indigo-600
                 text-white font-semibold
                 shadow-lg shadow-indigo-500/30
                 hover:from-indigo-400 hover:to-indigo-500
                 hover:shadow-indigo-400/40
                 transition-all"
        >
          {{-- Discord icon --}}
          <svg class="w-5 h-5" viewBox="0 0 245 240" fill="currentColor" aria-hidden="true">
            <path d="M104.4 104.9c-5.7 0-10.2 5-10.2 11.1s4.6 11.1 10.2 11.1c5.7 0 10.3-5 10.2-11.1 0-6.1-4.6-11.1-10.2-11.1zm36.2 0c-5.7 0-10.2 5-10.2 11.1s4.6 11.1 10.2 11.1c5.7 0 10.3-5 10.2-11.1 0-6.1-4.5-11.1-10.2-11.1z"/>
            <path d="M189.5 20h-134C24.8 20 20 24.8 20 30.5v134c0 5.7 4.8 10.5 10.5 10.5h113.2l-5.3-18.5 12.8 11.9 12.1 11.1L225 220V30.5c0-5.7-4.8-10.5-10.5-10.5z"/>
          </svg>

          Join Our Discord
        </a>
      </div>

      {{-- Existing cards --}}
      <div class="relative z-10 mt-20 grid grid-cols-1 md:grid-cols-3 gap-8">
        <div class="rounded-xl shadow-xl bg-gradient-to-br from-indigo-600 to-indigo-500 px-6 py-8">
          <h3 class="text-2xl font-semibold text-white">For Managers</h3>
          <p class="mt-2 text-indigo-100">
            Gain powerful insights, view advanced stats, and use leading indicators for your specific leagues.
            Identify future stars currently undervalued. Fantrax integration allows you to use strength
            asssessments accross all categories.
          </p>
        </div>

        <div class="rounded-xl shadow-xl bg-gradient-to-br from-indigo-600 to-indigo-500 px-6 py-8">
          <h3 class="text-2xl font-semibold text-white">For Commissioners</h3>
          <p class="mt-2 text-indigo-100">
            Try easy mode. Manage recruitment & onboarding, discord server communications & notifications,
            and provide your community with discord commands to access critical league and statistical information.
          </p>
        </div>

        <div class="rounded-xl shadow-xl bg-gradient-to-br from-indigo-600 to-indigo-500 px-6 py-8">
          <h3 class="text-2xl font-semibold text-white">For Creators</h3>
          <p class="mt-2 text-indigo-100">
            Produce engaging fantasy content for your fans - like rankings, projections and reports, and manage
            paywalls for value IP. Embed these protected digital assets directly in your website with ease.
          </p>
        </div>
      </div>

    </div>
  </section>
</x-app-layout>
