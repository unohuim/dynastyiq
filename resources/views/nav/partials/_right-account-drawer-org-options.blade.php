{{-- resources/views/nav/partials/right-account-drawer-org-options.blade.php --}}

@php
  $organization      = $organization ?? auth()->user()?->organizations()->first();
  $orgSettings       = $organization?->settings ?? null; // null => disabled
  $orgEnabledDefault = ! is_null($orgSettings);
@endphp

<div
  class="mb-6"
  x-data="orgSection({
      orgId: @js($organization?->id),
      enabledDefault: @js($orgEnabledDefault),
      nameDefault: @js($organization?->name ?? ''),
      commishDefault: @js(data_get($orgSettings, 'commissioner_tools', false)),
      creatorDefault: @js(data_get($orgSettings, 'creator_tools', false)),
      updateUrlBase: @js(route('organizations.settings.update', ['organization' => null])),
      updateUrlWithId: @js(route('organizations.settings.update', ['organization' => '__ORG__'])),
      csrf: @js(csrf_token()),
  })"
>
  <h3 class="px-3 mb-2 text-[10px] font-semibold tracking-wider text-gray-400 uppercase">
    Community
  </h3>

  {{-- Header row: enable + caret --}}
  <div class="flex items-center justify-between px-3 py-2 rounded-xl bg-white/5 ring-1 ring-white/10">
    <div class="flex items-center gap-3">
      {!! $ico('M12 4.5l7.5 4.5v6l-7.5 4.5L4.5 15v-6L12 4.5') !!}
      <span class="text-sm">Community Tools</span>
    </div>

    <div class="flex items-center gap-2">
      <button
        type="button"
        x-show="orgEnabled"
        x-on:click.stop="orgOpen = !orgOpen"
        class="p-1 rounded-md hover:bg-white/5 focus:outline-none"
      >
        <svg class="h-4 w-4 text-gray-400 transition-transform duration-200"
             :class="orgOpen ? 'rotate-90' : ''"
             viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
          <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
        </svg>
      </button>

      {{-- Community Tools toggle (emits immediately via $dispatch) --}}
      <label class="relative inline-flex items-center cursor-pointer">
        <input
          type="checkbox"
          class="sr-only peer"
          x-model="orgEnabled"
          @change="
            $dispatch('org:settings-updated', {
              organization_id: orgId,
              enabled: orgEnabled,
              settings: orgEnabled ? { commissioner_tools: commishEnabled, creator_tools: creatorEnabled } : {},
              actor_id: {{ auth()->id() ?? 'null' }},
              updated_at: new Date().toISOString(),
              optimistic: true
            })
          "
        />
        <span class="w-8 h-[18px] rounded-full bg-gray-600 peer-checked:bg-indigo-500 transition-colors duration-200"></span>
        <span class="absolute left-[2px] h-[14px] w-[14px] rounded-full bg-white shadow transition-transform duration-200 translate-x-0 peer-checked:translate-x-[14px]"></span>
      </label>
    </div>
  </div>

  {{-- Options (collapsible while enabled) --}}
  <div x-show="orgEnabled && orgOpen" x-transition class="mt-2 space-y-3">
    <div class="px-3">
      <label class="block text-xs text-gray-400 mb-1">Community Name</label>
      <input
        type="text"
        x-model="orgName"
        x-on:input.debounce.500ms="saveName()"
        placeholder="e.g. DynastyIQ Hockey Network"
        class="w-full bg-transparent/0 text-sm text-gray-100 placeholder-gray-500 border border-white/10 rounded-xl px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition"
      />
    </div>

    <div class="flex items-center justify-between px-3 py-2 rounded-xl hover:bg-white/5">
      <div class="flex items-center gap-3">
        {!! $ico('M4.5 6.75h15M6.75 12h12M6.75 17.25h9') !!}
        <span class="text-sm">Commissioner Tools</span>
      </div>
      <label class="relative inline-flex items-center cursor-pointer">
        <input type="checkbox" class="sr-only peer" x-model="commishEnabled" />
        <span class="w-8 h-[18px] rounded-full bg-gray-600 peer-checked:bg-indigo-500 transition-colors duration-200"></span>
        <span class="absolute left-[2px] h-[14px] w-[14px] rounded-full bg-white shadow transition-transform duration-200 translate-x-0 peer-checked:translate-x-[14px]"></span>
      </label>
    </div>

    <div class="flex items-center justify-between px-3 py-2 rounded-xl hover:bg-white/5">
      <div class="flex items-center gap-3">
        {!! $ico('M12 6v12m6-6H6') !!}
        <span class="text-sm">Creator Tools</span>
      </div>
      <label class="relative inline-flex items-center cursor-pointer">
        <input type="checkbox" class="sr-only peer" x-model="creatorEnabled" />
        <span class="w-8 h-[18px] rounded-full bg-gray-600 peer-checked:bg-indigo-500 transition-colors duration-200"></span>
        <span class="absolute left-[2px] h-[14px] w-[14px] rounded-full bg-white shadow transition-transform duration-200 translate-x-0 peer-checked:translate-x-[14px]"></span>
      </label>
    </div>
  </div>
