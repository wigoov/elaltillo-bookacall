import { build } from 'esbuild';
import { execFileSync } from 'node:child_process';

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
