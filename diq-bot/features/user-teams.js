// features/user-teams.js
const {
    REST,
    Routes,
    ContextMenuCommandBuilder,
    ApplicationCommandType,
    MessageFlags,
} = require('discord.js');
const axios = require('axios');
const fs = require('fs');
const path = require('path');
const Handlebars = require('handlebars');

const COMMAND_NAME = 'DIQ: User Teams';

function getApiBase() {
    return process.env.API_BASE_URL || process.env.DIQ_API_BASE_URL || process.env.APP_URL || '';
}

async function register({
    token,
    clientId,
    guildId
}) {
    if (!token || !clientId) throw new Error('register: token and clientId are required');

    const rest = new REST({
        version: '10'
    }).setToken(token);

    const builder = new ContextMenuCommandBuilder()
        .setName(COMMAND_NAME)
        .setType(ApplicationCommandType.User)
        .setDefaultMemberPermissions(null);

    const cmdJson = {
        ...builder.toJSON(),
        integration_types: [0], // Guild Install
        contexts: [0], // Guild context (user right-click)
    };

    if (guildId) {
        await rest.put(Routes.applicationGuildCommands(clientId, guildId), {
            body: [cmdJson]
        });
    } else {
        await rest.put(Routes.applicationCommands(clientId), {
            body: [cmdJson]
        });
    }
}

async function fetchUserTeams(targetDiscordId, viewerDiscordId) {
    const API_BASE = getApiBase();
    if (!API_BASE) throw new Error('Missing APP_URL/API_BASE_URL');

    const url = new URL(`/api/discord/users/${targetDiscordId}`, API_BASE);
    if (viewerDiscordId) url.searchParams.set('viewer_discord_id', String(viewerDiscordId));

    const resp = await axios.get(url.toString(), {
        timeout: 8000,
        headers: {
            'User-Agent': 'diq-bot'
        },
        validateStatus: s => s >= 200 && s < 500,
    });

    if (resp.status >= 400) return {
        teams: [],
        not_linked: true
    };
    return resp.data || {
        teams: []
    };
}

async function loadTemplate() {
    const candidates = [
        process.env.DISCORD_USER_TEAMS_MD_PATH,
        path.resolve(__dirname, '../../resources/markdown/discord-user-teams.md'),
        path.resolve(__dirname, '../markdown/discord-user-teams.md'),
        path.resolve(process.cwd(), 'resources/markdown/discord-user-teams.md'),
    ].filter(Boolean);

    for (const p of candidates) {
        try {
            const txt = await fs.promises.readFile(p, 'utf8');
            if (txt && txt.trim()) return txt;
        } catch {}
    }

    return `{{display}} Teams
{{#if teams.length}}
{{#each teams}}
• {{league_name}} — {{team_name}}
{{/each}}
{{else}}
(no shared leagues)
{{/if}}`;
}

async function handle(interaction) {
    if (!interaction.isUserContextMenuCommand()) return false;
    if (interaction.commandName !== COMMAND_NAME) return false;

    await interaction.deferReply({
        flags: MessageFlags.Ephemeral
    });

    const target = interaction.targetUser;
    const display = interaction.targetMember ? .displayName ?
        ?
        target.globalName ? ? target.username ? ? 'User';

    try {
        const [tplSrc, data] = await Promise.all([
            loadTemplate(),
            fetchUserTeams(target.id, interaction.user.id),
        ]);

        if (data.not_linked) {
            await interaction.editReply(`${display} not yet linked to Fantrax`);
            return true;
        }

        const tpl = Handlebars.compile(tplSrc);
        const teams = Array.isArray(data.teams) ? data.teams : [];
        const content = tpl({
            display,
            teams
        });

        try {
            await interaction.user.send({
                content,
                allowedMentions: {
                    parse: []
                }
            });
            await interaction.editReply('Sent you a DM.');
        } catch {
            await interaction.editReply('Could not DM you (are DMs disabled?).');
        }
    } catch {
        await interaction.editReply(`${display} Teams\n(couldn't fetch right now)`);
    }

    return true;
}

module.exports = {
    COMMAND_NAME,
    register,
    handle
};
