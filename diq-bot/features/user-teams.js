// features/user-teams.js
const {
  REST,
  Routes,
  ContextMenuCommandBuilder,
  ApplicationCommandType,
} = require('discord.js');

const COMMAND_NAME = 'DIQ: User Teams';

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

/**
 * Handle the context menu interaction.
 * DMs the invoker with a placeholder "user teams" message.
 * @param {import('discord.js').Interaction} interaction
 */
async function handle(interaction) {
  if (!interaction.isUserContextMenuCommand()) return false;
  if (interaction.commandName !== COMMAND_NAME) return false;

  await interaction.deferReply({ ephemeral: true });

  try {
    await interaction.user.send({ content: 'user teams', allowedMentions: { parse: [] } });
    await interaction.editReply('Sent you a DM.');
  } catch (err) {
    await interaction.editReply('Could not DM you (are DMs disabled?).');
  }
  return true;
}

module.exports = { COMMAND_NAME, register, handle };
