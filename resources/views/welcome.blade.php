<x-app-layout>
  <section class="bg-gray-900 min-h-screen py-16 sm:py-24 lg:py-32">
    <div class="max-w-7xl mx-auto px-6 lg:px-8 text-center">
      

      //logo
      <div class="mx-auto w-56 h-56 rounded-full p-6 bg-gray-800 flex items-center justify-center overflow-hidden">
        <img src="{{ asset('images/dynastyiq_logo.png') }}" alt="DynastyIQ" class="w-full h-full object-contain">
      </div>



      <p class="mt-4 text-lg sm:text-xl text-gray-300 max-w-3xl mx-auto">
        Nerdy Tools for Fantasy Fools
      </p>

      <div class="mt-16 grid grid-cols-1 md:grid-cols-3 gap-8">
        <!-- Manager -->
        <div class="rounded-xl shadow-xl bg-gradient-to-br from-indigo-600 to-indigo-500 px-6 py-8">
          <h3 class="text-2xl font-semibold text-white">For Managers</h3>
          <p class="mt-2 text-indigo-100">Gain powerful insights, view advanced stats, and use leading indicators for your specific leagues.  Identify future stars currently undervalued.  Fantrax integration allows you to use strength asssessments accross all categories.</p>
        </div>

        <!-- Commissioner -->
        <div class="rounded-xl shadow-xl bg-gradient-to-br from-indigo-600 to-indigo-500 px-6 py-8">
          <h3 class="text-2xl font-semibold text-white">For Commissioners</h3>
          <p class="mt-2 text-indigo-100">Try easy mode.  Manage recruitment & onboarding, discord server communications & notifications, and provide your community with discord commands to access critical league and statistical information.</p>
        </div>

        <!-- Creator -->
        <div class="rounded-xl shadow-xl bg-gradient-to-br from-indigo-600 to-indigo-500 px-6 py-8">
          <h3 class="text-2xl font-semibold text-white">For Creators</h3>
          <p class="mt-2 text-indigo-100">Produce engaging fantasy content for your fans - like rankings, projections and reports, and manage paywalls for value IP.  Embed these protected digital assets directly in your website with ease.</p>
        </div>
      </div>
    </div>
  </section>
</x-app-layout>
