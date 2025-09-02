// features/user-teams.js
const {
  REST,
  Routes,
  ContextMenuCommandBuilder,
  ApplicationCommandType,
  MessageFlags,
} = require('discord.js');
const axios = require('axios');

const COMMAND_NAME = 'DIQ: User Teams';

// Lazy read so dotenv in bot.js can load ../.env first
function getApiBase() {
  return (
    process.env.API_BASE_URL ||
    process.env.DIQ_API_BASE_URL ||
    process.env.APP_URL || ''
  );
}
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
    .setDMPermission(false)
    .toJSON();

  if (guildId) {
    await rest.put(Routes.applicationGuildCommands(clientId, guildId), { body: [cmd] });
  } else {
    await rest.put(Routes.applicationCommands(clientId), { body: [cmd] });
  }
}

async function fetchDiscordUser(discordId) {
  const API_BASE = getApiBase();
  if (!API_BASE) throw new Error('Missing APP_URL (or API_BASE_URL/DIQ_API_BASE_URL)');

  const url = new URL(`/api/discord/users/${discordId}`, API_BASE).toString();
  const resp = await axios.get(url, {
    timeout: 8000,
    validateStatus: (s) => s >= 200 && s < 500,
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

  await interaction.deferReply({ flags: MessageFlags.Ephemeral });

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
