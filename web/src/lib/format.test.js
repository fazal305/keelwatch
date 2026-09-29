import { describe, expect, it } from 'vitest';
import { formatAge, formatMs } from './format.js';

describe('formatMs', () => {
  it.each([
    [null, '—'],
    [4.24, '4.2 ms'],
    [821.4, '821 ms'],
    [1523, '1.5 s'],
    [59_949, '59.9 s'],
    [120_000, '2 min'],
    [125_000, '2 min 5 s'],
  ])('%s -> %s', (input, expected) => {
    expect(formatMs(input)).toBe(expected);
  });
});

describe('formatAge', () => {
  it.each([
    [null, '—'],
    [0.5, 'just now'],
    [42, '42s ago'],
    [125, '2m ago'],
    [7200, '2h ago'],
    [90_000, '1d ago'],
  ])('%s s -> %s', (input, expected) => {
    expect(formatAge(input)).toBe(expected);
  });
});
