// features/user-teams.js
const {
  REST,
  Routes,
  ContextMenuCommandBuilder,
  ApplicationCommandType,
} = require('discord.js');
const axios = require('axios');

const COMMAND_NAME = 'DIQ: User Teams';

const API_BASE =
  process.env.API_BASE_URL ||
  process.env.DIQ_API_BASE_URL || // fallback if you use this name
  process.env.APP_URL;            // last-resort fallback
const API_KEY   = process.env.API_INTERNAL_KEY;
const OAUTH_URL = process.env.DISCORD_OAUTH_URL || process.env.DIQ_SIGNIN_URL || '';

/**
 * Register the User Context Command (right-click → Apps → DIQ: User Teams)
 * @param {{ token: string, clientId: string, guildId?: string }} cfg
 */
async function register({ token, clientId, guildId }) {
  if (!token || !clientId) throw new Error('register: token and clientId are required');

  const rest = new REST({ version: '10' }).setToken(token);
  const cmd = new ContextMenuCommandBuilder()
    .setName(COMMAND_NAME)
    .setType(ApplicationCommandType.User)
    .setDMPermission(false) // server-only
    .toJSON();

  if (guildId) {
    await rest.put(Routes.applicationGuildCommands(clientId, guildId), { body: [cmd] });
  } else {
    await rest.put(Routes.applicationCommands(clientId), { body: [cmd] });
  }
}

async function fetchDiscordUser(discordId) {
  if (!API_BASE || !API_KEY) throw new Error('Missing API_BASE_URL/DIQ_API_BASE_URL/APP_URL or API_INTERNAL_KEY');
  const url = new URL(`/api/discord/users/${discordId}`, API_BASE).toString();
  const resp = await axios.get(url, {
    headers: { 'X-Internal-Key': API_KEY },
    timeout: 8000,
    validateStatus: (s) => s >= 200 && s < 500, // treat 4xx as handled (return body)
  });
  return resp.data || {};
}

/**
 * Handle the context menu interaction.
 * DMs the invoker with "user teams" and the target's email (or missing).
 * @param {import('discord.js').Interaction} interaction
 */
async function handle(interaction) {
  if (!interaction.isUserContextMenuCommand()) return false;
  if (interaction.commandName !== COMMAND_NAME) return false;

  await interaction.deferReply({ ephemeral: true });

  const target = interaction.targetUser;

  let emailMsg = '';
  try {
    const record = await fetchDiscordUser(target.id);
    if (record && record.email) {
      emailMsg = `\nemail: ${record.email}`;
    } else {
      emailMsg = `\nemail: missing${OAUTH_URL ? ` — connect here: ${OAUTH_URL}` : ''}`;
    }
  } catch (_) {
    emailMsg = `\nemail: (couldn't fetch right now)`;
  }

  try {
    await interaction.user.send({
      content: `user teams${emailMsg}`,
      allowedMentions: { parse: [] },
    });
    await interaction.editReply('Sent you a DM.');
  } catch (err) {
    await interaction.editReply('Could not DM you (are DMs disabled?).');
  }

  return true;
}

module.exports = { COMMAND_NAME, register, handle };
