<x-guest-layout>
    <div
        class="mx-auto flex min-h-screen max-w-md flex-col items-center justify-center px-6 py-12 text-center"
        data-discord-bot-installed-callback
        data-organization-id="{{ $organizationId }}"
        data-discord-server-id="{{ $discordServerId }}"
    >
        <h1 class="text-xl font-semibold text-slate-900">DIQ bot installed</h1>
        <p class="mt-2 text-sm text-slate-600">
            DynastyIQ is updating the community connection. You can close this tab if it stays open.
        </p>
    </div>
</x-guest-layout>
