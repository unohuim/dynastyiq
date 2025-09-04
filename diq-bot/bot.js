// diq-bot/bot.js
// NOTE: This file lives in <laravel-app>/diq-bot and loads env from the Laravel root.

const path = require("path");
const fs = require("fs");

// ---------- Env (root first, then bot overrides) ----------
const dotenv = require("dotenv");
dotenv.config({ path: path.resolve(__dirname, "../.env") }); // Laravel root
dotenv.config({ path: path.resolve(__dirname, ".env") }); // diq-bot/.env (wins)

// ---------- Constants ----------
const SIGNIN_URL = process.env.DIQ_SIGNIN_URL || "https://dynastyiq.com";
const PUBLIC_ORIGIN =
    process.env.BOT_REVERB_ORIGIN ||
    process.env.REVERB_ORIGIN ||
    "https://dynastyiq.com";

const crypto = require("crypto");
const axios = require("axios");
const { Client, GatewayIntentBits, Events } = require("discord.js");

// ---------- Force Origin header for all ws connections ----------
const wsModulePath = require.resolve("ws");
const WS = require("ws");

function WSWithOrigin(address, protocols, options = {}) {
    const opts = { ...(options || {}) };
    opts.headers = { ...(opts.headers || {}), Origin: PUBLIC_ORIGIN };
    opts.origin = opts.origin || PUBLIC_ORIGIN;

    console.log("[ws] open →", String(address), { origin: opts.origin });

    const sock = new WS(address, protocols, opts);

    // Deep diagnostics
    sock.on("unexpectedResponse", (req, res) => {
        console.log("[ws] unexpectedResponse", {
            status: res.statusCode,
            message: res.statusMessage,
            headers: res.headers,
        });
    });
    sock.on("upgrade", (res) => {
        console.log("[ws] upgrade ok", {
            accept: res?.headers?.["sec-websocket-accept"],
            server: res?.headers?.server,
            xPoweredBy: res?.headers?.["x-powered-by"],
        });
    });
    sock.on("open", () => console.log("[ws] open OK"));
    sock.on("close", (code, reason) =>
        console.log("[ws] close", { code, reason: reason?.toString?.() })
    );
    sock.on("error", (e) => console.log("[ws] error", e?.message || e));

    return sock;
}
Object.keys(WS).forEach((k) => (WSWithOrigin[k] = WS[k]));
WSWithOrigin.prototype = WS.prototype;
require.cache[wsModulePath].exports = WSWithOrigin;

// Use the Node build (it requires 'ws' internally — now patched)
const Pusher = require("pusher-js/node");
Pusher.logToConsole = true; // enable internal pusher diagnostics

// ---------- Feature modules ----------
const {
    register: registerUserTeams,
    handle: handleUserTeams,
} = require("./features/user-teams");
const {
    assignFantraxRole,
    assignFantraxRoleForUser,
} = require("./features/assign-fantrax-roles");

// ---------- Discord client ----------
const client = new Client({
    intents: [GatewayIntentBits.Guilds, GatewayIntentBits.GuildMembers],
});

// ---------- Utilities ----------
function makeLocalAuthorizer({ appKey, appSecret }) {
    // Returns a Pusher-compatible authorizer that signs private channel auth locally.
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

    // Default: INTERNAL
    let scheme = (process.env.REVERB_SCHEME || "http").toLowerCase();
    let host = process.env.REVERB_HOST || "127.0.0.1";
    let port = Number(
        process.env.REVERB_PORT || (scheme === "https" ? 443 : 80)
    );

    // If BOT_REVERB_USE_PUBLIC is set (1/true/yes) => use PUBLIC origin/host
    const usePublic = /^(1|true|yes)$/i.test(
        process.env.BOT_REVERB_USE_PUBLIC || ""
    );
    if (usePublic) {
        try {
            const u = new URL(PUBLIC_ORIGIN); // e.g. https://dynastyiq.com
            scheme = (u.protocol || "https:").replace(":", "");
            host = u.hostname;
            port = u.port ? Number(u.port) : scheme === "https" ? 443 : 80;
        } catch {
            scheme = "https";
            host =
                process.env.VITE_REVERB_HOST ||
                process.env.REVERB_PUBLIC_HOST ||
                "dynastyiq.com";
            port = 443;
        }
    }

    const useTLS = scheme === "https";
    const ORIGIN = PUBLIC_ORIGIN;

    const opts = {
        cluster: "mt1",
        wsHost: host,
        enabledTransports: ["ws", "wss"],
        forceTLS: useTLS,

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

        wsOptions: { origin: ORIGIN, headers: { Origin: ORIGIN } },
    };

    if (useTLS) opts.wssPort = port;
    else opts.wsPort = port;

    console.log("[pusher] config", {
        scheme,
        host,
        port,
        useTLS,
        ORIGIN,
        wsPort: opts.wsPort,
        wssPort: opts.wssPort,
        hasLocalAuth: !!process.env.REVERB_APP_SECRET,
        usingPublic: usePublic,
    });

    const pusher = new Pusher(KEY, opts);

    pusher.connection.bind("state_change", (s) =>
        console.log("[pusher] state", `${s.previous} -> ${s.current}`)
    );
    pusher.connection.bind("connected", () =>
        console.log("[pusher] connected", {
            socket_id: pusher.connection.socket_id,
        })
    );
    pusher.connection.bind("error", (err) =>
        console.error("[pusher] connection error:", err?.error || err)
    );
    pusher.connection.bind("failed", () => console.error("[pusher] failed"));
    pusher.connection.bind("unavailable", () =>
        console.warn("[pusher] unavailable")
    );

    const ch = pusher.subscribe("private-diq-bot");
    ch.bind("pusher:subscription_succeeded", () =>
        console.log("[pusher] subscribed: private-diq-bot")
    );
    ch.bind("pusher:subscription_error", (err) =>
        console.error("[pusher] subscription error (private-diq-bot):", err)
    );

    ch.bind("fantrax-linked", async (payload) => {
        console.log("📨 fantrax-linked RECEIVED:", JSON.stringify(payload));
        try {
            const discordId = String(payload?.discord_user_id || "");
            if (!discordId)
                return console.warn("fantrax-linked: missing discord_user_id");
            await assignFantraxRoleForUser(client, discordId, true);
            console.log(`✅ fantrax-linked DONE for ${discordId}`);
        } catch (e) {
            console.error("💥 fantrax-linked handler error:", e?.message || e);
        }
    });

    ch.bind("fantrax-unlinked", async (payload) => {
        console.log("📨 fantrax-unlinked RECEIVED:", JSON.stringify(payload));
        try {
            const discordId = String(payload?.discord_user_id || "");
            if (!discordId)
                return console.warn(
                    "fantrax-unlinked: missing discord_user_id"
                );
            await assignFantraxRoleForUser(client, discordId, false);
            console.log(`✅ fantrax-unlinked DONE for ${discordId}`);
        } catch (e) {
            console.error(
                "💥 fantrax-unlinked handler error:",
                e?.message || e
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
            e?.message || e
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
        } catch {
            /* next */
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
            console.warn(`⚠️ Also failed to DM owner: ${e2?.message}`);
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
        console.error("❌ Failed to send join event", err?.message || err);
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
