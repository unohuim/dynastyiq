import { readFile, writeFile } from 'node:fs/promises';
import { existsSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const projectRoot = path.resolve(__dirname, '..');
const sourcePath = path.join(projectRoot, 'docs/designs/player_draft_card.png');
const outputPath = path.join(projectRoot, 'docs/designs/player_draft_card_modern.png');

const DESIGN_PROMPT = `
Restyle the provided hockey draft player card into a sleek, modern, premium sports-broadcast graphic.

Preserve all factual content exactly:
- Northumberland Nitro Selects
- DYNASTYIQ DRAFT ALERT
- Aspinall, Nathan
- L SELECTED BY Northumberland Nitro
- NYR
- OVERALL
- #43
- ROUND 3 | PICK 7
- 2025-26 | OHL | 65 | 33 | 61 | 94 | Flint Firebirds
- 2024-25 | OHL | 62 | 17 | 30 | 47 | Flint Firebirds
- DYNASTYIQ | FANTRAX DRAFT TRACKER
- LIVE DRAFT BOARD

Visual direction:
- dark navy / charcoal palette
- premium sports UI
- clean spacing and hierarchy
- refined typography
- subtle gradients
- restrained neon-green accents
- restrained blue highlights
- modern framed portrait treatment
- cleaner stat table
- softer separators
- balanced layout
- broadcast-quality finish
- sharp, minimal, luxurious, contemporary
`.trim();

async function main() {
  const apiKey = process.env.OPENAI_API_KEY;

  if (!apiKey) {
    throw new Error('Missing OPENAI_API_KEY. Set it before running this script.');
  }

  if (!existsSync(sourcePath)) {
    throw new Error(`Source image not found: ${sourcePath}`);
  }

  const sourceImage = await readFile(sourcePath);
  const form = new FormData();
  form.append('model', 'gpt-image-1');
  form.append('prompt', DESIGN_PROMPT);
  form.append('size', '1536x1024');
  form.append('quality', 'high');
  form.append('output_format', 'png');
  form.append('n', '1');
  form.append('image', new Blob([sourceImage], { type: 'image/png' }), 'player_draft_card.png');

  const response = await fetch('https://api.openai.com/v1/images/edits', {
    method: 'POST',
    headers: {
      Authorization: `Bearer ${apiKey}`,
    },
    body: form,
  });

  const payload = await response.json().catch(() => null);

  if (!response.ok) {
    const message = payload?.error?.message ?? response.statusText;
    throw new Error(`OpenAI image edit failed (${response.status}): ${message}`);
  }

  const imageBase64 = payload?.data?.[0]?.b64_json;

  if (!imageBase64) {
    throw new Error('OpenAI response did not include image data.');
  }

  await writeFile(outputPath, Buffer.from(imageBase64, 'base64'));
  console.log(`Saved redesigned draft card to ${outputPath}`);
}

main().catch((error) => {
  console.error(error instanceof Error ? error.message : String(error));
  process.exitCode = 1;
});
