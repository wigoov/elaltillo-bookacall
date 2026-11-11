// The theme also uses Tailwind with the same class names. Any plugin rule that is not
// limited to the booking widget would restyle the rest of the site (it once broke the footer).
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const css = readFileSync(new URL('../../assets/css/bookacall.min.css', import.meta.url), 'utf8');

/** Top-level selector lists of style rules (skips @keyframes and @property bodies). */
function selectors(source) {
	const found = [];
	const skip = /@(keyframes|property|font-face)[^{]*\{/g;
	let text = source.replace(/\/\*[\s\S]*?\*\//g, '');
	let match;
	while ((match = skip.exec(text))) {
		let depth = 0;
		let end = match.index + match[0].length - 1;
		for (; end < text.length; end++) {
			if (text[end] === '{') depth++;
			if (text[end] === '}' && --depth === 0) break;
		}
		text = text.slice(0, match.index) + text.slice(end + 1);
		skip.lastIndex = match.index;
	}
	const rule = /([^{};]+)\{/g;
	while ((match = rule.exec(text))) {
		const selector = match[1].trim();
		if (!selector.startsWith('@')) found.push(selector);
	}
	return found;
}

/** Split a selector list on commas outside parentheses (escaped commas belong to class names). */
function split(list) {
	const parts = [];
	let depth = 0;
	let current = '';
	let escaped = false;
	for (const char of list) {
		if (escaped) {
			current += char;
			escaped = false;
			continue;
		}
		if (char === '\\') {
			current += char;
			escaped = true;
			continue;
		}
		if (char === '(') depth++;
		if (char === ')') depth--;
		if (char === ',' && depth === 0) {
			parts.push(current);
			current = '';
		} else {
			current += char;
		}
	}
	return [...parts, current].map((part) => part.trim()).filter(Boolean);
}

test('no global variables on :root', () => {
	assert.equal(/:root|:host/.test(css), false);
});

test('every rule only reaches the booking widget', () => {
	const leaks = selectors(css).flatMap(split).filter((selector) => !selector.includes('data-elab-booking'));
	assert.deepEqual(leaks, []);
});

test('utilities the theme also uses are scoped (the footer case)', () => {
	for (const utility of ['flex-wrap', 'w-full', 'flex', 'grid']) {
		assert.match(css, new RegExp(`:where\\(\\[data-elab-booking\\],\\[data-elab-booking\\] \\*\\)\\.${utility}\\{`), utility);
		assert.doesNotMatch(css, new RegExp(`(^|[}])\\.${utility}\\{`), utility);
	}
});
