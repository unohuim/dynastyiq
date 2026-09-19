const dateTimeOptions = {
    weekday: 'short',
    year: 'numeric',
    month: 'long',
    day: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
    timeZoneName: 'short',
};

export function formatLocalDateTime(value, locale, timeZone) {
    if (!value) {
        return '';
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return '';
    }

    return new Intl.DateTimeFormat(locale, {
        ...dateTimeOptions,
        ...(timeZone ? { timeZone } : {}),
    }).format(date);
}

export function localizeDateTimes(root = document) {
    root.querySelectorAll('[data-local-datetime]').forEach((element) => {
        const formatted = formatLocalDateTime(element.getAttribute('datetime'));

        if (formatted) {
            element.textContent = formatted;
        }
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => localizeDateTimes(), { once: true });
} else {
    localizeDateTimes();
}
