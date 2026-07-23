#!/usr/bin/env node

import { chromium } from "playwright";

function parseArgs(argv) {
  const options = {
    leagueId: "",
    profile: process.env.FANTRAX_BROWSER_PROFILE_PATH || "",
    headless: false,
    timeout: 45000,
    view: "CLAIM_DROP",
  };

  for (let index = 0; index < argv.length; index += 1) {
    const arg = argv[index];

    if (arg === "--league-id") {
      options.leagueId = argv[index + 1] || "";
      index += 1;
      continue;
    }

    if (arg === "--profile") {
      options.profile = argv[index + 1] || "";
      index += 1;
      continue;
    }

    if (arg === "--view") {
      options.view = argv[index + 1] || "";
      index += 1;
      continue;
    }

    if (arg === "--headless") {
      options.headless = true;
      continue;
    }

    if (arg === "--timeout") {
      options.timeout = Number(argv[index + 1] || options.timeout);
      index += 1;
    }
  }

  return options;
}

function fail(message, details = {}) {
  console.log(JSON.stringify({
    ok: false,
    message,
    ...details,
  }, null, 2));
  process.exitCode = 1;
}

const options = parseArgs(process.argv.slice(2));
const allowedViews = new Set(["CLAIM_DROP", "TRADE", "LINEUP_CHANGE"]);

if (!options.leagueId) {
  fail("Missing --league-id.");
  process.exit();
}

if (!allowedViews.has(options.view)) {
  fail("Invalid --view.");
  process.exit();
}

if (!options.profile) {
  fail("Missing --profile.");
  process.exit();
}

const pageUrl = `https://www.fantrax.com/fantasy/league/${options.leagueId}/transactions/history;view=${options.view}`;
const rpcUrl = `https://www.fantrax.com/fxpa/req?leagueId=${options.leagueId}`;
const body = {
  msgs: [
    { method: "getTransactionDetailsHistory", data: { leagueId: options.leagueId, view: options.view } },
    { method: "getFantasyLeagueInfo", data: {} },
    { method: "getFantasyTeams", data: {} },
  ],
  uiv: 3,
  refUrl: pageUrl,
  dt: 0,
  at: 0,
  tz: "America/Toronto",
  v: "184.0.10",
};

let context;

try {
  context = await chromium.launchPersistentContext(options.profile, {
    headless: options.headless,
  });

  const page = await context.newPage();
  await page.goto(pageUrl, {
    waitUntil: "domcontentloaded",
    timeout: options.timeout,
  });

  const result = await page.evaluate(async ({ rpcUrl, body }) => {
    const response = await fetch(rpcUrl, {
      method: "POST",
      headers: {
        accept: "application/json",
        "content-type": "application/json",
      },
      body: JSON.stringify(body),
    });
    const text = await response.text();
    let json = null;

    try {
      json = JSON.parse(text);
    } catch {
      json = null;
    }

    return {
      status: response.status,
      ok: response.ok,
      contentType: response.headers.get("content-type"),
      json,
      text: json === null ? text : null,
    };
  }, { rpcUrl, body });

  console.log(JSON.stringify({
    ok: result.ok,
    page_url: pageUrl,
    rpc_url: rpcUrl,
    view: options.view,
    current_url: page.url(),
    request_body: body,
    response: result,
  }, null, 2));
} catch (error) {
  fail(error?.message || "Fantrax transaction browser RPC failed.", {
    page_url: pageUrl,
    rpc_url: rpcUrl,
  });
} finally {
  if (context) {
    await context.close();
  }
}
