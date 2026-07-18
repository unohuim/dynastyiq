function notifyOpener() {
    const root = document.querySelector("[data-discord-bot-installed-callback]");

    if (!root) {
        return;
    }

    const organizationId = Number(root.dataset.organizationId || 0);
    const discordServerId = Number(root.dataset.discordServerId || 0);

    if (window.opener && !window.opener.closed) {
        window.opener.postMessage(
            {
                type: "diq:discord-bot-installed",
                organizationId,
                discordServerId,
            },
            window.location.origin
        );
    }

    window.setTimeout(() => {
        window.close();
    }, 250);
}

if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", notifyOpener, { once: true });
} else {
    notifyOpener();
}
