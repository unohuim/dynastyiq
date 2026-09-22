import { readFile } from 'node:fs/promises';
import { pathToFileURL } from 'node:url';

/** Reconstruct spatial rows without guessing names or dropping uncertain players. */
export function orderedLines(tsv, minimumConfidence = 0.7) {
    if (typeof tsv !== 'string') throw new Error('OCR returned no word coordinates.');
    if (!Number.isFinite(minimumConfidence) || minimumConfidence < 0 || minimumConfidence > 1) {
        throw new Error('Invalid confidence threshold.');
    }
    const items = [];
    for (const line of tsv.split(/\r?\n/)) {
        const fields = line.split('\t');
        if (fields[0] !== '5') continue;
        if (fields.length < 12) throw new Error('Invalid OCR word row.');
        const text = fields.slice(11).join(' ').trim();
        if (!text) continue;
        const [left, top, width, height, score] = fields.slice(6, 11).map(Number);
        if (![left, top, width, height, score].every(Number.isFinite)
            || left < 0 || top < 0 || width <= 0 || height <= 0 || score < 0 || score > 100) {
            throw new Error('Invalid OCR coordinates or confidence.');
        }
        items.push({ text, confidence: score / 100, box: [left, top, left + width, top + height] });
    }
    items.sort((a, b) => (a.box[1] + a.box[3]) / 2 - (b.box[1] + b.box[3]) / 2 || a.box[0] - b.box[0]);
    const rows = [];
    for (const item of items) {
        const previous = rows.at(-1);
        const reference = previous?.[0].box;
        const center = (item.box[1] + item.box[3]) / 2;
        const sameRow = reference && Math.abs(center - (reference[1] + reference[3]) / 2)
            <= Math.min(item.box[3] - item.box[1], reference[3] - reference[1]) * 0.45;
        if (sameRow) previous.push(item);
        else rows.push([item]);
    }
    const lines = rows.map((row) => {
        row.sort((a, b) => a.box[0] - b.box[0]);
        return { text: row.map((word) => word.text).join(' '), boxes: row };
    });
    const uncertain = items.some((word) => word.confidence < minimumConfidence);
    return {
        status: uncertain ? 'uncertain' : (lines.length ? 'ok' : 'empty'),
        text: uncertain ? '' : lines.map((line) => line.text).join('\n'),
        reason: uncertain ? 'Low-confidence image text requires review.' : 'Image text extracted.',
        lines,
    };
}

/** Run entirely with npm-shipped engine and language data, without runtime downloads. */
async function main() {
    const [imagePath, ...options] = process.argv.slice(2);
    if (!imagePath) throw new Error('A local image file is required.');
    const option = (name, fallback) => {
        const index = options.indexOf(name);
        return index < 0 ? fallback : Number(options[index + 1]);
    };
    const maxPixels = option('--max-pixels', 20_000_000);
    if (!Number.isSafeInteger(maxPixels) || maxPixels <= 0) throw new Error('Invalid pixel limit.');
    const image = await readFile(imagePath);
    const { imageSize } = await import('image-size');
    const dimensions = imageSize(image);
    if (!['png', 'jpg'].includes(dimensions.type)) throw new Error('Only JPEG and PNG photos are supported.');
    if (!dimensions.width || !dimensions.height || dimensions.width * dimensions.height > maxPixels) {
        throw new Error('Image exceeds pixel limit.');
    }
    const { createWorker, OEM, PSM } = await import('tesseract.js');
    const { default: english } = await import('@tesseract.js-data/eng');
    const worker = await createWorker('eng', OEM.LSTM_ONLY, {
        langPath: english.langPath,
        gzip: english.gzip,
        cacheMethod: 'none',
        logger: () => {},
        errorHandler: (error) => process.stderr.write(`${String(error)}\n`),
    });
    try {
        await worker.setParameters({ tessedit_pageseg_mode: PSM.SPARSE_TEXT, preserve_interword_spaces: '1' });
        const { data } = await worker.recognize(image, {}, { text: true, tsv: true });
        const output = orderedLines(data.tsv, option('--minimum-confidence', 0.7));
        process.stdout.write(JSON.stringify(output));
    } finally {
        await worker.terminate();
    }
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
    main().catch((error) => {
        process.stderr.write(`${error.message}\n`);
        // Also stop an initializing worker if an engine failure left it alive.
        process.exit(1);
    });
}
