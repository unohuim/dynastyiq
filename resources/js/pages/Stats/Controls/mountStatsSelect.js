import { createApp, h } from 'vue';
import StatsSelect from './StatsSelect.vue';

const selectApps = new WeakMap();

export const mountStatsSelect = ({
  options = [],
  modelValue = '',
  onChange = () => {},
  placeholder = 'Select',
  ariaLabel = 'Select option',
  triggerClass = '',
} = {}) => {
  const mount = document.createElement('div');
  const app = createApp({
    render() {
      return h(StatsSelect, {
        options,
        modelValue,
        placeholder,
        ariaLabel,
        triggerClass,
        onChange,
      });
    },
  });

  app.mount(mount);
  selectApps.set(mount, app);

  const observer = new MutationObserver(() => {
    if (!document.body.contains(mount)) {
      selectApps.get(mount)?.unmount();
      selectApps.delete(mount);
      observer.disconnect();
    }
  });

  observer.observe(document.body, { childList: true, subtree: true });

  return mount;
};
