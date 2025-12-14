<x-app-layout>
  <section class="relative bg-gray-900 min-h-screen py-16 sm:py-24 lg:py-32 overflow-hidden">
    <div class="max-w-7xl mx-auto px-6 lg:px-8 text-center">

      {{-- Integrated logo --}}
      <div class="relative mx-auto w-56 h-56 flex items-center justify-center">
        <div class="absolute inset-0 rounded-full bg-gradient-to-br from-gray-700/40 via-gray-800/40 to-gray-900/60 blur-xl"></div>
        <div class="relative w-48 h-48 rounded-full bg-gray-900/60 ring-1 ring-white/10 flex items-center justify-center">
          <img
            src="{{ asset('images/dynastyiq_logo.png') }}"
            alt="DynastyIQ"
            class="w-40 h-40 object-contain"
          >
        </div>
      </div>

      {{-- Launch notice --}}
      <div class="mt-6 flex justify-center">
        <span class="inline-flex items-center rounded-full bg-indigo-500/10 px-4 py-1 text-sm font-medium text-indigo-300 ring-1 ring-inset ring-indigo-500/20">
          Official Launch · February 2026
        </span>
      </div>

      <p class="mt-4 text-lg sm:text-xl text-gray-300 max-w-3xl mx-auto">
        Nerdy Tools for Fantasy Fools
      </p>

      {{-- Discord CTA --}}
      <div class="mt-6">
        <a
          href="{{ config('services.discord.invite') }}"
          target="_blank"
          class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-6 py-3 text-sm font-semibold text-white shadow-lg hover:bg-indigo-500 transition"
        >
          Join the DynastyIQ Discord
        </a>
      </div>

      {{-- Existing cards (unchanged copy) --}}
      <div class="mt-16 grid grid-cols-1 md:grid-cols-3 gap-8">
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
