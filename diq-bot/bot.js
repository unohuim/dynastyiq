// diq-bot/bot.js
// NOTE: This file lives in <laravel-app>/diq-bot, and reads env from the Laravel root.

const { register: registerUserTeams, handle: handleUserTeams } = require('./features/user-teams');
const { assignFantraxRole, assignFantraxRoleForUser } = require('./features/assign-fantrax-roles');

const Pusher = require('pusher-js');
global.WebSocket = require('ws'); // pusher-js in Node

const path = require('path');
const fs = require('fs');
const { Client, GatewayIntentBits, Events } = require('discord.js');
const axios = require('axios');

// ---------- Env (prefer Laravel root .env) ----------
const envCandidates = [
  path.resolve(__dirname, '../../.env'),
  path.resolve(process.cwd(), '.env'),
];
const envFile = envCandidates.find(p => fs.existsSync(p));
require('dotenv').config(envFile ? { path: envFile } : {});

// Base app URL (used for auth endpoint fallback)
const SIGNIN_URL = process.env.DIQ_SIGNIN_URL || 'https://dynastyiq.com';

// ---------- Discord client ----------
const client = new Client({
  intents: [GatewayIntentBits.Guilds, GatewayIntentBits.GuildMembers],
});

// ---------- On boot ----------
async function onBoot({ client }) {
  await assignFantraxRole(client);
}

// ---------- Reverb (Pusher protocol) wiring ----------
function wireRealtime({ client }) {
  const KEY =
    process.env.REVERB_APP_KEY ||
    process.env.VITE_REVERB_APP_KEY ||
    process.env.PUSHER_KEY; // last-resort fallback

  if (!KEY) {
    console.warn('Realtime not configured (no REVERB_APP_KEY / VITE_REVERB_APP_KEY); skipping realtime.');
    return;
  }

  // Prefer VITE_* (public) if present, else REVERB_* (server)
  const scheme = (process.env.VITE_REVERB_SCHEME || process.env.REVERB_SCHEME || 'https').toLowerCase();
  const host = process.env.VITE_REVERB_HOST || process.env.REVERB_HOST || 'localhost';
  const port = Number(process.env.VITE_REVERB_PORT || process.env.REVERB_PORT || (scheme === 'https' ? 443 : 80));
  const useTLS = scheme === 'https' || scheme === 'wss';

  const opts = {
    // pusher-js requires a cluster value even when unused by Reverb
    cluster: 'mt1',
    wsHost: host,
    enabledTransports: ['ws', 'wss'],
    forceTLS: useTLS,
    authEndpoint: process.env.PUSHER_AUTH_ENDPOINT || `${SIGNIN_URL}/broadcasting/auth`,
  };
  if (useTLS) {
    opts.wssPort = port;
  } else {
    opts.wsPort = port;
  }

  const pusher = new Pusher(KEY, opts);
  const ch = pusher.subscribe('private-diq-bot');

  ch.bind('pusher:subscription_error', err => console.error('Pusher subscription error:', err));

  ch.bind('fantrax-linked', async ({ discord_user_id }) => {
    try {
      await assignFantraxRoleForUser(client, String(discord_user_id), true);
      console.log(`✅ fantrax-linked handled for ${discord_user_id}`);
    } catch (e) {
      console.error('fantrax-linked handler error:', e?.message || e);
    }
  });

  ch.bind('fantrax-unlinked', async ({ discord_user_id }) => {
    try {
      await assignFantraxRoleForUser(client, String(discord_user_id), false);
      console.log(`✅ fantrax-unlinked handled for ${discord_user_id}`);
    } catch (e) {
      console.error('fantrax-unlinked handler error:', e?.message || e);
    }
  });
}

// ---------- Ready ----------
client.once(Events.ClientReady, async (c) => {
  console.log(`🤖 DIQ Bot logged in as ${c.user.tag}`);

  try {
    await registerUserTeams({
      token: process.env.DISCORD_BOT_TOKEN,
      clientId: process.env.CLIENT_ID || process.env.DISCORD_CLIENT_ID,
      guildId: process.env.GUILD_ID || process.env.DISCORD_GUILD_ID,
    });
    console.log('✅ Registered DIQ: User Teams');
  } catch (e) {
    console.error('❌ Failed to register DIQ: User Teams:', e?.message || e);
  }

  await onBoot({ client: c });
  wireRealtime({ client: c });
});

// ---------- Join DM ----------
async function loadWelcomeMarkdown() {
  const candidates = [
    process.env.DISCORD_WELCOME_MD_PATH,
    path.resolve(__dirname, '../resources/markdown/discord-join-welcome.md'),
    path.resolve(__dirname, '../markdown/discord-join-welcome.md'),
    path.resolve(process.cwd(), 'markdown/discord-join-welcome.md'),
  ].filter(Boolean);

  for (const p of candidates) {
    try {
      const data = await fs.promises.readFile(p, 'utf8');
      const text = (data || '').trim();
      if (text) return text;
    } catch { /* next */ }
  }
  return null;
}

client.on(Events.GuildMemberAdd, async (member) => {
  console.log(`👤 New member joined: ${member.user.tag} (${member.id})`);

  const OAUTH_URL = process.env.DISCORD_OAUTH_URL;

  let dmBody = await loadWelcomeMarkdown();
  if (!dmBody) {
    dmBody = [
      `👋 Welcome to DynastyIQ!`,
      ``,
      `Connect your DynastyIQ account (stats & Fantrax):`,
      OAUTH_URL,
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
      email: member.user.email ?? null,
    });
    console.log('✅ Sent join event to web app');
  } catch (err) {
    console.error('❌ Failed to send join event', err.message);
  }
});

// ---------- Interactions ----------
client.on(Events.InteractionCreate, async (interaction) => {
  if (await handleUserTeams(interaction)) return;
  // other handlers...
});

// ---------- Start ----------
client.login(process.env.DISCORD_BOT_TOKEN);
