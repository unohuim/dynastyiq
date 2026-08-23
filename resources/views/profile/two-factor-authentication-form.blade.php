<x-action-section>
    <x-slot name="title">
        {{ __('Two Factor Authentication') }}
    </x-slot>

    <x-slot name="description">
        {{ __('Add additional security to your account using two factor authentication.') }}
    </x-slot>

    <x-slot name="content">
        <h3 class="text-lg font-medium text-gray-900">
            @if ($this->enabled)
                @if ($showingConfirmation)
                    {{ __('Finish enabling two factor authentication.') }}
                @else
                    {{ __('You have enabled two factor authentication.') }}
                @endif
            @else
                {{ __('You have not enabled two factor authentication.') }}
            @endif
        </h3>

        <div class="mt-3 max-w-xl text-sm text-gray-600">
            <p>
                {{ __('When two factor authentication is enabled, you will be prompted for a secure, random token during authentication. You may retrieve this token from your phone\'s Google Authenticator application.') }}
            </p>
        </div>

        @if ($this->enabled)
            @if ($showingQrCode)
                <div class="mt-4 max-w-xl text-sm text-gray-600">
                    <p class="font-semibold">
                        @if ($showingConfirmation)
                            {{ __('To finish enabling two factor authentication, scan the following QR code using your phone\'s authenticator application or enter the setup key and provide the generated OTP code.') }}
                        @else
                            {{ __('Two factor authentication is now enabled. Scan the following QR code using your phone\'s authenticator application or enter the setup key.') }}
                        @endif
                    </p>
                </div>

                <div class="mt-4 p-2 inline-block bg-white">
                    {!! $this->user->twoFactorQrCodeSvg() !!}
                </div>

                <div class="mt-4 max-w-xl text-sm text-gray-600">
                    <p class="font-semibold">
                        {{ __('Setup Key') }}: {{ decrypt($this->user->two_factor_secret) }}
                    </p>
                </div>

                @if ($showingConfirmation)
                    <div class="mt-4">
                        <label class="block text-sm font-medium text-gray-700" for="code">
                            {{ __('Code') }}
                        </label>

                        <input id="code" type="text" name="code" class="mt-1 block w-1/2 rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500" inputmode="numeric" autofocus autocomplete="one-time-code"
                            wire:model="code"
                            wire:keydown.enter="confirmTwoFactorAuthentication" />

                        @error('code')
                            <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>
                @endif
            @endif

            @if ($showingRecoveryCodes)
                <div class="mt-4 max-w-xl text-sm text-gray-600">
                    <p class="font-semibold">
                        {{ __('Store these recovery codes in a secure password manager. They can be used to recover access to your account if your two factor authentication device is lost.') }}
                    </p>
                </div>

                <div class="grid gap-1 max-w-xl mt-4 px-4 py-4 font-mono text-sm bg-gray-100 rounded-lg">
                    @foreach (json_decode(decrypt($this->user->two_factor_recovery_codes), true) as $code)
                        <div>{{ $code }}</div>
                    @endforeach
                </div>
            @endif
        @endif

        <div class="mt-5">
            @if (! $this->enabled)
                <span
                    wire:then="enableTwoFactorAuthentication"
                    x-data
                    x-ref="confirmableEnableTwoFactorAuthentication"
                    x-on:click="$wire.startConfirmingPassword('{{ md5('enableTwoFactorAuthentication') }}')"
                    x-on:password-confirmed.window="setTimeout(() => $event.detail.id === '{{ md5('enableTwoFactorAuthentication') }}' && $refs.confirmableEnableTwoFactorAuthentication.dispatchEvent(new CustomEvent('then', { bubbles: false })), 250);"
                >
                    <button type="button" wire:loading.attr="disabled" class="inline-flex items-center rounded-md border border-transparent bg-gray-800 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-white transition duration-150 ease-in-out hover:bg-gray-700 focus:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 active:bg-gray-900 disabled:opacity-50">
                        {{ __('Enable') }}
                    </button>
                </span>
            @else
                @if ($showingRecoveryCodes)
                    <span
                        wire:then="regenerateRecoveryCodes"
                        x-data
                        x-ref="confirmableRegenerateRecoveryCodes"
                        x-on:click="$wire.startConfirmingPassword('{{ md5('regenerateRecoveryCodes') }}')"
                        x-on:password-confirmed.window="setTimeout(() => $event.detail.id === '{{ md5('regenerateRecoveryCodes') }}' && $refs.confirmableRegenerateRecoveryCodes.dispatchEvent(new CustomEvent('then', { bubbles: false })), 250);"
                    >
                        <button type="button" class="me-3 inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-xs font-semibold uppercase tracking-widest text-gray-700 shadow-sm transition duration-150 ease-in-out hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 disabled:opacity-25">
                            {{ __('Regenerate Recovery Codes') }}
                        </button>
                    </span>
                @elseif ($showingConfirmation)
                    <span
                        wire:then="confirmTwoFactorAuthentication"
                        x-data
                        x-ref="confirmableConfirmTwoFactorAuthentication"
                        x-on:click="$wire.startConfirmingPassword('{{ md5('confirmTwoFactorAuthentication') }}')"
                        x-on:password-confirmed.window="setTimeout(() => $event.detail.id === '{{ md5('confirmTwoFactorAuthentication') }}' && $refs.confirmableConfirmTwoFactorAuthentication.dispatchEvent(new CustomEvent('then', { bubbles: false })), 250);"
                    >
                        <button type="button" class="me-3 inline-flex items-center rounded-md border border-transparent bg-gray-800 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-white transition duration-150 ease-in-out hover:bg-gray-700 focus:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 active:bg-gray-900 disabled:opacity-50" wire:loading.attr="disabled">
                            {{ __('Confirm') }}
                        </button>
                    </span>
                @else
                    <span
                        wire:then="showRecoveryCodes"
                        x-data
                        x-ref="confirmableShowRecoveryCodes"
                        x-on:click="$wire.startConfirmingPassword('{{ md5('showRecoveryCodes') }}')"
                        x-on:password-confirmed.window="setTimeout(() => $event.detail.id === '{{ md5('showRecoveryCodes') }}' && $refs.confirmableShowRecoveryCodes.dispatchEvent(new CustomEvent('then', { bubbles: false })), 250);"
                    >
                        <button type="button" class="me-3 inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-xs font-semibold uppercase tracking-widest text-gray-700 shadow-sm transition duration-150 ease-in-out hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 disabled:opacity-25">
                            {{ __('Show Recovery Codes') }}
                        </button>
                    </span>
                @endif

                @if ($showingConfirmation)
                    <span
                        wire:then="disableTwoFactorAuthentication"
                        x-data
                        x-ref="confirmableCancelTwoFactorAuthentication"
                        x-on:click="$wire.startConfirmingPassword('{{ md5('disableTwoFactorAuthentication') }}')"
                        x-on:password-confirmed.window="setTimeout(() => $event.detail.id === '{{ md5('disableTwoFactorAuthentication') }}' && $refs.confirmableCancelTwoFactorAuthentication.dispatchEvent(new CustomEvent('then', { bubbles: false })), 250);"
                    >
                        <button type="button" wire:loading.attr="disabled" class="inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-xs font-semibold uppercase tracking-widest text-gray-700 shadow-sm transition duration-150 ease-in-out hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 disabled:opacity-25">
                            {{ __('Cancel') }}
                        </button>
                    </span>
                @else
                    <span
                        wire:then="disableTwoFactorAuthentication"
                        x-data
                        x-ref="confirmableDisableTwoFactorAuthentication"
                        x-on:click="$wire.startConfirmingPassword('{{ md5('disableTwoFactorAuthentication') }}')"
                        x-on:password-confirmed.window="setTimeout(() => $event.detail.id === '{{ md5('disableTwoFactorAuthentication') }}' && $refs.confirmableDisableTwoFactorAuthentication.dispatchEvent(new CustomEvent('then', { bubbles: false })), 250);"
                    >
                        <button type="button" wire:loading.attr="disabled" class="inline-flex items-center justify-center rounded-md border border-transparent bg-red-600 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-white transition duration-150 ease-in-out hover:bg-red-500 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2 active:bg-red-700">
                            {{ __('Disable') }}
                        </button>
                    </span>
                @endif

            @endif
        </div>

        <div
            x-data="{ show: @entangle('confirmingPassword').live }"
            x-on:close.stop="show = false"
            x-on:keydown.escape.window="show = false"
            x-show="show"
            class="jetstream-modal fixed inset-0 z-50 overflow-y-auto px-4 py-6 sm:px-0"
            style="display: none;"
        >
            <div
                x-show="show"
                class="fixed inset-0 transform transition-all"
                x-on:click="show = false"
                x-transition:enter="ease-out duration-300"
                x-transition:enter-start="opacity-0"
                x-transition:enter-end="opacity-100"
                x-transition:leave="ease-in duration-200"
                x-transition:leave-start="opacity-100"
                x-transition:leave-end="opacity-0"
            >
                <div class="absolute inset-0 bg-gray-500 opacity-75"></div>
            </div>

            <div
                x-show="show"
                class="mb-6 transform overflow-hidden rounded-lg bg-white shadow-xl transition-all sm:mx-auto sm:w-full sm:max-w-2xl"
                x-trap.inert.noscroll="show"
                x-transition:enter="ease-out duration-300"
                x-transition:enter-start="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
                x-transition:leave="ease-in duration-200"
                x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
                x-transition:leave-end="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
            >
                <div class="px-6 py-4">
                    <div class="text-lg font-medium text-gray-900">
                        {{ __('Confirm Password') }}
                    </div>

                    <div class="mt-4 text-sm text-gray-600">
                        {{ __('For your security, please confirm your password to continue.') }}

                        <div class="mt-4" x-data="{}" x-on:confirming-password.window="setTimeout(() => $refs.confirmable_password.focus(), 250)">
                            <input type="password" class="mt-1 block w-3/4 rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500" placeholder="{{ __('Password') }}" autocomplete="current-password"
                                        x-ref="confirmable_password"
                                        wire:model="confirmablePassword"
                                        wire:keydown.enter="confirmPassword" />

                            @error('confirmable_password')
                                <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>
                </div>

                <div class="flex flex-row justify-end bg-gray-100 px-6 py-4 text-end">
                    <button type="button" wire:click="stopConfirmingPassword" wire:loading.attr="disabled" class="inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-xs font-semibold uppercase tracking-widest text-gray-700 shadow-sm transition duration-150 ease-in-out hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 disabled:opacity-25">
                        {{ __('Cancel') }}
                    </button>

                    <button type="button" class="ms-3 inline-flex items-center rounded-md border border-transparent bg-gray-800 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-white transition duration-150 ease-in-out hover:bg-gray-700 focus:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 active:bg-gray-900 disabled:opacity-50" dusk="confirm-password-button" wire:click="confirmPassword" wire:loading.attr="disabled">
                        {{ __('Confirm') }}
                    </button>
                </div>
            </div>
        </div>
    </x-slot>
</x-action-section>
