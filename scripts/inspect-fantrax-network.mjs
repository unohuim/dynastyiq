#!/usr/bin/env node

import fs from "node:fs";
import path from "node:path";

const DEFAULT_URL = "https://www.fantrax.com/fantasy/league/uf1sdl47mo6nzpr6/home;reload=1";
const DEFAULT_NEEDLES = [
  "fantraximg.com",
  "tmLogo_",
  "teamLogo",
  "logoUrl",
  "logoUrl128",
  "fantasyTeams",
  "leaguesTeams",
  "matchupId",
  "matchups__row",
  "background-image",
];
const LOGO_RESPONSE_DUMP_PATH = path.resolve("docs", "api_responses", "fantrax_logos.txt");
const LOGO_WAIT_MS = 15000;
const LOGIN_WAIT_MS = 180000;

function parseArgs(argv) {
  const options = {
    url: DEFAULT_URL,
    headless: false,
    json: false,
    appendDump: false,
    waitForLogin: false,
    profile: process.env.FANTRAX_BROWSER_PROFILE_PATH
      || process.env.FANTRAX_INSPECT_PROFILE
      || "/tmp/dynastyiq-fantrax-profile",
    needles: [...DEFAULT_NEEDLES],
  };

  for (let index = 0; index < argv.length; index += 1) {
    const arg = argv[index];

    if (arg === "--headless") {
      options.headless = true;
      continue;
    }

    if (arg === "--json") {
      options.json = true;
      continue;
    }

    if (arg === "--append-dump") {
      options.appendDump = true;
      continue;
    }

    if (arg === "--wait-for-login") {
      options.waitForLogin = true;
      continue;
    }

    if (arg === "--profile") {
      options.profile = argv[index + 1] || options.profile;
      index += 1;
      continue;
    }

    if (arg === "--needle") {
      const needle = argv[index + 1];

      if (needle) {
        options.needles.push(needle);
      }

      index += 1;
      continue;
    }

    if (arg === "--help" || arg === "-h") {
      options.help = true;
      continue;
    }

    if (!arg.startsWith("--")) {
      options.url = arg;
    }
  }

  return options;
}

function printHelp() {
  console.log(`
Inspect Fantrax browser network traffic for logo/matchup payloads.

Usage:
  node scripts/inspect-fantrax-network.mjs [url] [--needle value] [--profile path] [--headless] [--json] [--append-dump] [--wait-for-login]

Examples:
  node scripts/inspect-fantrax-network.mjs
  node scripts/inspect-fantrax-network.mjs "https://www.fantrax.com/fantasy/league/uf1sdl47mo6nzpr6/home;reload=1" --needle 3p7cwizlmo6nzpre

Notes:
  - Uses a persistent Chromium profile outside the repo by default:
    /tmp/dynastyiq-fantrax-profile
  - If Fantrax requires login, run without --headless, log in, then reload.
  - The script prints XHR/fetch/document excerpts and fantraximg.com image responses.
  - Inspection files are written as you browse, using the current league and page route,
    for example league_uf1sdl47mo6nzpr6_home_reload_1.txt.
  - Use --json with --headless for app-driven logo extraction.
  - Use --wait-for-login with visible Chromium when the user should log in and let
    the script close the browser after logo payloads are captured.
`);
}

function isFantraxLeagueUrl(url) {
  try {
    const parsed = new URL(url);

    return parsed.hostname.endsWith("fantrax.com")
      && parsed.pathname.includes("/fantasy/league/");
  } catch {
    return false;
  }
}

