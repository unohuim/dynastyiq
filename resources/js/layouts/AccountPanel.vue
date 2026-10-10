<script setup>
import { reactive, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import axios from 'axios';
import ShellDisclosure from './ShellDisclosure.vue';

const props = defineProps({ shell: Object });
const emit = defineEmits(['notify']);
const busy = ref(false);
const error = ref('');
const secret = ref('');
const preferences = reactive({});
const community = reactive({});
watch(() => props.shell, shell => {
    Object.assign(preferences, shell.notifications);
    Object.assign(community, shell.organization);
}, { immediate: true });

/** Existing endpoints own validation/authorization; reload props to reconcile saved state. */
async function mutate(action, success) {
    if (busy.value) return;
    busy.value = true;
    error.value = '';
    try {
        await action();
        emit('notify', { type: 'success', message: success });
        router.reload({ only: ['shell'], preserveScroll: true });
    } catch (exception) {
        const data = exception.response?.data;
        error.value = Object.values(data?.errors ?? {}).flat()[0] ?? data?.error ?? data?.message ?? 'Could not save. Please try again.';
        emit('notify', { type: 'error', message: error.value });
        Object.assign(preferences, props.shell.notifications);
        Object.assign(community, props.shell.organization);
        router.reload({ only: ['shell'], preserveScroll: true });
    } finally {
        busy.value = false;
    }
}

async function connectFantrax() {
    await mutate(async () => {
        await axios.post(props.shell.fantrax_url, { fantrax_secret_key: secret.value });
        secret.value = '';
    }, 'Fantrax connection saved.');
}

function savePreference(key, value) {
    return mutate(async () => {
        await axios.put(props.shell.preferences_url, { key: `notifications.discord.${key}`, value });
        preferences[key] = value;
        // Disabling private-channel delivery clears its override, preserving the existing contract.
        if (key === 'channel') {
            await axios.put(props.shell.preferences_url, {
                key: 'notifications.discord.channel-name', value: value ? (preferences['channel-name'] || null) : null,
            });
        }
    }, 'Notification preferences saved.');
}

function saveCommunity(changes) {
    return mutate(async () => {
        const response = await axios.put(community.url, {
            enabled: community.enabled,
            ...changes,
        });
        community.id = response.data.organization.id;
        community.name = response.data.organization.name;
        community.enabled = response.data.settings !== null;
        community.commissioner_tools = !!response.data.settings?.commissioner_tools;
        community.creator_tools = !!response.data.settings?.creator_tools;
    }, 'Community settings saved.');
}
</script>

<template>
    <div class="space-y-6">
        <p v-if="error" role="alert" class="rounded-lg bg-red-50 p-3 text-sm text-red-800">{{ error }}</p>
        <section aria-label="Integrations" class="space-y-1">
            <h3 class="px-3 text-xs font-semibold uppercase tracking-wide text-gray-500">Integrations</h3>
            <ShellDisclosure :label="`Fantrax · ${shell.fantasy.fantrax?.connected ? 'Connected' : 'Connect'}`">
                <form class="space-y-3" @submit.prevent="connectFantrax">
                    <label class="block text-sm">Secret Key ID<input v-model="secret" type="password" required maxlength="255" autocomplete="off" :disabled="busy" placeholder="Enter a new Fantrax secret key" class="mt-2 w-full rounded-lg border-gray-300 text-sm" /></label>
                    <p class="text-xs text-gray-500">Saved secrets are never sent back to this menu.</p>
                    <button :disabled="busy" class="rounded-lg bg-indigo-600 px-3 py-2 text-sm font-medium text-white disabled:opacity-50">{{ busy ? 'Saving…' : 'Save connection' }}</button>
                </form>
            </ShellDisclosure>
            <p v-if="shell.fantasy.yahoo?.connected" class="px-3 py-3 text-sm">Yahoo <span class="float-right text-emerald-700">Connected</span></p>
            <a v-else :href="shell.yahoo_url" class="block rounded-lg px-3 py-3 text-sm hover:bg-gray-100">Yahoo <span class="float-right text-indigo-700">Connect</span></a>
            <a :href="shell.discord_url" target="_blank" rel="noopener noreferrer" class="block rounded-lg px-3 py-3 text-sm hover:bg-gray-100">Our Discord <span class="float-right text-indigo-700">{{ shell.discord_connected ? 'Connected' : 'Join' }}</span></a>
        </section>
        <section aria-label="User settings">
            <h3 class="px-3 text-xs font-semibold uppercase tracking-wide text-gray-500">User settings</h3>
            <ShellDisclosure label="Notifications">
                <div v-for="[key, label] in [['dm', 'DIQ Bot response to DMs'], ['channel', 'DIQ Bot response to private channel']]" :key="key" class="flex items-center justify-between gap-4">
                    <span class="text-sm">{{ label }}</span>
                    <button type="button" role="switch" :aria-label="label" :aria-checked="!!preferences[key]" :disabled="busy" class="inline-flex h-6 w-11 shrink-0 items-center rounded-full p-1 transition-colors duration-200 disabled:opacity-50 motion-reduce:transition-none" :class="preferences[key] ? 'bg-indigo-600' : 'bg-gray-300'" @click="savePreference(key, !preferences[key])"><span class="h-4 w-4 rounded-full bg-white transition-transform duration-200 motion-reduce:transition-none" :class="preferences[key] ? 'translate-x-5' : 'translate-x-0'"></span></button>
                </div>
                <label v-if="preferences.channel" class="block text-sm">Private channel<input v-model="preferences['channel-name']" :disabled="busy" class="mt-2 w-full rounded-lg border-gray-300 text-sm" placeholder="e.g. stats-bot" @change="savePreference('channel-name', preferences['channel-name'] || null)" /></label>
            </ShellDisclosure>
            <p class="px-3 py-2 text-xs text-gray-500">Theme and timezone settings are not available yet.</p>
        </section>
        <section aria-label="Community settings" class="space-y-3">
            <h3 class="px-3 text-xs font-semibold uppercase tracking-wide text-gray-500">Community</h3>
            <div class="flex items-center justify-between gap-4 px-3"><span class="text-sm font-medium">Community Tools</span><button type="button" role="switch" aria-label="Community Tools" :aria-checked="!!community.enabled" :disabled="busy" class="inline-flex h-6 w-11 items-center rounded-full p-1 transition-colors duration-200 disabled:opacity-50 motion-reduce:transition-none" :class="community.enabled ? 'bg-indigo-600' : 'bg-gray-300'" @click="saveCommunity({ enabled: !community.enabled, name: community.name || null, commissioner_tools: community.commissioner_tools, creator_tools: community.creator_tools })"><span class="h-4 w-4 rounded-full bg-white transition-transform duration-200 motion-reduce:transition-none" :class="community.enabled ? 'translate-x-5' : 'translate-x-0'"></span></button></div>
            <ShellDisclosure v-if="community.enabled" label="Community options">
                <label class="block text-sm">Community name<input v-model="community.name" :disabled="busy" minlength="2" maxlength="120" class="mt-2 w-full rounded-lg border-gray-300 text-sm" @change="saveCommunity({ name: community.name })" /></label>
                <div v-for="[key, label] in [['commissioner_tools', 'Commissioner Tools'], ['creator_tools', 'Creator Tools']]" :key="key" class="flex items-center justify-between gap-4"><span class="text-sm">{{ label }}</span><button type="button" role="switch" :aria-label="label" :aria-checked="!!community[key]" :disabled="busy" class="inline-flex h-6 w-11 items-center rounded-full p-1 transition-colors duration-200 disabled:opacity-50 motion-reduce:transition-none" :class="community[key] ? 'bg-indigo-600' : 'bg-gray-300'" @click="saveCommunity({ [key]: !community[key] })"><span class="h-4 w-4 rounded-full bg-white transition-transform duration-200 motion-reduce:transition-none" :class="community[key] ? 'translate-x-5' : 'translate-x-0'"></span></button></div>
            </ShellDisclosure>
        </section>
        <section v-if="shell.admin.length" aria-label="Administration" class="space-y-1"><h3 class="px-3 text-xs font-semibold uppercase tracking-wide text-gray-500">Admin</h3><a v-for="link in shell.admin" :key="link.href" :href="link.href" :aria-current="link.active ? 'page' : undefined" class="block rounded-lg px-3 py-3 text-sm hover:bg-gray-100" :class="link.active ? 'font-semibold text-indigo-700' : 'text-gray-700'">{{ link.label }}</a></section>
        <section aria-label="Account" class="space-y-1 border-t border-gray-200 pt-4"><a :href="shell.profile_url" class="block rounded-lg px-3 py-3 text-sm hover:bg-gray-100">View profile</a><form method="post" :action="shell.logout_url"><input type="hidden" name="_token" :value="shell.csrf" /><button class="w-full rounded-lg px-3 py-3 text-left text-sm text-red-700 hover:bg-red-50">Sign out</button></form></section>
    </div>
</template>
