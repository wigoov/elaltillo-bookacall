// Run with: pnpm test:js
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { checkFiles, extensionOf, formatSize, MAX_FILES, MAX_BYTES } from '../../src/js/files.js';

const file = (name, size = 1000) => ({ name, size });

test('only PDF, Word, Excel and PowerPoint are accepted', () => {
	const { accepted, errors } = checkFiles([], [file('a.pdf'), file('b.DOCX'), file('c.xlsx'), file('d.doc'), file('e.exe'), file('noextension')]);
	assert.deepEqual(accepted.map((f) => f.name), ['a.pdf', 'b.DOCX', 'c.xlsx']);
	assert.deepEqual(errors.map((e) => e.reason), ['type', 'type', 'type']);
});

test('files over 10 MB are refused with their name', () => {
	const { accepted, errors } = checkFiles([], [file('big.pdf', MAX_BYTES + 1), file('ok.pdf', MAX_BYTES)]);
	assert.deepEqual(accepted.map((f) => f.name), ['ok.pdf']);
	assert.deepEqual(errors, [{ reason: 'size', name: 'big.pdf' }]);
});

test('the same file is not attached twice', () => {
	const first = checkFiles([], [file('plan.pdf', 50)]);
	const again = checkFiles(first.accepted, [file('plan.pdf', 50)]);
	assert.equal(again.accepted.length, 1);
	assert.equal(again.errors[0].reason, 'duplicate');
});

test('at most three files, added to what was already chosen', () => {
	const first = checkFiles([], [file('1.pdf'), file('2.pdf')]);
	const second = checkFiles(first.accepted, [file('3.pptx'), file('4.xlsx')]);
	assert.equal(second.accepted.length, MAX_FILES);
	assert.deepEqual(second.errors, [{ reason: 'count', name: '4.xlsx' }]);
});

test('extension and readable sizes', () => {
	assert.equal(extensionOf('Propuesta.Final.PDF'), 'pdf');
	assert.equal(extensionOf('sin-extension'), '');
	assert.equal(formatSize(245 * 1024, 'en'), '245 KB');
	assert.equal(formatSize(3.4 * 1024 * 1024, 'es'), '3,4 MB');
	assert.equal(formatSize(10, 'en'), '1 KB');
});
