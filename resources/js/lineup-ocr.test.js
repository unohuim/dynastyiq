// @vitest-environment node
import { describe, expect, it } from 'vitest';
import { orderedLines } from '../../scripts/lineup-ocr.mjs';

describe('lineup OCR word layout', () => {
    const word = (text, left = 0, top = 0, score = 95, width = 30, height = 10) =>
        `5\t1\t1\t1\t1\t1\t${left}\t${top}\t${width}\t${height}\t${score}\t${text}`;

    it('returns empty for an empty page', () => {
        expect(orderedLines('')).toMatchObject({ status: 'empty', text: '', lines: [] });
    });
    it('ignores the TSV header', () => {
        expect(orderedLines('level\tpage_num\tblock_num')).toMatchObject({ status: 'empty' });
    });
    it('ignores non-word page and paragraph records', () => {
        expect(orderedLines(word('Ignored').replace(/^5/, '3')).text).toBe('');
    });
    it('preserves a recognized player name', () => {
        expect(orderedLines(word('Matthews')).text).toBe('Matthews');
    });
    it('sorts words left to right', () => {
        expect(orderedLines([word('Marner', 100), word('Matthews', 0)].join('\n')).text).toBe('Matthews Marner');
    });
    it('sorts rows top to bottom', () => {
        expect(orderedLines([word('Second', 0, 50), word('First')].join('\n')).text).toBe('First\nSecond');
    });
    it('groups three horizontally aligned forwards', () => {
        expect(orderedLines([word('Knies'), word('Matthews', 100), word('Marner', 200)].join('\n')).lines).toHaveLength(1);
    });
    it('keeps four forward rows separate', () => {
        const text = [0, 30, 60, 90].map((top) => [word('Left', 0, top), word('Center', 100, top), word('Right', 200, top)].join('\n')).join('\n');
        expect(orderedLines(text).lines).toHaveLength(4);
    });
    it('keeps defensive pairings on separate rows', () => {
        expect(orderedLines([word('Rielly'), word('Tanev', 100), word('McCabe', 0, 30), word('Benoit', 100, 30)].join('\n')).text)
            .toBe('Rielly Tanev\nMcCabe Benoit');
    });
    it('tolerates small baseline offsets in a row', () => {
        expect(orderedLines([word('First'), word('Second', 50, 2)].join('\n')).text).toBe('First Second');
    });
    it('preserves apostrophes and accents', () => {
        expect(orderedLines(word('O’Reilly') + '\n' + word('Šimek', 100)).text).toBe('O’Reilly Šimek');
    });
    it('normalizes confidence to the shared zero-to-one scale', () => {
        expect(orderedLines(word('Player', 0, 0, 85)).lines[0].boxes[0].confidence).toBe(0.85);
    });
    it('withholds an entire image if one word is uncertain', () => {
        expect(orderedLines(word('Known') + '\n' + word('Uncertain', 50, 0, 40))).toMatchObject({ status: 'uncertain', text: '' });
    });
    it('retains uncertain words in audit rows without dropping names', () => {
        expect(orderedLines(word('Uncertain', 0, 0, 40)).lines[0].text).toBe('Uncertain');
    });
    it('accepts confidence exactly at the threshold', () => {
        expect(orderedLines(word('Player', 0, 0, 70)).status).toBe('ok');
    });
    it('supports an explicitly configured confidence threshold', () => {
        expect(orderedLines(word('Player', 0, 0, 80), 0.9).status).toBe('uncertain');
    });
    it('skips blank recognized text', () => {
        expect(orderedLines(word('   ')).status).toBe('empty');
    });
    it('handles Windows line endings', () => {
        expect(orderedLines(word('One') + '\r\n' + word('Two', 100)).text).toBe('One Two');
    });
    it('rejects a missing TSV payload', () => {
        expect(() => orderedLines(undefined)).toThrow('coordinates');
    });
    it('rejects truncated word records', () => {
        expect(() => orderedLines('5\t1')).toThrow('word row');
    });
    it('rejects nonfinite coordinates', () => {
        expect(() => orderedLines(word('Name', Infinity))).toThrow('coordinates');
    });
    it('rejects zero-sized word boxes', () => {
        expect(() => orderedLines(word('Name', 0, 0, 95, 0))).toThrow('coordinates');
    });
    it('rejects negative confidence', () => {
        expect(() => orderedLines(word('Name', 0, 0, -1))).toThrow('confidence');
    });
    it('rejects confidence above one hundred', () => {
        expect(() => orderedLines(word('Name', 0, 0, 101))).toThrow('confidence');
    });
    it('rejects invalid configured thresholds', () => {
        expect(() => orderedLines('', 2)).toThrow('threshold');
    });
});
