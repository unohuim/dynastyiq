// features/assign-fantrax-role.js
const axios = require('axios');

const TEAL = '#14B8A6';
const ROLE_NAME = 'Fantrax';

function getApiBase() {
  return process.env.API_BASE_URL || process.env.DIQ_API_BASE_URL || process.env.APP_URL || '';
}

/**
 * Rate-limited runner (ops per second).
 */
function makeLimiter(opsPerSec = 10) {
  const q = [];
  let ticking = false;
  return fn => new Promise((resolve, reject) => {
    q.push({ fn, resolve, reject });
    if (!ticking) {
      ticking = true;
      const iv = setInterval(async () => {
        const job = q.shift();
        if (!job) { clearInterval(iv); ticking = false; return; }
        try { job.resolve(await job.fn()); } catch (e) { job.reject(e); }
      }, Math.ceil(1000 / opsPerSec));
    }
  });
}

/**
 * Ensure the "Fantrax" role exists in a guild (creates if missing).
 * Returns the Role object.
 */
async function ensureRole(guild) {
  const byName = guild.roles.cache.find(r => r.name === ROLE_NAME);
  if (byName) return byName;

  // create if missing
  return guild.roles.create({
    name: ROLE_NAME,
    color: TEAL,
    reason: 'DIQ: ensure Fantrax role exists',
  });
}

/**
 * Fetch all members for a guild (caches are not guaranteed).
 */
async function fetchAllMembers(guild) {
  try {
    const coll = await guild.members.fetch(); // requires GUILD_MEMBERS intent
    return coll;
  } catch (e) {
    console.warn(`⚠️  Could not fetch members for guild ${guild.id}: ${e?.message}`);
    return guild.members.cache;
  }
}

/**
 * Ask Laravel which Discord IDs are Fantrax-connected.
 * Body: { discord_user_ids: [...] } -> { connected_ids: [...] }
 */
async function fetchConnectedIds(discordIds) {
  const API_BASE = getApiBase();
  if (!API_BASE) throw new Error('Missing API_BASE_URL/APP_URL');

  const url = new URL('/api/diq/is-fantrax', API_BASE).toString();
  const resp = await axios.post(url, { discord_user_ids: Array.from(discordIds) }, {
    timeout: 20000,
    headers: {
      'User-Agent': 'diq-bot',
      'Content-Type': 'application/json',
      ...(process.env.DIQ_INTERNAL_SECRET ? { 'X-Webhook-Secret': process.env.DIQ_INTERNAL_SECRET } : {}),
    },
    validateStatus: s => s >= 200 && s < 500,
  });
  if (resp.status !== 200) return new Set();
  const list = Array.isArray(resp.data?.connected_ids) ? resp.data.connected_ids : [];
  return new Set(list.map(String));
}

/**
 * Assign/remove the Fantrax role per user per guild, based on Laravel's isFantrax().
 * Run this from onBoot(): await assignFantraxRole(client)
 */
async function assignFantraxRole(client) {
  const limiter = makeLimiter(10); // ~10 role ops/sec total

  // 1) Collect all members from all guilds the bot is in
  const guilds = await client.guilds.fetch();
  const guildObjs = [];
  const allMemberIds = new Set();

  for (const [, gMeta] of guilds) {
    try {
      const guild = await client.guilds.fetch(gMeta.id);
      guildObjs.push(guild);
      const members = await fetchAllMembers(guild);
      members.forEach(m => allMemberIds.add(String(m.id)));
    } catch (e) {
      console.warn(`⚠️  Skipping guild ${gMeta.id}: ${e?.message}`);
    }
  }

  if (allMemberIds.size === 0) {
    console.log('ℹ️ No members found across guilds.');
    return;
  }

  // 2) Ask Laravel once for who is fantrax-connected (per user)
  const connected = await fetchConnectedIds(allMemberIds);

  // 3) Per guild: ensure role exists, then add/remove per member
  for (const guild of guildObjs) {
    let role;
    try {
      role = await ensureRole(guild);
    } catch (e) {
      console.warn(`⚠️  Could not ensure role in guild ${guild.id}: ${e?.message}`);
      continue;
    }

    const members = await fetchAllMembers(guild);
    const currentHolders = new Set(
      members
        .filter(m => m.roles.cache.has(role.id))
        .map(m => String(m.id))
    );

    // desired = connected ∩ guild members
    const guildMemberIds = new Set(members.map(m => String(m.id)));
    const desired = new Set([...connected].filter(id => guildMemberIds.has(id)));

    // toAdd = desired - currentHolders
    const toAdd = [...desired].filter(id => !currentHolders.has(id));
    // toRemove = currentHolders - desired
    const toRemove = [...currentHolders].filter(id => !desired.has(id));

    console.log(`🔧 Guild ${guild.name} (${guild.id}): add ${toAdd.length}, remove ${toRemove.length}`);

    // Apply adds
    for (const id of toAdd) {
      const member = members.get(id);
      if (!member) continue;
      await limiter(() => member.roles.add(role, 'DIQ Fantrax sync (add)').catch(() => {}));
    }

    // Apply removals
    for (const id of toRemove) {
      const member = members.get(id);
      if (!member) continue;
      await limiter(() => member.roles.remove(role, 'DIQ Fantrax sync (remove)').catch(() => {}));
    }
  }

  console.log('✅ Fantrax role sync complete.');
}

module.exports = { assignFantraxRole };