function inspectionPathForUrl(url) {
  const parsed = new URL(url);
  const leagueMatch = parsed.pathname.match(/\/fantasy\/league\/([^/]+)/);
  const routeParts = parsed.pathname
    .replace(/^\/+|\/+$/g, "")
    .split("/")
    .filter(Boolean);
  const leagueIndex = routeParts.findIndex((part) => part === "league");
  const sectionParts = leagueIndex === -1 ? [] : routeParts.slice(leagueIndex + 2);
  const sectionSource = [sectionParts.join("_"), parsed.search, parsed.hash]
    .filter(Boolean)
    .join("_");
  const section = sectionSource.replace(/[^a-z0-9]+/gi, "_").replace(/^_+|_+$/g, "");
  const fallback = parsed.pathname.replace(/[^a-z0-9]+/gi, "_").replace(/^_+|_+$/g, "");
  const baseName = leagueMatch
    ? ["league", leagueMatch[1], section || "page"].join("_")
    : fallback;
  const filename = `${baseName || "fantrax_inspection"}.txt`;

  return path.resolve("docs", "inspection", filename);
}

function inspectionUrlForResponse(pageUrl, responseUrl) {
  if (isFantraxLeagueUrl(pageUrl)) {
    return pageUrl;
  }

  return responseUrl;
}

function ensureInspectionFile(filePath, inspectedUrl, options, initializedFiles) {
  if (initializedFiles.has(filePath)) {
    return;
  }

  const header = [
    `Fantrax network inspection`,
    `Inspected URL: ${inspectedUrl}`,
    `Started at: ${new Date().toISOString()}`,
    `Profile: ${options.profile}`,
    `Needles: ${options.needles.join(", ")}`,
    "",
  ].join("\n");

  fs.mkdirSync(path.dirname(filePath), { recursive: true });
  fs.writeFileSync(filePath, header);
  initializedFiles.add(filePath);
}

function appendInspection(filePath, text) {
  fs.mkdirSync(path.dirname(filePath), { recursive: true });
  fs.appendFileSync(filePath, text);
}

function initializeLogoResponseDump(options) {
  fs.mkdirSync(path.dirname(LOGO_RESPONSE_DUMP_PATH), { recursive: true });
  const writer = options.appendDump ? fs.appendFileSync : fs.writeFileSync;

  writer(LOGO_RESPONSE_DUMP_PATH, [
    "",
    "============================================================",
    "Chromium inspector run",
    "Fantrax logo raw browser responses",
    `Started at: ${new Date().toISOString()}`,
    `Initial URL: ${options.url}`,
    `Profile: ${options.profile}`,
    "",
  ].join("\n"));
}

function appendLogoResponseDump({ url, status, type, method, text }) {
  const output = [
    "",
    "============================================================",
    `Captured at: ${new Date().toISOString()}`,
    `TYPE: ${type}`,
    `METHOD: ${method}`,
    `STATUS: ${status}`,
    `URL: ${url}`,
    "",
    "--- RAW RESPONSE BODY ---",
    text,
    "",
  ].join("\n");

  fs.appendFileSync(LOGO_RESPONSE_DUMP_PATH, output);
}

function appendLogoTrace(message) {
  fs.appendFileSync(
    LOGO_RESPONSE_DUMP_PATH,
    `[${new Date().toISOString()}] ${message}\n`,
  );
}

function excerptMatches(text, needles) {
  const excerpts = [];

  for (const needle of needles) {
    const needleIndex = text.indexOf(needle);

    if (needleIndex === -1) {
      continue;
    }

    const start = Math.max(needleIndex - 700, 0);
    const end = Math.min(needleIndex + needle.length + 1400, text.length);
    excerpts.push({
      needle,
      excerpt: text.slice(start, end),
    });
  }

  return excerpts;
}

function hasLogoNeedle(text) {
  return DEFAULT_NEEDLES.some((needle) => text.includes(needle));
}

function isFantraxApiRequest(url) {
  return url.includes("fantrax.com/fxpa/req");
}

function isRelevantResponse(url, type) {
  if (isFantraxApiRequest(url)) {
    return true;
  }

  if (type === "image" && url.includes("fantraximg.com")) {
    return true;
  }

  try {
    const parsed = new URL(url);

    return parsed.hostname.endsWith("fantrax.com")
      && parsed.pathname.includes("/fantasy/league/");
  } catch {
    return false;
  }
}

