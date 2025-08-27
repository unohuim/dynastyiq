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

// after dotenv load
const SIGNIN_URL = process.env.DIQ_SIGNIN_URL || 'https://dynastyiq.com';

const { Client, GatewayIntentBits, Events } = require('discord.js');
const axios = require('axios');

const client = new Client({
  intents: [GatewayIntentBits.Guilds, GatewayIntentBits.GuildMembers]
});

client.once(Events.ClientReady, (c) => {
  console.log(`🤖 DIQ Bot logged in as ${c.user.tag}`);
});

// NEW: load markdown DM body from local filesystem
async function loadWelcomeMarkdown() {
  const candidates = [
    process.env.DISCORD_WELCOME_MD_PATH, // optional override
    path.resolve(__dirname, '../resources/markdown/discord-join-welcome.md'),
    path.resolve(__dirname, '../markdown/discord-join-welcome.md'),
    path.resolve(process.cwd(), 'markdown/discord-join-welcome.md'),
  ].filter(Boolean);

  for (const p of candidates) {
    try {
      const data = await fs.promises.readFile(p, 'utf8');
      const text = (data || '').trim();
      if (text) return text;
    } catch (_) { /* try next */ }
  }
  return null;
}

// Listen for new member join
client.on(Events.GuildMemberAdd, async (member) => {
  console.log(`👤 New member joined: ${member.user.tag} (${member.id})`);

  const OAUTH_URL = process.env.DISCORD_OAUTH_URL;

  // CHANGED: use markdown template (with simple fallback)
  let dmBody = await loadWelcomeMarkdown();
  if (!dmBody) {
    dmBody = [
      `👋 Welcome to DynastyIQ!`,
      ``,
      `Connect your DynastyIQ account (stats & Fantrax):`,
      OAUTH_URL
    ].join('\n');
  }

  try {
    await member.send(dmBody);
    console.log(`✅ DM sent to ${member.user.tag}`);
  } catch (err) {
    console.warn(`⚠️ Could not DM ${member.user.tag}: ${err?.code || err?.message}`);
    try {
      const owner = await member.guild.fetchOwner();
      await owner.send(
        `FYI: I couldn't DM ${member.user.tag} (DMs likely off). ` +
        `Please ask them to connect here: ${OAUTH_URL}`
      );
      console.log(`✅ Owner notified about ${member.user.tag}`);
    } catch (e2) {
      console.warn(`⚠️ Also failed to DM owner: ${e2?.message}`);
    }
  }

  try {
    await axios.post(process.env.DISCORD_MEMBER_JOINED_URL, {
      discord_user_id: member.id,
      guild_id: member.guild.id,
      username: member.user.username ?? null,
      email: member.user.email ?? null  // discord rarely provides email
    });
    console.log("✅ Sent join event to web app");
  } catch (err) {
    console.error("❌ Failed to send join event", err.message);
  }
});

client.login(process.env.DISCORD_BOT_TOKEN);