</div>

<script>
  function orgSection(cfg) {
    return {
      // state
      orgId: cfg.orgId,
      orgEnabled: cfg.enabledDefault,
      orgOpen: false,
      orgName: cfg.nameDefault,
      seedName: cfg.nameDefault,
      commishEnabled: cfg.commishDefault,
      creatorEnabled: cfg.creatorDefault,
      updateUrlBase: cfg.updateUrlBase,
      updateUrlWithId: cfg.updateUrlWithId,
      csrf: cfg.csrf,
      saving: false,
      ignoreOrgWatch: false,
      ignoreCommishWatch: false,
      ignoreCreatorWatch: false,

      notify(type, message) {
        if (window.toast?.[type]) {
          window.toast[type](message);
        } else if (window.toast?.show) {
          window.toast.show(message, { type });
        }
      },

      // helpers
      async api(payload) {
        this.saving = true;
        const url = this.orgId
          ? this.updateUrlWithId.replace('__ORG__', this.orgId)
          : this.updateUrlBase;

        try {
          const res  = await fetch(url, {
            method: 'PUT',
            headers: {
              'Content-Type': 'application/json',
              'X-CSRF-TOKEN': this.csrf,
              'Accept': 'application/json',
            },
            body: JSON.stringify(payload),
          });

          const json = await res.json().catch(() => ({}));

          if (!res.ok) {
            throw new Error(json?.message || 'Request failed');
          }

          // Authoritative emit (post-save) via Alpine
          this.$dispatch('org:settings-updated', {
            organization_id: this.orgId ?? json?.organization?.id ?? null,
            enabled: json?.settings !== null,
            settings: json?.settings ?? {},
            actor_id: {{ auth()->id() ?? 'null' }},
            updated_at: (new Date()).toISOString(),
            optimistic: false
          });

          return json;
        } finally {
          this.saving = false;
        }
      },

      // lifecycle
      init() {
        this.$watch('orgEnabled', async (v, old) => {
          if (this.ignoreOrgWatch) {
            this.ignoreOrgWatch = false;
            return;
          }

          if (v && !this.orgName && this.seedName) this.orgName = this.seedName;
          if (!v) this.orgOpen = false;

          try {
            const resp = await this.api({
              enabled: v,
              name: this.orgName || null,
              organization_id: this.orgId,
              commissioner_tools: v ? !!this.commishEnabled : null,
              creator_tools: v ? !!this.creatorEnabled : null,
            });

            if (resp?.organization?.id) this.orgId = resp.organization.id;
            if (resp?.settings) {
              this.commishEnabled = !!resp.settings.commissioner_tools;
              this.creatorEnabled = !!resp.settings.creator_tools;
            }

            this.notify('success', v ? 'Community tools enabled.' : 'Community tools disabled.');
          } catch (err) {
            console.error(err);
            this.notify('error', 'Could not update community tools.');
            this.ignoreOrgWatch = true;
            this.orgEnabled = old;
          }
        });

        this.$watch('commishEnabled', async (v, old) => {
          if (this.ignoreCommishWatch) {
            this.ignoreCommishWatch = false;
            return;
          }
          if (!this.orgEnabled) return;

          try {
            await this.api({
              enabled: true,
              organization_id: this.orgId,
              commissioner_tools: !!v,
            });

            this.notify('success', v ? 'Commissioner tools enabled.' : 'Commissioner tools disabled.');
          } catch (err) {
            console.error(err);
            this.notify('error', 'Could not update commissioner tools.');
            this.ignoreCommishWatch = true;
            this.commishEnabled = old;
          }
        });

        this.$watch('creatorEnabled', async (v, old) => {
          if (this.ignoreCreatorWatch) {
            this.ignoreCreatorWatch = false;
            return;
          }
          if (!this.orgEnabled) return;

          try {
            await this.api({
              enabled: true,
              organization_id: this.orgId,
              creator_tools: !!v,
            });

            this.notify('success', v ? 'Creator tools enabled.' : 'Creator tools disabled.');
          } catch (err) {
            console.error(err);
            this.notify('error', 'Could not update creator tools.');
            this.ignoreCreatorWatch = true;
            this.creatorEnabled = old;
          }
        });
      },

      // actions
      saveName() {
        if (!this.orgEnabled) return;
        this.api({
          enabled: true,
          organization_id: this.orgId,
          name: this.orgName || null,
        });
      },
    };
  }

  // Echo → DOM bridge (kept as-is so server broadcasts still fan out)
  (function () {
    const orgId = {{ $organization?->id ?? 'null' }};
    if (window.Echo && orgId) {
      window.Echo.private('org.' + orgId)
        .listen('.org.settings.updated', (e) => {
          window.dispatchEvent(new CustomEvent('org:settings-updated', { detail: e }));
        });
    }
  })();
</script>