function isAccessDeniedText(text) {
  const normalized = text.toLowerCase();

  return normalized.includes("not in this league")
    || normalized.includes("you are not in this league")
    || normalized.includes("access denied")
    || normalized.includes("not authorized");
}

function requestBodyFor(request) {
  const method = request.method();

  if (!["POST", "PUT", "PATCH"].includes(method)) {
    return null;
  }

  const postData = request.postData();

  if (!postData) {
    return null;
  }

  return postData.length > 5000 ? `${postData.slice(0, 5000)}\n... [truncated]` : postData;
}

function formatMatch({ url, status, type, method, postData, matches }) {
  const lines = [
    "",
    "============================================================",
    `TYPE: ${type}`,
    `METHOD: ${method}`,
    `STATUS: ${status}`,
    `URL: ${url}`,
  ];

  if (postData) {
    lines.push("", "--- REQUEST BODY ---", postData);
  }

  for (const match of matches) {
    lines.push("", `--- MATCH: ${match.needle} ---`, match.excerpt);
  }

  return `${lines.join("\n")}\n`;
}

function printAndWriteMatch(filePath, inspectedUrl, match) {
  const output = formatMatch(match);

  if (!options.json) {
    console.log(output);
    console.log(`Wrote match for ${inspectedUrl} to ${filePath}`);
  }

  appendInspection(filePath, output);
}

function stringValue(value) {
  return typeof value === "string" && value.trim() !== "" ? value.trim() : null;
}

function logoValueFromObject(object) {
  const logoKeys = [
    "teamLogo",
    "teamLogoUrl",
    "teamLogoURL",
    "logoUrl128",
    "logoUrl",
    "logoURL",
    "logo",
    "imageUrl",
    "imageURL",
    "avatarUrl",
    "avatarURL",
  ];

  for (const key of logoKeys) {
    const value = stringValue(object[key]);

    if (value && (value.includes("fantraximg.com") || value.startsWith("/assets/"))) {
      return value.startsWith("/") ? `https://fantraximg.com${value}` : value;
    }
  }

  return null;
}

function teamIdValueFromObject(object) {
  const idKeys = ["teamId", "fantasyTeamId", "myDefaultTeamId", "id"];

  for (const key of idKeys) {
    const value = stringValue(object[key]);

    if (value) {
      return value;
    }
  }

  return null;
}

function addLogoCandidate(candidates, candidate) {
  if (!candidate.teamId || !candidate.logoUrl) {
    return;
  }

  const key = [
    candidate.leagueId || "",
    candidate.teamId,
    candidate.logoUrl,
  ].join("|");

  candidates.set(key, candidate);
}

function collectLogoCandidatesFromJson(value, sourceUrl, candidates, inherited = {}) {
  if (Array.isArray(value)) {
    for (const item of value) {
      collectLogoCandidatesFromJson(item, sourceUrl, candidates, inherited);
    }

    return;
  }

  if (!value || typeof value !== "object") {
    return;
  }

  const leagueId = stringValue(value.leagueId) || stringValue(value.leagueID) || inherited.leagueId || null;
  const leagueName = stringValue(value.league) || stringValue(value.leagueName) || inherited.leagueName || null;
  const teamId = teamIdValueFromObject(value);
  const teamName = stringValue(value.team) || stringValue(value.teamName) || stringValue(value.name) || null;
  const logoUrl = logoValueFromObject(value);

  if (teamId && logoUrl) {
    addLogoCandidate(candidates, {
      leagueId,
      leagueName,
      teamId,
      teamName,
      logoUrl,
      sourceUrl,
      source: "json",
    });
  }

  const nextInherited = {
    leagueId,
    leagueName,
  };

  for (const child of Object.values(value)) {
    collectLogoCandidatesFromJson(child, sourceUrl, candidates, nextInherited);
  }
}

