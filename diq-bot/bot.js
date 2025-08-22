require('dotenv').config();
const { Client, GatewayIntentBits, Events } = require('discord.js');
const axios = require('axios');

const client = new Client({
  intents: [
    GatewayIntentBits.Guilds,
    GatewayIntentBits.GuildMembers
  ]
});

client.once(Events.ClientReady, (c) => {
  console.log(`🤖 DIQ Bot logged in as ${c.user.tag}`);
});

// Listen for new member join
client.on(Events.GuildMemberAdd, async (member) => {
  console.log(`👤 New member joined: ${member.user.tag} (${member.id})`);

  try {
    await axios.post(process.env.APP_CALLBACK_URL, {
      discord_user_id: member.id,
      guild_id: member.guild.id,
      email: member.user.email ?? null  // note: discord API doesn't always send email
    });
    console.log("✅ Sent join event to web app");
  } catch (err) {
    console.error("❌ Failed to send join event", err.message);
  }
});

client.login(process.env.DISCORD_BOT_TOKEN);
