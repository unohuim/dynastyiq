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
  path.resolve(__dirname, '../.env'), // Laravel root (one level up)
  path.resolve(process.cwd(), '.env'),
];
const envFile = envCandidates.find(p => fs.existsSync(p));
require('dotenv').config(envFile ? { path: envFile } : {});
console.log(
  `🧩 env loaded from: ${envFile || 'process.env'} | ` +
  `REVERB_APP_KEY:${process.env.REVERB_APP_KEY ? 'set' : '—'} | ` +
  `VITE_REVERB_APP_KEY:${process.env.VITE_REVERB_APP_KEY ? 'set' : '—'} | ` +
  `REVERB_HOST:${process.env.REVERB_HOST || '—'} | ` +
  `VITE_REVERB_HOST:${process.env.VITE_REVERB_HOST || '—'}`
);

const SIGNIN_URL = process.env.DIQ_SIGNIN_URL || 'https://dynastyiq.com';

// ---------- Process/Client error visibility ----------
process.on('unhandledRejection', r => console.error('💥 UnhandledRejection:', r));
process.on('uncaughtException',  e => console.error('💥 UncaughtException:',  e));
const client = new Client({ intents: [GatewayIntentBits.Guilds, GatewayIntentBits.GuildMembers] });
client.on('error', e => console.error('💥 Discord client error:', e?.message || e));

// ---------- On boot ----------
async function onBoot({ client }) {
  console.log('🛠 onBoot: starting Fantrax bulk sync…');
  await assignFantraxRole(client);
  console.log('🛠 onBoot: bulk sync finished.');
}

// ---------- Reverb (Pusher protocol) wiring ----------
function wireRealtime({ client }) {
  const KEY =
    process.env.REVERB_APP_KEY ||
    process.env.VITE_REVERB_APP_KEY ||
    process.env.PUSHER_KEY; // last-resort fallback

  if (!KEY) {
    console.warn('📡 Realtime not configured (no REVERB_APP_KEY / VITE_REVERB_APP_KEY); skipping realtime.');
    return;
  }

  const scheme = (process.env.VITE_REVERB_SCHEME || process.env.REVERB_SCHEME || 'https').toLowerCase();
  const host   =  process.env.VITE_REVERB_HOST   || process.env.REVERB_HOST   || 'localhost';
  const port   = Number(process.env.VITE_REVERB_PORT || process.env.REVERB_PORT || (scheme === 'https' ? 443 : 80));
  const useTLS = scheme === 'https' || scheme === 'wss';
  const authEndpoint = process.env.PUSHER_AUTH_ENDPOINT || `${SIGNIN_URL}/broadcasting/auth`;

  console.log(`📡 Realtime config → host=${host} scheme=${scheme} port=${port} tls=${useTLS} auth=${authEndpoint}`);

  const opts = {
    cluster: 'mt1',                // required by pusher-js even with Reverb
    wsHost: host,
    enabledTransports: ['ws','wss'],
    forceTLS: useTLS,
    authEndpoint,
    ...(useTLS ? { wssPort: port } : { wsPort: port }),
  };

  const pusher = new Pusher(KEY, opts);

  pusher.connection.bind('state_change', s =>
    console.log(`📡 Pusher state: ${s.previous} → ${s.current}`)
  );
  pusher.connection.bind('error', err =>
    console.error('📡 Pusher connection error:', err?.error || err)
  );

  const ch = pusher.subscribe('private-diq-bot');
  ch.bind('pusher:subscription_succeeded', () =>
    console.log('📡 Subscribed to channel: private-diq-bot')
  );
  ch.bind('pusher:subscription_error', err =>
    console.error('📡 Subscription error (private-diq-bot):', err)
  );

  // Fantrax linked
  ch.bind('fantrax-linked', async (payload) => {
    console.log('📨 fantrax-linked RECEIVED:', JSON.stringify(payload));
    try {
      const discordId = String(payload?.discord_user_id || '');
      if (!discordId) return console.warn('fantrax-linked: missing discord_user_id');
      console.log(`➡️  Assign Fantrax role for ${discordId} across mutual guilds…`);
      await assignFantraxRoleForUser(client, discordId, true);
      console.log(`✅ fantrax-linked DONE for ${discordId}`);
    } catch (e) {
      console.error('💥 fantrax-linked handler error:', e?.message || e);
    }
  });

  // Fantrax unlinked
  ch.bind('fantrax-unlinked', async (payload) => {
    console.log('📨 fantrax-unlinked RECEIVED:', JSON.stringify(payload));
    try {
      const discordId = String(payload?.discord_user_id || '');
      if (!discordId) return console.warn('fantrax-unlinked: missing discord_user_id');
      console.log(`➡️  REMOVE Fantrax role for ${discordId} across mutual guilds…`);
      await assignFantraxRoleForUser(client, discordId, false);
      console.log(`✅ fantrax-unlinked DONE for ${discordId}`);
    } catch (e) {
      console.error('💥 fantrax-unlinked handler error:', e?.message || e);
    }
  });
}

// ---------- Ready ----------
client.once(Events.ClientReady, async (c) => {
  console.log(`🤖 DIQ Bot logged in as ${c.user.tag}`);

  try {
    console.log('🧾 Registering context menu command DIQ: User Teams…');
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
if (!process.env.DISCORD_BOT_TOKEN) {
  console.error('❌ Missing DISCORD_BOT_TOKEN. Exiting.');
  process.exit(1);
}
console.log('🚀 Logging in to Discord…');
client.login(process.env.DISCORD_BOT_TOKEN);