function collectLogoCandidatesFromText(text, sourceUrl, candidates) {
  try {
    collectLogoCandidatesFromJson(JSON.parse(text), sourceUrl, candidates);
  } catch {
    // Fantrax document responses are often HTML or script-wrapped data; regex below handles useful fragments.
  }

  const escapedJsonPatterns = [
    /"teamLogo":"([^"]+)"(?:,"draftDate":[^,{}]+)?,"leagueId":"([^"]+)","league":"([^"]*)","teamId":"([^"]+)","team":"([^"]*)"/g,
    /"teamId":"([^"]+)","team":"([^"]*)"[^{}]*?"teamLogo":"([^"]+)"/g,
    /"id":"([^"]+)"[^{}]*?"logoUrl128":"([^"]+)"[^{}]*?"name":"([^"]*)"/g,
    /"teamLogo":"([^"]+)"[^{}]*?"myDefaultTeamId":"([^"]+)"/g,
  ];

  let match;

  while ((match = escapedJsonPatterns[0].exec(text)) !== null) {
    addLogoCandidate(candidates, {
      logoUrl: match[1],
      leagueId: match[2],
      leagueName: match[3],
      teamId: match[4],
      teamName: match[5],
      sourceUrl,
      source: "text",
    });
  }

  while ((match = escapedJsonPatterns[1].exec(text)) !== null) {
    addLogoCandidate(candidates, {
      teamId: match[1],
      teamName: match[2],
      logoUrl: match[3],
      sourceUrl,
      source: "text",
    });
  }

  while ((match = escapedJsonPatterns[2].exec(text)) !== null) {
    addLogoCandidate(candidates, {
      teamId: match[1],
      logoUrl: match[2],
      teamName: match[3],
      sourceUrl,
      source: "text",
    });
  }

  while ((match = escapedJsonPatterns[3].exec(text)) !== null) {
    addLogoCandidate(candidates, {
      logoUrl: match[1],
      teamId: match[2],
      sourceUrl,
      source: "text",
    });
  }
}

const options = parseArgs(process.argv.slice(2));

if (options.help) {
  printHelp();
  process.exit(0);
}

let chromium;

try {
  ({ chromium } = await import("playwright"));
} catch {
  console.error("Playwright is not installed in this project.");
  console.error("Install it locally with:");
  console.error("  npm install -D playwright");
  console.error("  npx playwright install chromium");
  process.exit(1);
}

const context = await chromium.launchPersistentContext(options.profile, {
  headless: options.headless,
  viewport: { width: 1440, height: 1000 },
});
const page = await context.newPage();
const initializedFiles = new Set();
const logoCandidates = new Map();
const pendingResponses = new Set();
let logoPayloadResponseCount = 0;
let accessDeniedDetected = false;
let usefulLogoPayloadFound = false;
let loginWaitExpired = false;
const markers = [];
initializeLogoResponseDump(options);

if (!options.json) {
  console.log(`Opening: ${options.url}`);
  console.log(`Profile: ${options.profile}`);
  console.log(`Needles: ${options.needles.join(", ")}`);
  console.log("Writing inspection output to docs/inspection based on the current Fantrax page URL.");
}

