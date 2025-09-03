// diq-bot/bot.js

const { register: registerUserTeams, handle: handleUserTeams } = require('./features/user-teams');
const { assignFantraxRole, assignFantraxRoleForUser } = require('./features/assign-fantrax-roles');
const Pusher = require('pusher-js');
global.WebSocket = require('ws'); // needed for pusher-js in Node

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

// everytime on restart
async function onBoot({ client }) {
  await assignFantraxRole(client);
}

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

  // 🔸 run-on-restart code goes here
  await onBoot({ client: c });

  // ----- Realtime from Laravel (Pusher) -----
  const PUSHER_KEY =
    process.env.PUSHER_KEY ||
    process.env.PUSHER_APP_KEY ||
    process.env.VITE_PUSHER_APP_KEY;

  const PUSHER_CLUSTER =
    process.env.PUSHER_CLUSTER ||
    process.env.PUSHER_APP_CLUSTER ||
    process.env.VITE_PUSHER_APP_CLUSTER ||
    'mt1'; // default cluster to avoid "must provide a cluster"

  if (PUSHER_KEY) {
    try {
      const opts = {
        cluster: PUSHER_CLUSTER,
        forceTLS: true,
        authEndpoint: process.env.PUSHER_AUTH_ENDPOINT || `${SIGNIN_URL}/broadcasting/auth`,
      };

      // Optional self-hosted / websockets overrides
      if (process.env.PUSHER_HOST) {
        opts.wsHost = process.env.PUSHER_HOST;
        if (process.env.PUSHER_PORT) {
          const port = Number(process.env.PUSHER_PORT);
          opts.wsPort = port;
          opts.wssPort = port;
        }
        opts.forceTLS = String(process.env.PUSHER_USE_TLS || 'true') === 'true';
        opts.enabledTransports = ['ws', 'wss'];
      }

      const pusher = new Pusher(PUSHER_KEY, opts);
      const ch = pusher.subscribe('private-diq-bot');

      ch.bind('pusher:subscription_error', err =>
        console.error('Pusher subscription error:', err)
      );

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
    } catch (e) {
      console.error('Pusher init failed:', e?.message || e);
    }
  } else {
    console.warn('Pusher not configured (no PUSHER_KEY); skipping realtime.');
  }
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

client.on(Events.InteractionCreate, async (interaction) => {
  if (await handleUserTeams(interaction)) return;
  // other handlers...
});

client.login(process.env.DISCORD_BOT_TOKEN);
