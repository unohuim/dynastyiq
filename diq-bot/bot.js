// diq-bot/bot.js
const path = require('path');
const fs = require('fs');

// Prefer ../.env (Laravel root). Fallback to CWD/.env if you ever run it elsewhere.
const envCandidates = [
  path.resolve(__dirname, '../.env'),
  path.resolve(process.cwd(), '.env'),
];
const envFile = envCandidates.find(p => fs.existsSync(p));

require('dotenv').config(envFile ? { path: envFile } : {});



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
    await axios.post(process.env.DISCORD_MEMBER_JOINED_URL, {
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