page.on("response", (response) => {
  const request = response.request();
  const type = request.resourceType();
  const responseUrl = response.url();

  if (!isRelevantResponse(responseUrl, type)) {
    return;
  }

  const pendingResponse = (async () => {
    const status = response.status();
    const inspectedUrl = inspectionUrlForResponse(page.url(), responseUrl);
    const inspectionFile = inspectionPathForUrl(inspectedUrl);

    if (["document", "xhr", "fetch"].includes(type)) {
      appendLogoTrace(`RESPONSE ${status} ${type.toUpperCase()} ${request.method()} ${responseUrl}`);
    }

    if (type === "image" && responseUrl.includes("fantraximg.com")) {
      ensureInspectionFile(inspectionFile, inspectedUrl, options, initializedFiles);
      printAndWriteMatch(inspectionFile, inspectedUrl, {
        url: responseUrl,
        status,
        type,
        method: request.method(),
        postData: requestBodyFor(request),
        matches: [{ needle: "fantraximg.com image", excerpt: responseUrl }],
      });
      return;
    }

    if (!["document", "xhr", "fetch"].includes(type)) {
      return;
    }

    const text = await response.text().catch(() => "");

    if (!text) {
      return;
    }

    if (isAccessDeniedText(text)) {
      accessDeniedDetected = true;
      appendLogoTrace(`ACCESS_DENIED_RESPONSE ${responseUrl}`);
    }

    const matches = excerptMatches(text, options.needles);

    if ((hasLogoNeedle(text) || isFantraxApiRequest(responseUrl)) && type !== "document") {
      logoPayloadResponseCount += 1;
      appendLogoResponseDump({
        url: responseUrl,
        status,
        type,
        method: request.method(),
        text,
      });
    }

    collectLogoCandidatesFromText(text, responseUrl, logoCandidates);

    if (logoCandidates.size > 0) {
      usefulLogoPayloadFound = true;
    }

    if (matches.length === 0) {
      return;
    }

    ensureInspectionFile(inspectionFile, inspectedUrl, options, initializedFiles);
    printAndWriteMatch(inspectionFile, inspectedUrl, {
      url: responseUrl,
      status,
      type,
      method: request.method(),
      postData: requestBodyFor(request),
      matches,
    });
  })();

  pendingResponses.add(pendingResponse);
  pendingResponse.finally(() => pendingResponses.delete(pendingResponse));
});

await page.goto(options.url, { waitUntil: "domcontentloaded" });
const waitStartedAt = Date.now();
const loginWaitStartedAt = Date.now();
const maxWaitMs = options.waitForLogin ? LOGIN_WAIT_MS : LOGO_WAIT_MS;

while (
  !usefulLogoPayloadFound
  && !accessDeniedDetected
  && Date.now() - waitStartedAt < maxWaitMs
) {
  if (page.url().includes("/login") && !options.waitForLogin) {
    break;
  }

  if (page.url().includes("/login") && Date.now() - loginWaitStartedAt >= LOGIN_WAIT_MS) {
    loginWaitExpired = true;
    break;
  }

  await page.waitForTimeout(250);
}

const responseDrainStartedAt = Date.now();

while (pendingResponses.size > 0 && Date.now() - responseDrainStartedAt < 1500) {
  await Promise.allSettled(Array.from(pendingResponses));
}

if (pendingResponses.size > 0) {
  appendLogoTrace(`PENDING_RESPONSE_DRAIN_TIMEOUT ${pendingResponses.size}`);
}

if (usefulLogoPayloadFound) {
  markers.push("USEFUL_LOGO_PAYLOAD_FOUND");
  appendLogoTrace("USEFUL_LOGO_PAYLOAD_FOUND");
}

if (accessDeniedDetected) {
  markers.push("ACCESS_DENIED_DETECTED");
  appendLogoTrace("ACCESS_DENIED_DETECTED");
}

if (logoPayloadResponseCount === 0) {
  markers.push("NO_LOGO_XHR_FOUND");
  appendLogoTrace("NO_LOGO_XHR_FOUND");
}

if (page.url().includes("/login")) {
  markers.push("LOGIN_ROUTE_DETECTED");
  appendLogoTrace(`LOGIN_ROUTE_DETECTED ${page.url()}`);
}

if (loginWaitExpired) {
  markers.push("LOGIN_WAIT_EXPIRED");
  appendLogoTrace("LOGIN_WAIT_EXPIRED");
}

appendLogoTrace(`CANDIDATE_COUNT ${logoCandidates.size}`);

if (options.json) {
  console.log(JSON.stringify({
    ok: true,
    url: options.url,
    profile: options.profile,
    markers,
    logoCandidates: Array.from(logoCandidates.values()),
  }));
  await context.close();
  process.exit(0);
}

console.log("\nPage loaded. If you need to log in, do it in the browser, then reload the page.");
console.log("Press Enter here to close.");

process.stdin.resume();
process.stdin.once("data", async () => {
  await context.close();
});
