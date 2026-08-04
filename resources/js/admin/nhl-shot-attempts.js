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

document.addEventListener('DOMContentLoaded', mountNhlShotAttemptMatchupForms);
document.addEventListener('alpine:navigated', mountNhlShotAttemptMatchupForms);
