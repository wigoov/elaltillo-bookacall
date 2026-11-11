// Run with: pnpm test:js
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { checkGuest, MAX_GUESTS } from '../../src/js/guests.js';

test('accepts a valid email and trims it', () => {
	assert.deepEqual(checkGuest('  ana@example.test '), { ok: true, email: 'ana@example.test' });
});

test('empty input is reported as empty', () => {
	assert.equal(checkGuest('   ').reason, 'empty');
});

test('rejects malformed addresses', () => {
	for (const value of ['ana', 'ana@', '@example.test', 'ana@example', 'a b@example.test', 'a,b@example.test']) {
		assert.equal(checkGuest(value).reason, 'invalid', value);
	}
});

test('rejects the visitor’s own address, ignoring case', () => {
	assert.equal(checkGuest('Me@Example.test', [], 'me@example.test').reason, 'self');
});

test('rejects duplicates, ignoring case', () => {
	assert.equal(checkGuest('ANA@example.test', ['ana@example.test']).reason, 'duplicate');
});

test(`stops at ${MAX_GUESTS} guests`, () => {
	const full = Array.from({ length: MAX_GUESTS }, (_, i) => `g${i}@example.test`);
	assert.equal(checkGuest('new@example.test', full).reason, 'full');
	assert.equal(checkGuest('new@example.test', full.slice(1)).ok, true);
});

test('matches the server limit of 5', () => {
	assert.equal(MAX_GUESTS, 5);
});
