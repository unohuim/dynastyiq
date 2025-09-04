// diq-bot/bot.js
// NOTE: This file lives in <laravel-app>/diq-bot and loads env from the Laravel root.

const crypto = require("crypto"); // for local Pusher authorizer HMAC
const Pusher = require("pusher-js");
global.WebSocket = require("ws"); // pusher-js in Node

const path = require("path");
const fs = require("fs");
const axios = require("axios");
const { Client, GatewayIntentBits, Events } = require("discord.js");

const {
    register: registerUserTeams,
    handle: handleUserTeams,
} = require("./features/user-teams");
const {
    assignFantraxRole,
    assignFantraxRoleForUser,
} = require("./features/assign-fantrax-roles");

// ---------- Env (prefer Laravel root .env) ----------
const envCandidates = [
    path.resolve(__dirname, "../.env"), // Laravel root
    path.resolve(process.cwd(), ".env"), // fallback
];
const envFile = envCandidates.find((p) => fs.existsSync(p));
require("dotenv").config(envFile ? { path: envFile } : {});

const SIGNIN_URL = process.env.DIQ_SIGNIN_URL || "https://dynastyiq.com";

// ---------- Discord client ----------
const client = new Client({
    intents: [GatewayIntentBits.Guilds, GatewayIntentBits.GuildMembers],
});

// ---------- Utilities ----------
function makeLocalAuthorizer({ appKey, appSecret }) {
    // Returns a Pusher-compatible authorizer object that signs private channel auth locally.
    return function (channel /* Pusher.Channel */, _options) {
        return {
            authorize(socketId, callback) {
                try {
                    const channelName = channel.name;
                    const stringToSign = `${socketId}:${channelName}`;
                    const signature = crypto
                        .createHmac("sha256", appSecret)
                        .update(stringToSign)
                        .digest("hex");
                    callback(false, { auth: `${appKey}:${signature}` });
                } catch (err) {
                    console.error(
                        "authorizer error:",
                        (err && err.message) || String(err)
                    );
                    callback(true, err);
                }
            },
        };
    };
}

function buildOrigin({ scheme, host, port }) {
    const standard =
        (scheme === "https" && port === 443) ||
        (scheme === "http" && port === 80);
    return standard ? `${scheme}://${host}` : `${scheme}://${host}:${port}`;
}

// ---------- On boot ----------
async function onBoot({ client }) {
    console.log("🛠 onBoot: starting Fantrax bulk sync…");
    await assignFantraxRole(client);
    console.log("🛠 onBoot: bulk sync finished.");
}

// ---------- Reverb (Pusher protocol) wiring ----------
function wireRealtime({ client }) {
    const KEY =
        process.env.REVERB_APP_KEY ||
        process.env.VITE_REVERB_APP_KEY ||
        process.env.PUSHER_KEY;

    if (!KEY) {
        console.warn(
            "📡 Realtime not configured (no REVERB_APP_KEY / VITE_REVERB_APP_KEY); skipping realtime."
        );
        return;
    }

    // Use your INTERNAL Reverb endpoint (server-side)
    const scheme = (process.env.REVERB_SCHEME || "http").toLowerCase(); // e.g. http
    const host = process.env.REVERB_HOST || "127.0.0.1"; // e.g. 127.0.0.1
    const port = Number(
        process.env.REVERB_PORT || (scheme === "https" ? 443 : 80)
    ); // e.g. 8080
    const useTLS = scheme === "https" || scheme === "wss";

    // Build/override Origin header — must be allowed by Reverb allowed_origins
    const defaultOrigin = buildOrigin({ scheme, host, port });
    const ORIGIN =
        process.env.BOT_REVERB_ORIGIN || // recommended: https://dynastyiq.com
        process.env.REVERB_ORIGIN || // optional fallback
        defaultOrigin;

    const opts = {
        cluster: "mt1", // required by pusher-js even for Reverb
        wsHost: host,
        enabledTransports: ["ws", "wss"],
        forceTLS: useTLS,

        // Prefer local HMAC auth if we have the secret; otherwise fall back to Laravel auth endpoint.
        ...(process.env.REVERB_APP_SECRET
            ? {
                  authorizer: makeLocalAuthorizer({
                      appKey: KEY,
                      appSecret: process.env.REVERB_APP_SECRET,
                  }),
              }
            : {
                  authEndpoint:
                      process.env.PUSHER_AUTH_ENDPOINT ||
                      `${SIGNIN_URL}/broadcasting/auth`,
              }),

        // Send Origin in both forms to satisfy different ws stacks
        wsOptions: {
            origin: ORIGIN,
            headers: { Origin: ORIGIN },
        },
    };

    if (useTLS) opts.wssPort = port;
    else opts.wsPort = port;

    console.log(
        `📡 Realtime (internal) → host=${host} scheme=${scheme} port=${port} tls=${useTLS}`
    );
    console.log(`📡 Using Origin header: ${ORIGIN}`);
    console.log(
        process.env.REVERB_APP_SECRET
            ? "🔐 Using local HMAC authorizer (no /broadcasting/auth)."
            : "🔓 Using remote auth endpoint (Laravel /broadcasting/auth)."
    );

    const pusher = new Pusher(KEY, opts);

    // Connection lifecycle logs
    pusher.connection.bind("state_change", function (s) {
        console.log(`📡 Pusher state: ${s.previous} → ${s.current}`);
    });
    pusher.connection.bind("connected", function () {
        console.log(
            `📡 Pusher connected (socket_id=${pusher.connection.socket_id})`
        );
    });
    pusher.connection.bind("error", function (err) {
        const msg = (err && (err.error || err.message)) || err;
        console.error("📡 Pusher connection error:", msg);
    });

    const ch = pusher.subscribe("private-diq-bot");
    ch.bind("pusher:subscription_succeeded", function () {
        console.log("📡 Subscribed to channel: private-diq-bot");
    });
    ch.bind("pusher:subscription_error", function (err) {
        console.error("📡 Subscription error (private-diq-bot):", err);
    });

    // ---- Events from Laravel ----
    ch.bind("fantrax-linked", async function (payload) {
        console.log("📨 fantrax-linked RECEIVED:", JSON.stringify(payload));
        try {
            const discordId = String(
                payload && payload.discord_user_id
                    ? payload.discord_user_id
                    : ""
            );
            if (!discordId) {
                console.warn("fantrax-linked: missing discord_user_id");
                return;
            }
            console.log(
                `➡️  Assign Fantrax role for ${discordId} in all mutual guilds…`
            );
            await assignFantraxRoleForUser(client, discordId, true);
            console.log(`✅ fantrax-linked DONE for ${discordId}`);
        } catch (e) {
            console.error(
                "💥 fantrax-linked handler error:",
                (e && e.message) || e
            );
        }
    });

    ch.bind("fantrax-unlinked", async function (payload) {
        console.log("📨 fantrax-unlinked RECEIVED:", JSON.stringify(payload));
        try {
            const discordId = String(
                payload && payload.discord_user_id
                    ? payload.discord_user_id
                    : ""
            );
            if (!discordId) {
                console.warn("fantrax-unlinked: missing discord_user_id");
                return;
            }
            console.log(
                `➡️  REMOVE Fantrax role for ${discordId} in all mutual guilds…`
            );
            await assignFantraxRoleForUser(client, discordId, false);
            console.log(`✅ fantrax-unlinked DONE for ${discordId}`);
        } catch (e) {
            console.error(
                "💥 fantrax-unlinked handler error:",
                (e && e.message) || e
            );
        }
    });
}

