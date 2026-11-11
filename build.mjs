import { build } from 'esbuild';
import { execFileSync } from 'node:child_process';
import { readFileSync, writeFileSync } from 'node:fs';
import { createRequire } from 'node:module';

for (const [entry, source] of [['booking', 'public'], ['advisor-admin', 'advisor-admin']]) {
	await build({
		entryPoints: [`src/js/${source}.js`],
		outfile: `assets/js/${entry}.min.js`,
		bundle: true,
		minify: true,
		format: 'iife',
		target: ['es2020'],
	});
}

execFileSync('node_modules/.bin/tailwindcss', ['-i', 'src/css/bookacall.css', '-o', 'assets/css/bookacall.min.css', '--minify'], { stdio: 'inherit' });
scopeCss('assets/css/bookacall.min.css');

/**
 * The theme is built with Tailwind too, with the same class names. Our stylesheet loads
 * after it, so an unscoped `.flex-wrap` or `.w-full` here overrides the theme's own
 * `sm:flex-nowrap` / `sm:w-max` anywhere on the page (it broke the site footer).
 * Every rule is therefore limited to the booking widget and its contents, with
 * `:where()` so specificity does not change, and the theme variables Tailwind puts on
 * `:root` are moved onto the widget.
 */
function scopeCss(file) {
	const fromCli = createRequire(createRequire(import.meta.url).resolve('@tailwindcss/cli/package.json'));
	let lightning;
	try {
		lightning = fromCli('lightningcss');
	} catch {
		lightning = createRequire(fromCli.resolve('@tailwindcss/node'))('lightningcss');
	}
	const scope = { type: 'attribute', namespace: null, name: 'data-elab-booking', operation: null };
	const inScope = {
		type: 'pseudo-class',
		kind: 'where',
		selectors: [[scope], [scope, { type: 'combinator', value: 'descendant' }, { type: 'universal' }]],
	};
	const isRoot = (selector) => selector.length === 1 && selector[0].type === 'pseudo-class' && ['root', 'host'].includes(selector[0].kind);
	const { code } = lightning.transform({
		filename: file,
		code: readFileSync(file),
		minify: true,
		visitor: {
			// Only selectors are rewritten; declarations are left exactly as Tailwind wrote them.
			Selector(selector) {
				if (isRoot(selector)) {
					return [scope];
				}
				// A type or universal selector must stay first in its compound.
				const at = selector[0] && ['type', 'universal'].includes(selector[0].type) ? 1 : 0;
				return [...selector.slice(0, at), inScope, ...selector.slice(at)];
			},
		},
	});
	writeFileSync(file, code);
}
