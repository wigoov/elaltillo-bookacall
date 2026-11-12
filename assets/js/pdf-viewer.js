/**
 * Draws a booking's PDF onto canvases. The PDF arrives inside the page as data, so this page
 * connects nowhere. PDF.js's core never runs the PDF's own JavaScript; forms and links are not
 * drawn (annotationMode DISABLE), WebAssembly is off and XFA forms are ignored.
 */
import { getDocument, GlobalWorkerOptions, AnnotationMode } from './vendor/pdfjs/pdf.min.js';

const root = document.querySelector('[data-elab-pdf]');
const status = root.querySelector('[data-status]');
const holder = document.getElementById('elab-pdf-data');

GlobalWorkerOptions.workerSrc = root.dataset.worker;

try {
	const raw = atob(holder.textContent.trim());
	holder.remove();
	const bytes = new Uint8Array(raw.length);
	for (let i = 0; i < raw.length; i++) bytes[i] = raw.charCodeAt(i);
	const pdf = await getDocument({ data: bytes, useWasm: false, enableXfa: false, stopAtErrors: false }).promise;
	status.textContent = root.dataset.pages.replace('%d', pdf.numPages);
	const width = Math.min(root.clientWidth || 900, 900);
	const ratio = window.devicePixelRatio || 1;
	for (let number = 1; number <= pdf.numPages; number++) {
		const page = await pdf.getPage(number);
		const scale = width / page.getViewport({ scale: 1 }).width;
		const viewport = page.getViewport({ scale: scale * ratio });
		const canvas = document.createElement('canvas');
		canvas.width = Math.floor(viewport.width);
		canvas.height = Math.floor(viewport.height);
		canvas.style.width = `${Math.floor(viewport.width / ratio)}px`;
		canvas.setAttribute('role', 'img');
		canvas.setAttribute('aria-label', root.dataset.page.replace('%d', number));
		root.append(canvas);
		await page.render({ canvas, viewport, annotationMode: AnnotationMode.DISABLE }).promise;
		page.cleanup();
	}
} catch (error) {
	status.textContent = root.dataset.error;
}
