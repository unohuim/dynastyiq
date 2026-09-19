/* @vitest-environment jsdom */

import { beforeEach, describe, expect, it } from 'vitest';
import { formatLocalDateTime, localizeDateTimes } from './starting-goalies.js';

const timestamp = '2026-10-01T23:00:00Z';

describe('local date and time formatting', () => {
    beforeEach(() => {
        document.body.innerHTML = '';
    });

    it('returns an empty string for a missing timestamp', () => {
        expect(formatLocalDateTime('')).toBe('');
    });

    it('returns an empty string for an invalid timestamp', () => {
        expect(formatLocalDateTime('not-a-date')).toBe('');
    });

    it('includes the year', () => {
        expect(formatLocalDateTime(timestamp, 'en-US', 'UTC')).toContain('2026');
    });

    it('uses a human-readable month', () => {
        expect(formatLocalDateTime(timestamp, 'en-US', 'UTC')).toContain('October');
    });

    it('includes the day of the month', () => {
        expect(formatLocalDateTime(timestamp, 'en-US', 'UTC')).toContain('1');
    });

    it('includes a weekday', () => {
        expect(formatLocalDateTime(timestamp, 'en-US', 'UTC')).toContain('Thu');
    });

    it('includes minutes', () => {
        expect(formatLocalDateTime(timestamp, 'en-US', 'UTC')).toContain(':00');
    });

    it('includes the utc timezone label', () => {
        expect(formatLocalDateTime(timestamp, 'en-US', 'UTC')).toMatch(/UTC|GMT/);
    });

    it('converts the time for Toronto', () => {
        expect(formatLocalDateTime(timestamp, 'en-US', 'America/Toronto')).toContain('7:00');
    });

    it('includes the Toronto timezone abbreviation', () => {
        expect(formatLocalDateTime(timestamp, 'en-US', 'America/Toronto')).toMatch(/EDT|GMT-4/);
    });

    it('converts the time for Vancouver', () => {
        expect(formatLocalDateTime(timestamp, 'en-US', 'America/Vancouver')).toContain('4:00');
    });

    it('includes the Vancouver timezone abbreviation', () => {
        expect(formatLocalDateTime(timestamp, 'en-US', 'America/Vancouver')).toMatch(/PDT|GMT-7/);
    });

    it('converts the date when the local timezone crosses midnight', () => {
        expect(formatLocalDateTime(timestamp, 'en-US', 'Europe/Helsinki')).toContain('October 2');
    });

    it('accepts timestamps with explicit offsets', () => {
        expect(formatLocalDateTime('2026-10-01T19:00:00-04:00', 'en-US', 'UTC')).toContain('11:00');
    });

    it('uses the supplied locale', () => {
        expect(formatLocalDateTime(timestamp, 'fr-CA', 'UTC')).toContain('octobre');
    });

    it('formats with the browser defaults when overrides are omitted', () => {
        expect(formatLocalDateTime(timestamp)).not.toBe('');
    });

    it('replaces the fallback text in a local datetime element', () => {
        document.body.innerHTML = `<time datetime="${timestamp}" data-local-datetime>Fallback</time>`;
        localizeDateTimes();
        expect(document.querySelector('time').textContent).not.toBe('Fallback');
    });

    it('leaves an invalid datetime fallback unchanged', () => {
        document.body.innerHTML = '<time datetime="invalid" data-local-datetime>Fallback</time>';
        localizeDateTimes();
        expect(document.querySelector('time').textContent).toBe('Fallback');
    });

    it('localizes every matching datetime element', () => {
        document.body.innerHTML = `<time datetime="${timestamp}" data-local-datetime>One</time><time datetime="${timestamp}" data-local-datetime>Two</time>`;
        localizeDateTimes();
        expect([...document.querySelectorAll('time')].every((element) => !['One', 'Two'].includes(element.textContent))).toBe(true);
    });

    it('limits localization to the supplied root', () => {
        document.body.innerHTML = `<section><time datetime="${timestamp}" data-local-datetime>Inside</time></section><time datetime="${timestamp}" data-local-datetime>Outside</time>`;
        localizeDateTimes(document.querySelector('section'));
        expect(document.querySelector('section time').textContent).not.toBe('Inside');
        expect(document.body.lastElementChild.textContent).toBe('Outside');
    });
});
