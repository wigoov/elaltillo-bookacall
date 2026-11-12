/**
 * Attachments the visitor picks or drops: one card per file with its type, size and, for a
 * PDF, a thumbnail of the first page and its page count, so they can see exactly what will be
 * sent before booking. Files are checked here (type, size, count) and again on the server.
 * PDF.js loads only when a PDF is chosen; it never runs anything inside the PDF.
 */

export const MAX_FILES = 3;
export const MAX_BYTES = 10 * 1024 * 1024;
export const TYPES = { pdf: 'PDF', docx: 'Word', xlsx: 'Excel', pptx: 'PowerPoint' };

export function extensionOf(name) {
	const match = /\.([a-z0-9]+)$/i.exec(String(name || ''));
	return match ? match[1].toLowerCase() : '';
}

/**
 * Add `incoming` files to `current`. Pure, so it is unit-tested on its own.
 * @returns {{accepted: File[], errors: {reason: 'type'|'size'|'duplicate'|'count', name: string}[]}}
 */
export function checkFiles(current, incoming) {
	const accepted = [...current];
	const errors = [];
	for (const file of incoming) {
		const name = file.name;
		if (!TYPES[extensionOf(name)]) errors.push({ reason: 'type', name });
		else if (file.size > MAX_BYTES) errors.push({ reason: 'size', name });
		else if (accepted.some((kept) => kept.name === name && kept.size === file.size)) errors.push({ reason: 'duplicate', name });
		else if (accepted.length >= MAX_FILES) errors.push({ reason: 'count', name });
		else accepted.push(file);
	}
	return { accepted, errors };
}

/** "245 KB", "3,4 MB" in the visitor's language. */
export function formatSize(bytes, locale = 'en') {
	const megabytes = bytes >= 1024 * 1024;
	const value = megabytes ? bytes / (1024 * 1024) : Math.max(1, Math.round(bytes / 1024));
	const number = new Intl.NumberFormat(locale, { maximumFractionDigits: megabytes ? 1 : 0 }).format(value);
	return `${number} ${megabytes ? 'MB' : 'KB'}`;
}