// ---------- Ready ----------
client.once(Events.ClientReady, async function (c) {
    console.log(`🤖 DIQ Bot logged in as ${c.user.tag}`);

    try {
        console.log("🧾 Registering context menu command DIQ: User Teams…");
        await registerUserTeams({
            token: process.env.DISCORD_BOT_TOKEN,
            clientId: process.env.CLIENT_ID || process.env.DISCORD_CLIENT_ID,
            guildId: process.env.GUILD_ID || process.env.DISCORD_GUILD_ID,
        });
        console.log("✅ Registered DIQ: User Teams");
    } catch (e) {
        console.error(
            "❌ Failed to register DIQ: User Teams:",
            (e && e.message) || e
        );
    }

    await onBoot({ client: c });
    wireRealtime({ client: c });
});

// ---------- Join DM ----------
async function loadWelcomeMarkdown() {
    const candidates = [
        process.env.DISCORD_WELCOME_MD_PATH,
        path.resolve(
            __dirname,
            "../resources/markdown/discord-join-welcome.md"
        ),
        path.resolve(__dirname, "../markdown/discord-join-welcome.md"),
        path.resolve(process.cwd(), "markdown/discord-join-welcome.md"),
    ].filter(Boolean);

    for (const p of candidates) {
        try {
            const data = await fs.promises.readFile(p, "utf8");
            const text = (data || "").trim();
            if (text) return text;
        } catch (_e) {
            // next candidate
        }
    }
    return null;
}

client.on(Events.GuildMemberAdd, async function (member) {
    console.log(`👤 New member joined: ${member.user.tag} (${member.id})`);

    const OAUTH_URL = process.env.DISCORD_OAUTH_URL;

    let dmBody = await loadWelcomeMarkdown();
    if (!dmBody) {
        dmBody = [
            "👋 Welcome to DynastyIQ!",
            "",
            "Connect your DynastyIQ account (stats & Fantrax):",
            OAUTH_URL,
        ].join("\n");
    }

    try {
        await member.send(dmBody);
        console.log(`✅ DM sent to ${member.user.tag}`);
    } catch (err) {
        const why = (err && (err.code || err.message)) || err;
        console.warn(`⚠️ Could not DM ${member.user.tag}: ${why}`);
        try {
            const owner = await member.guild.fetchOwner();
            await owner.send(
                `FYI: I couldn't DM ${member.user.tag} (DMs likely off). ` +
                    `Please ask them to connect here: ${OAUTH_URL}`
            );
            console.log(`✅ Owner notified about ${member.user.tag}`);
        } catch (e2) {
            console.warn(
                `⚠️ Also failed to DM owner: ${(e2 && e2.message) || e2}`
            );
        }
    }

    try {
        await axios.post(process.env.DISCORD_MEMBER_JOINED_URL, {
            discord_user_id: member.id,
            guild_id: member.guild.id,
            username:
                member.user && member.user.username
                    ? member.user.username
                    : null,
            email: member.user && member.user.email ? member.user.email : null,
        });
        console.log("✅ Sent join event to web app");
    } catch (err) {
        console.error(
            "❌ Failed to send join event",
            (err && err.message) || err
        );
    }
});

// ---------- Interactions ----------
client.on(Events.InteractionCreate, async function (interaction) {
    if (await handleUserTeams(interaction)) return;
    // other handlers…
});

// ---------- Start ----------
console.log("🚀 Logging in to Discord…");
client.login(process.env.DISCORD_BOT_TOKEN);
