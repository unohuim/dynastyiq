const mountNhlShotAttemptMatchupForms = () => {
    document.querySelectorAll('[data-nhl-matchup-form]').forEach((form) => {
        if (form.dataset.nhlMatchupMounted === '1') {
            return;
        }

        form.dataset.nhlMatchupMounted = '1';

        form.querySelectorAll('[data-nhl-matchup-refresh]').forEach((field) => {
            field.addEventListener('change', () => {
                const refreshScope = field.getAttribute('data-nhl-matchup-refresh');

                if (refreshScope === 'team-a' || refreshScope === 'all') {
                    const goalie = form.querySelector('[name="matchup_team_a_goalie_id"]');

                    if (goalie !== null) {
                        goalie.value = '';
                    }
                }

                if (refreshScope === 'team-b' || refreshScope === 'all') {
                    const goalie = form.querySelector('[name="matchup_team_b_goalie_id"]');

                    if (goalie !== null) {
                        goalie.value = '';
                    }
                }

                if (form.requestSubmit instanceof Function) {
                    form.requestSubmit();

                    return;
                }

                form.submit();
            });
        });
    });
};

const sectionElements = (section) => ({
    toggle: section.querySelector('[data-context-sat-section-toggle]'),
    panel: section.querySelector('[data-context-sat-section-panel]'),
    status: section.querySelector('[data-context-sat-section-status]'),
    content: section.querySelector('[data-context-sat-section-content]'),
    icon: section.querySelector('[data-context-sat-section-icon]'),
});

const setContextSatSectionOpen = (section, open) => {
    const { toggle, panel, icon } = sectionElements(section);

    toggle?.setAttribute('aria-expanded', open ? 'true' : 'false');
    panel?.classList.toggle('hidden', !open);

    if (icon !== null) {
        icon.textContent = open ? '-' : '+';
    }
};

const loadContextSatSection = async (section, url = null) => {
    const { status, content } = sectionElements(section);
    const targetUrl = url ?? section.getAttribute('data-url');

    if (!targetUrl || content === null) {
        return;
    }

    section.dataset.loading = '1';

    if (status !== null) {
        status.classList.remove('hidden');
        status.textContent = 'Loading...';
    }

    try {
        const response = await fetch(targetUrl, {
            headers: {
                Accept: 'text/html',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });

        if (!response.ok) {
            throw new Error(`Request failed with status ${response.status}`);
        }

        content.innerHTML = await response.text();
        section.dataset.loaded = '1';
        section.setAttribute('data-url', targetUrl);
        mountContextSatAccordions();

        if (status !== null) {
            status.classList.add('hidden');
            status.textContent = '';
        }
    } catch (error) {
        if (status !== null) {
            status.classList.remove('hidden');
            status.textContent = 'Unable to load this section. Refresh and try again.';
        }
    } finally {
        section.dataset.loading = '0';
    }
};

const mountContextSatAccordions = () => {
    document.querySelectorAll('[data-context-sat-section]').forEach((section) => {
        if (section.dataset.contextSatMounted === '1') {
            return;
        }

        section.dataset.contextSatMounted = '1';
        setContextSatSectionOpen(section, false);

        sectionElements(section).toggle?.addEventListener('click', () => {
            const isOpen = sectionElements(section).toggle?.getAttribute('aria-expanded') === 'true';
            const nextOpen = !isOpen;

            setContextSatSectionOpen(section, nextOpen);

            if (nextOpen && section.dataset.loaded !== '1' && section.dataset.loading !== '1') {
                loadContextSatSection(section);
            }
        });

        section.addEventListener('click', (event) => {
            const link = event.target instanceof Element
                ? event.target.closest('[data-context-sat-section-link]')
                : null;

            if (!(link instanceof HTMLAnchorElement)) {
                return;
            }

            event.preventDefault();
            loadContextSatSection(section, link.href);
        });
    });
};

document.addEventListener('DOMContentLoaded', mountNhlShotAttemptMatchupForms);
document.addEventListener('DOMContentLoaded', mountContextSatAccordions);
document.addEventListener('alpine:navigated', mountNhlShotAttemptMatchupForms);
document.addEventListener('alpine:navigated', mountContextSatAccordions);