/** Wire the attachments field inside `root`. `t` translates; `config` carries the PDF.js URLs. */
export function initFiles(root, t, config = {}, locale = 'en') {
	const box = root.querySelector('[data-elab-files-box]');
	if (!box) return { reset() {} };
	const input = box.querySelector('input[type="file"]');
	const zone = box.querySelector('[data-elab-dropzone]');
	const hint = box.querySelector('[data-elab-files]');
	const list = box.querySelector('[data-elab-file-list]');
	const error = box.querySelector('[data-elab-file-error]');
	const hintText = hint.textContent;
	let files = [];
	let pdfjs = null;

	const loadPdf = () => {
		pdfjs ??= import(config.pdfjs).then((lib) => {
			lib.GlobalWorkerOptions.workerSrc = config.pdfWorker;
			return lib;
		});
		return pdfjs;
	};

	// The browser sends what the input holds: keep it equal to the cards on screen.
	const syncInput = () => {
		try {
			const transfer = new DataTransfer();
			files.forEach((file) => transfer.items.add(file));
			input.files = transfer.files;
		} catch (problem) {
			// Very old browsers: the input keeps the last selection, the server still checks it.
		}
	};

	const showErrors = (errors) => {
		error.textContent = errors.map((item) => t(`file_${item.reason}`, item.name, MAX_FILES)).join(' ');
		error.hidden = !errors.length;
	};

	async function previewPdf(file, thumb, meta, warning, ready) {
		let task = null;
		try {
			const lib = await loadPdf();
			task = lib.getDocument({ data: new Uint8Array(await file.arrayBuffer()), useWasm: false, enableXfa: false });
			const pdf = await task.promise;
			const page = await pdf.getPage(1);
			const ratio = window.devicePixelRatio || 1;
			const viewport = page.getViewport({ scale: (thumb.clientWidth || 44) * ratio / page.getViewport({ scale: 1 }).width });
			const canvas = document.createElement('canvas');
			canvas.width = Math.floor(viewport.width);
			canvas.height = Math.floor(viewport.height);
			canvas.className = 'block h-auto w-full';
			await page.render({ canvas, viewport, annotationMode: lib.AnnotationMode.DISABLE }).promise;
			thumb.replaceChildren(canvas);
			thumb.classList.replace('bg-accent', 'bg-white');
			thumb.classList.replace('items-center', 'items-start');
			meta.textContent += ` · ${pdf.numPages === 1 ? t('fileOnePage') : t('filePages', pdf.numPages)}`;
		} catch (problem) {
			// Encrypted or damaged: the advisor would not be able to open it either.
			warning.hidden = false;
			ready.hidden = true;
		}
		// Free the worker; a failure here says nothing about the file.
		try {
			await task?.destroy();
		} catch (problem) {
			// Nothing to do.
		}
	}

	const card = (file) => {
		const extension = extensionOf(file.name);
		const item = document.createElement('li');
		item.className = 'flex items-center gap-3 rounded-xl border border-secondary/15 bg-white/70 p-2 pr-2.5';
		const thumb = document.createElement('div');
		thumb.className = 'flex h-14 w-11 shrink-0 items-center justify-center overflow-hidden rounded-md border border-secondary/10 bg-accent font-secondary text-[0.6rem] font-semibold uppercase tracking-wider text-secondary';
		thumb.setAttribute('aria-hidden', 'true');
		thumb.textContent = extension;
		const info = document.createElement('div');
		info.className = 'grid min-w-0 flex-1 gap-0.5';
		const name = document.createElement('p');
		name.className = 'truncate font-primary text-sm text-secondary';
		name.textContent = file.name;
		const meta = document.createElement('p');
		meta.className = 'font-secondary text-xs text-secondary/60';
		meta.textContent = `${TYPES[extension]} · ${formatSize(file.size, locale)}`;
		const ready = document.createElement('p');
		ready.className = 'font-secondary text-xs text-green-800';
		ready.textContent = `✓ ${t('fileReady')}`;
		const warning = document.createElement('p');
		warning.className = 'font-secondary text-xs text-red-800';
		warning.textContent = t('filePreviewFailed');
		warning.hidden = true;
		info.append(name, meta, ready, warning);
		const remove = document.createElement('button');
		remove.type = 'button';
		remove.className = 'flex h-8 w-8 shrink-0 cursor-pointer items-center justify-center rounded-full text-lg text-secondary/70 transition-colors hover:bg-secondary hover:text-primary focus-visible:outline-2 focus-visible:outline-secondary';
		remove.setAttribute('aria-label', t('fileRemove', file.name));
		remove.textContent = '×';
		remove.addEventListener('click', () => {
			files = files.filter((kept) => kept !== file);
			syncInput();
			render();
			showErrors([]);
			input.focus();
		});
		item.append(thumb, info, remove);
		if (extension === 'pdf' && config.pdfjs) previewPdf(file, thumb, meta, warning, ready);
		return item;
	};

	function render() {
		list.replaceChildren(...files.map(card));
		const full = files.length >= MAX_FILES;
		hint.textContent = full ? t('filesFull', MAX_FILES) : files.length ? t('filesAddMore') : hintText;
		input.disabled = full;
		zone.classList.toggle('opacity-50', full);
		zone.classList.toggle('cursor-not-allowed', full);
	}

	const add = (incoming) => {
		const result = checkFiles(files, Array.from(incoming || []));
		files = result.accepted;
		syncInput();
		render();
		showErrors(result.errors);
	};

	// The input now holds only the new pick: add it to what was already chosen.
	input.addEventListener('change', () => add(Array.from(input.files || []).filter((file) => !files.includes(file))));
	zone.addEventListener('dragover', (event) => {
		event.preventDefault();
		zone.classList.add('border-secondary', 'bg-accent/40');
	});
	zone.addEventListener('dragleave', () => zone.classList.remove('border-secondary', 'bg-accent/40'));
	zone.addEventListener('drop', (event) => {
		event.preventDefault();
		zone.classList.remove('border-secondary', 'bg-accent/40');
		if (!input.disabled) add(event.dataTransfer?.files);
	});

	return {
		reset() {
			files = [];
			syncInput();
			render();
			showErrors([]);
		},
	};
}
