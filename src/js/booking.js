/**
 * Public booking calendar: month grid → open times → details → confirmation.
 *
 * Motion rules: nothing changes size while loading (fixed six-week grid, fixed-height
 * times area, always-rendered selection bar); content changes cross-fade; steps fade and
 * the stage animates its height. Everything is skipped under prefers-reduced-motion.
 * All dynamic text is set with textContent; nothing from the API is parsed as HTML.
 */

const DAY_BASE = 'relative flex aspect-square w-full max-w-11 justify-self-center items-center justify-center rounded-full font-secondary text-sm tabular-nums transition-[background-color,color,box-shadow,opacity] duration-300 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-secondary';
const DAY_OPEN = 'cursor-pointer text-secondary shadow-[inset_0_0_0_1px_rgba(40,44,51,0.3)] hover:bg-accent hover:shadow-[inset_0_0_0_1px_#282C33]';
const DAY_SELECTED = 'cursor-pointer bg-secondary text-primary shadow-[inset_0_0_0_1px_#282C33]';
const DAY_CLOSED = 'cursor-default text-secondary/25';
const DAY_EMPTY = 'invisible pointer-events-none';
const TODAY_MARK = 'absolute bottom-1.5 left-1/2 h-1 w-1 -translate-x-1/2 rounded-full bg-current transition-opacity duration-300';
const SLOT_BASE = 'h-10 cursor-pointer whitespace-nowrap rounded-lg border text-center font-secondary text-sm tabular-nums transition-[background-color,color,border-color] duration-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-secondary';
const SLOT_IDLE = 'border-secondary/20 bg-primary hover:border-secondary hover:bg-accent';
const SLOT_SELECTED = 'border-secondary bg-secondary text-primary';
const SKELETON = 'h-10 animate-pulse rounded-lg bg-secondary/[0.07]';
const NOTE = 'col-span-full self-center justify-self-center py-10 text-center font-primary text-base text-secondary/60';
const RETRY = 'col-span-full justify-self-center cursor-pointer font-secondary text-xs underline underline-offset-4';
const STEP_DOT_ACTIVE = ['bg-secondary', 'text-primary', 'border-secondary'];
const ACTION_PRIMARY = 'rounded-full bg-secondary px-5 py-2.5 text-primary transition-colors hover:bg-secondary/85';
const ACTION_SECONDARY = 'rounded-full border border-secondary/30 px-5 py-2.5 transition-colors hover:border-secondary hover:bg-accent';

const PERIODS = ['morning', 'afternoon', 'evening'];
const STEPS = ['time', 'details', 'done'];
const EASE = 'cubic-bezier(0.22, 1, 0.36, 1)';
const GRID_CELLS = 42;

const pad = (value) => String(value).padStart(2, '0');
const monthKey = (year, month) => `${year}-${pad(month + 1)}`;
const reducedMotion = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;

/**
 * Web Animations helper; resolves when done (or at once when motion is reduced).
 * Browsers pause animations in hidden tabs, so a timer finishes the animation if it has
 * not ended on time: the booking flow never waits on an animation that cannot run.
 */
function play(element, frames, options = {}) {
	if (!element || reducedMotion() || typeof element.animate !== 'function') return Promise.resolve(null);
	const settings = { duration: 240, easing: EASE, ...options };
	const animation = element.animate(frames, settings);
	const limit = settings.duration + (settings.delay || 0) + 80;
	return new Promise((resolve) => {
		const timer = setTimeout(() => {
			try {
				animation.finish();
			} catch (error) {
				// A cancelled animation cannot finish; it already released its styles.
			}
			resolve(animation);
		}, limit);
		animation.finished.then(() => animation, () => animation).then(() => {
			clearTimeout(timer);
			resolve(animation);
		});
	});
}

function stopAnimations(element) {
	if (element?.getAnimations) element.getAnimations().forEach((animation) => animation.cancel());
}

/** Fade an element's text out and the new text in. Repeated calls supersede each other. */
async function swapText(element, text) {
	if (!element || element.textContent === text) return;
	const token = Symbol('swap');
	element.elabSwap = token;
	stopAnimations(element);
	if (!element.textContent.trim() || reducedMotion()) {
		element.textContent = text;
		return;
	}
	await play(element, [{ opacity: 1 }, { opacity: 0 }], { duration: 110, fill: 'forwards' });
	if (element.elabSwap !== token) return;
	stopAnimations(element);
	element.textContent = text;
	play(element, [{ opacity: 0, transform: 'translateY(3px)' }, { opacity: 1, transform: 'none' }], { duration: 220 });
}

function format(template, ...values) {
	let index = 0;
	return String(template).replace(/%(\d+\$)?[sd]/g, (match, position) => {
		const value = position ? values[parseInt(position, 10) - 1] : values[index++];
		return value === undefined ? '' : String(value);
	});
}

function detectTimezone() {
	try {
		return Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC';
	} catch (error) {
		return 'UTC';
	}
}

function todayIn(timezone) {
	const parts = new Intl.DateTimeFormat('en-CA', { timeZone: timezone, year: 'numeric', month: '2-digit', day: '2-digit' }).formatToParts(new Date());
	const get = (type) => parts.find((part) => part.type === type).value;
	return `${get('year')}-${get('month')}-${get('day')}`;
}

export function initElabBookingForms() {
	const config = window.elabBooking;
	if (!config) return;
	document.querySelectorAll('[data-elab-booking]').forEach((root) => {
		if (root.dataset.elabInitialized || !root.querySelector('[data-elab-step]')) return;
		root.dataset.elabInitialized = '1';
		createCalendar(root, config);
	});
}

function createCalendar(root, config) {
	const t = (key, ...values) => format(config.i18n?.[key] ?? key, ...values);
	const $ = (selector) => root.querySelector(selector);
	const locale = document.documentElement.lang || navigator.language || 'en';
	const steps = { time: $('[data-elab-step="time"]'), details: $('[data-elab-step="details"]'), done: $('[data-elab-step="done"]') };
	const ui = {
		stage: $('[data-elab-stage]'),
		live: $('[data-elab-live]'),
		month: $('[data-elab-month]'),
		prev: $('[data-elab-prev]'),
		next: $('[data-elab-next]'),
		weekdays: $('[data-elab-weekdays]'),
		days: $('[data-elab-days]'),
		day: $('[data-elab-day]'),
		tzLabel: $('[data-elab-tz-label]'),
		tzToggle: $('[data-elab-tz-toggle]'),
		tzPicker: $('[data-elab-tz-picker]'),
		tzSearch: $('[data-elab-tz-search]'),
		tzList: $('[data-elab-tz-list]'),
		tzDetected: $('[data-elab-tz-detected]'),
		periodIndicator: $('[data-elab-period-indicator]'),
		periodButtons: Array.from(root.querySelectorAll('[data-elab-period]')),
		times: $('[data-elab-times]'),
		choiceWhen: $('[data-elab-choice-when]'),
		choiceMeta: $('[data-elab-choice-meta]'),
		choiceDuration: $('[data-elab-choice-duration]'),
		choiceWith: $('[data-elab-choice-with]'),
		choiceName: $('[data-elab-choice-name]'),
		prefer: $('[data-elab-prefer]'),
		preferSelect: $('[data-elab-prefer-select]'),
		continue: $('[data-elab-continue]'),
		form: steps.details,
		back: $('[data-elab-back]'),
		summaryDay: $('[data-elab-summary-day]'),
		summaryTime: $('[data-elab-summary-time]'),
		summaryMeta: $('[data-elab-summary-meta]'),
		files: $('[data-elab-files]'),
		error: $('[data-elab-error]'),
		submit: steps.details.querySelector('button[type="submit"]'),
		doneMark: $('[data-elab-done-mark]'),
		doneTitle: steps.done.querySelector('h2'),
		doneWhen: $('[data-elab-done-when]'),
		doneMeta: $('[data-elab-done-meta]'),
		doneMessage: $('[data-elab-done-message]'),
		calendarActions: $('[data-elab-calendar-actions]'),
		again: $('[data-elab-again]'),
	};
	const filesHint = ui.files.textContent;
	const pickTime = ui.choiceWhen.textContent;
	const state = {
		timezone: detectTimezone(),
		year: 0,
		month: 0,
		days: {},
		max: '',
		date: '',
		slots: [],
		period: '',
		slot: null,
		advisor: '',
		step: 'time',
		monthRequest: 0,
		slotRequest: 0,
		autoAdvance: 0,
		transition: Promise.resolve(),
	};

	const today = () => todayIn(state.timezone);
	const dateFormat = (options) => new Intl.DateTimeFormat(locale, { timeZone: 'UTC', ...options });
	const longDate = (date) => dateFormat({ weekday: 'long', day: 'numeric', month: 'long' }).format(new Date(`${date}T12:00:00Z`));
	const shortDate = (date) => dateFormat({ weekday: 'short', day: 'numeric', month: 'short' }).format(new Date(`${date}T12:00:00Z`));
	const timeLabel = (iso) => new Intl.DateTimeFormat(locale, { hour: 'numeric', minute: '2-digit', timeZone: state.timezone }).format(new Date(iso));
	const hourOf = (iso) => parseInt(new Intl.DateTimeFormat('en-GB', { hour: '2-digit', hourCycle: 'h23', timeZone: state.timezone }).format(new Date(iso)), 10);
	const periodOf = (iso) => { const hour = hourOf(iso); return hour < 12 ? 'morning' : hour < 18 ? 'afternoon' : 'evening'; };
	const tzName = () => state.timezone.replace(/_/g, ' ');
	const announce = (text) => { ui.live.textContent = text; };

	async function api(path, options = {}) {
		const response = await fetch(`${config.api}${path}`, { credentials: 'same-origin', ...options, headers: { 'X-WP-Nonce': config.nonce, ...(options.headers || {}) } });
		let data = null;
		try {
			data = await response.json();
		} catch (error) {
			data = null;
		}
		if (!response.ok) {
			const failure = new Error(data?.message || t('genericError'));
			failure.status = response.status;
			throw failure;
		}
		return data;
	}

	/* ---------- Month grid (42 persistent cells, updated in place) ---------- */

	const cells = Array.from({ length: GRID_CELLS }, () => {
		const button = document.createElement('button');
		button.type = 'button';
		const number = document.createElement('span');
		const mark = document.createElement('span');
		mark.className = `${TODAY_MARK} opacity-0`;
		mark.setAttribute('aria-hidden', 'true');
		button.append(number, mark);
		return { button, number, mark };
	});
	ui.days.replaceChildren(...cells.map((cell) => cell.button));

	function renderWeekdays() {
		const monday = Date.UTC(2024, 0, 1);
		const short = dateFormat({ weekday: 'short' });
		ui.weekdays.replaceChildren(...Array.from({ length: 7 }, (_, index) => {
			const cell = document.createElement('span');
			cell.textContent = short.format(new Date(monday + index * 86400000)).replace('.', '');
			return cell;
		}));
	}

	function renderMonth(loading = false) {
		const first = new Date(Date.UTC(state.year, state.month, 1));
		const offset = (first.getUTCDay() + 6) % 7;
		const count = new Date(Date.UTC(state.year, state.month + 1, 0)).getUTCDate();
		const now = today();
		const prefix = monthKey(state.year, state.month);
		cells.forEach((cell, index) => {
			const day = index - offset + 1;
			const { button } = cell;
			if (day < 1 || day > count) {
				button.className = `${DAY_BASE} ${DAY_EMPTY}`;
				button.disabled = true;
				button.tabIndex = -1;
				['data-date', 'aria-label', 'aria-pressed', 'aria-current'].forEach((name) => button.removeAttribute(name));
				cell.number.textContent = '';
				cell.mark.classList.add('opacity-0');
				return;
			}
			const date = `${prefix}-${pad(day)}`;
			const open = !loading && (state.days[date] || 0) > 0;
			const selected = open && date === state.date;
			button.dataset.date = date;
			button.className = `${DAY_BASE} ${selected ? DAY_SELECTED : open ? DAY_OPEN : DAY_CLOSED}`;
			button.disabled = !open;
			button.tabIndex = 0;
			button.setAttribute('aria-label', t(open ? 'dayOpen' : 'dayClosed', longDate(date)));
			button.setAttribute('aria-pressed', selected ? 'true' : 'false');
			if (date === now) button.setAttribute('aria-current', 'date');
			else button.removeAttribute('aria-current');
			cell.number.textContent = String(day);
			cell.mark.classList.toggle('opacity-0', date !== now);
		});
		ui.days.setAttribute('aria-busy', loading ? 'true' : 'false');
		updateNav();
	}

	/** Month arrows; the window is this month up to the furthest bookable month. */
	function canShow(year, month) {
		const key = monthKey(year, month);
		return key >= today().slice(0, 7) && (!state.max || key <= state.max.slice(0, 7));
	}

	function canShift(delta) {
		const target = new Date(Date.UTC(state.year, state.month + delta, 1));
		return canShow(target.getUTCFullYear(), target.getUTCMonth());
	}

	function updateNav() {
		ui.prev.disabled = !canShift(-1);
		ui.next.disabled = !canShift(1);
	}

	function monthLabel() {
		return dateFormat({ month: 'long', year: 'numeric' }).format(new Date(Date.UTC(state.year, state.month, 1)));
	}

	async function loadMonth() {
		const request = ++state.monthRequest;
		swapText(ui.month, monthLabel());
		renderMonth(true);
		try {
			const params = new URLSearchParams({ month: monthKey(state.year, state.month), timezone: state.timezone });
			const data = await api(`/availability?${params}`);
			if (request !== state.monthRequest || data?.month !== monthKey(state.year, state.month)) return;
			state.days = data.days || {};
			state.max = data.max || '';
			const open = Object.keys(state.days).sort();
			if (!open.length) {
				const viewing = monthKey(state.year, state.month);
				if (state.autoAdvance < 3 && (!state.max || viewing < state.max.slice(0, 7))) {
					state.autoAdvance++;
					shiftMonth(1);
					return;
				}
				state.autoAdvance = 0;
				renderMonth();
				state.date = '';
				clearSelection();
				swapText(ui.day, '\u00a0');
				showNote(state.max && monthKey(state.year, state.month) < state.max.slice(0, 7) ? t('noMonth') : t('noneAtAll'));
				return;
			}
			state.autoAdvance = 0;
			if (state.days[state.date]) renderMonth();
			else selectDate(open[0], false);
		} catch (error) {
			if (request !== state.monthRequest) return;
			renderMonth();
			showNote(error.message || t('loadError'), loadMonth);
		}
	}

	async function shiftMonth(delta) {
		if (!canShift(delta)) return;
		const next = new Date(Date.UTC(state.year, state.month + delta, 1));
		state.year = next.getUTCFullYear();
		state.month = next.getUTCMonth();
		state.days = {};
		state.monthRequest++; // Drop any response still on its way for the month we are leaving.
		updateNav();
		const distance = delta > 0 ? 18 : -18;
		stopAnimations(ui.days);
		await play(ui.days, [{ opacity: 1, transform: 'none' }, { opacity: 0, transform: `translateX(${-distance}px)` }], { duration: 140, easing: 'ease-in', fill: 'forwards' });
		stopAnimations(ui.days);
		loadMonth();
		play(ui.days, [{ opacity: 0, transform: `translateX(${distance}px)` }, { opacity: 1, transform: 'none' }], { duration: 280 });
	}

	/* ---------- Times ---------- */

	/** Replace the times area: fade out, swap, fade in with a short stagger. Its height never changes. */
	async function fillTimes(children, request) {
		await play(ui.times, [{ opacity: getComputedStyle(ui.times).opacity }, { opacity: 0 }], { duration: 120, fill: 'forwards' });
		if (request !== undefined && request !== state.slotRequest) return;
		ui.times.replaceChildren(...children);
		ui.times.scrollTop = 0;
		stopAnimations(ui.times);
		ui.times.classList.remove('opacity-40');
		children.forEach((child, index) => {
			play(child, [{ opacity: 0, transform: 'translateY(6px)' }, { opacity: 1, transform: 'none' }], { duration: 260, delay: Math.min(index, 14) * 16, fill: 'backwards' });
		});
	}

	function showNote(text, retry) {
		const message = document.createElement('p');
		message.className = NOTE;
		message.textContent = text;
		const children = [message];
		if (retry) {
			const button = document.createElement('button');
			button.type = 'button';
			button.className = RETRY;
			button.textContent = t('retry');
			button.addEventListener('click', retry);
			children.push(button);
		}
		updatePeriods({});
		fillTimes(children, state.slotRequest);
	}

	function skeleton() {
		return Array.from({ length: 9 }, () => {
			const block = document.createElement('span');
			block.className = SKELETON;
			return block;
		});
	}

	function selectDate(date, fromUser = true) {
		state.date = date;
		renderMonth();
		swapText(ui.day, longDate(date));
		clearSelection();
		// On a phone the times sit below the calendar; bring them into view after a tap.
		if (fromUser && window.matchMedia('(max-width: 767px)').matches) ui.day.scrollIntoView({ behavior: reducedMotion() ? 'auto' : 'smooth', block: 'start' });
		loadSlots(fromUser);
	}

	async function loadSlots(focusTimes) {
		const request = ++state.slotRequest;
		ui.times.setAttribute('aria-busy', 'true');
		// Keep the previous times on screen, dimmed, until the new ones arrive.
		if (ui.times.querySelector('button[data-value]')) ui.times.classList.add('opacity-40');
		else fillTimes(skeleton(), request);
		announce(t('loadingTimes'));
		try {
			const params = new URLSearchParams({ date: state.date, timezone: state.timezone });
			const slots = await api(`/slots?${params}`);
			if (request !== state.slotRequest) return;
			state.slots = (Array.isArray(slots) ? slots : []).map((slot) => ({ ...slot, period: periodOf(slot.value) }));
			ui.times.setAttribute('aria-busy', 'false');
			if (!state.slots.length) {
				showNote(t('noTimes'));
				announce(t('noTimes'));
				return;
			}
			const counts = {};
			state.slots.forEach((slot) => { counts[slot.period] = (counts[slot.period] || 0) + 1; });
			if (!counts[state.period]) state.period = PERIODS.find((period) => counts[period]) || '';
			updatePeriods(counts);
			await renderTimes(request);
			announce(`${longDate(state.date)}: ${state.slots.length}`);
			if (focusTimes) ui.times.querySelector('button[data-value]')?.focus({ preventScroll: true });
		} catch (error) {
			if (request !== state.slotRequest) return;
			ui.times.setAttribute('aria-busy', 'false');
			showNote(error.message || t('loadError'), () => loadSlots(true));
		}
	}

	function renderTimes(request) {
		const children = state.slots.filter((slot) => slot.period === state.period).map((slot) => {
			const button = document.createElement('button');
			button.type = 'button';
			button.dataset.value = slot.value;
			const selected = state.slot?.value === slot.value;
			button.className = `${SLOT_BASE} ${selected ? SLOT_SELECTED : SLOT_IDLE}`;
			button.setAttribute('aria-pressed', selected ? 'true' : 'false');
			button.textContent = timeLabel(slot.value);
			return button;
		});
		return fillTimes(children, request);
	}

	function updatePeriods(counts) {
		ui.periodButtons.forEach((button) => {
			const count = counts[button.dataset.elabPeriod] || 0;
			button.disabled = !count;
			button.setAttribute('aria-pressed', button.dataset.elabPeriod === state.period && count ? 'true' : 'false');
			button.querySelector('[data-elab-period-count]').textContent = count ? `, ${t('timesCount', count)}` : '';
		});
		moveIndicator();
	}

	function moveIndicator() {
		const active = ui.periodButtons.find((button) => button.getAttribute('aria-pressed') === 'true');
		ui.periodIndicator.classList.toggle('opacity-0', !active);
		if (!active || !active.offsetWidth) return;
		ui.periodIndicator.style.width = `${active.offsetWidth}px`;
		ui.periodIndicator.style.transform = `translateX(${active.offsetLeft}px)`;
	}

	function choosePeriod(period) {
		if (period === state.period) return;
		state.period = period;
		ui.periodButtons.forEach((button) => button.setAttribute('aria-pressed', button.dataset.elabPeriod === period ? 'true' : 'false'));
		moveIndicator();
		renderTimes(state.slotRequest);
	}

	/* ---------- Selection bar ---------- */

	function chosenAdvisors() {
		const advisors = state.slot?.advisors || [];
		return state.advisor ? advisors.filter((advisor) => String(advisor.id) === state.advisor) : advisors;
	}

	function durationOf(advisors) {
		return advisors.length ? Math.min(...advisors.map((advisor) => advisor.duration)) : 0;
	}

	function callDescription() {
		const advisors = chosenAdvisors();
		if (advisors.length === 1) return t('callWith', advisors[0].duration, advisors[0].name);
		return t('callAny', durationOf(advisors));
	}

	async function updateChoice() {
		const slot = state.slot;
		ui.continue.disabled = !slot;
		swapText(ui.choiceWhen, slot ? `${shortDate(state.date)} · ${timeLabel(slot.value)}` : pickTime);
		const advisors = slot?.advisors || [];
		const fillMeta = () => {
			ui.choiceDuration.textContent = slot ? t('minutes', durationOf(chosenAdvisors())) : '';
			ui.choiceWith.hidden = !slot;
			ui.prefer.hidden = advisors.length < 2;
			ui.choiceName.hidden = advisors.length !== 1;
			ui.choiceName.textContent = advisors.length === 1 ? advisors[0].name : '';
			if (advisors.length > 1) {
				ui.preferSelect.replaceChildren(new Option(t('anyone'), ''), ...advisors.map((advisor) => new Option(advisor.name, String(advisor.id))));
				ui.preferSelect.value = state.advisor;
			}
		};
		const token = Symbol('meta');
		ui.choiceMeta.elabSwap = token;
		stopAnimations(ui.choiceMeta);
		if (!ui.choiceDuration.textContent && !slot) {
			fillMeta();
			return;
		}
		await play(ui.choiceMeta, [{ opacity: 1 }, { opacity: 0 }], { duration: 110, fill: 'forwards' });
		if (ui.choiceMeta.elabSwap !== token) return;
		fillMeta();
		stopAnimations(ui.choiceMeta);
		play(ui.choiceMeta, [{ opacity: 0, transform: 'translateY(3px)' }, { opacity: 1, transform: 'none' }], { duration: 220 });
	}

	function paintSlots() {
		ui.times.querySelectorAll('button[data-value]').forEach((button) => {
			const selected = button.dataset.value === state.slot?.value;
			button.className = `${SLOT_BASE} ${selected ? SLOT_SELECTED : SLOT_IDLE}`;
			button.setAttribute('aria-pressed', selected ? 'true' : 'false');
		});
	}

	function clearSelection() {
		if (!state.slot) return;
		state.slot = null;
		state.advisor = '';
		paintSlots();
		updateChoice();
	}

	function selectSlot(value) {
		state.slot = state.slots.find((slot) => slot.value === value) || null;
		state.advisor = '';
		paintSlots();
		updateChoice();
		if (state.slot) announce(`${timeLabel(state.slot.value)} · ${callDescription()}`);
	}

	/* ---------- Time zone ---------- */

	const detectedZone = state.timezone;
	const normalize = (value) => String(value).normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
	let zoneIndex = null;
	let zoneMatches = [];
	let zoneActive = 0;

	function offsetOf(zone) {
		try {
			const part = new Intl.DateTimeFormat('en-US', { timeZone: zone, timeZoneName: 'shortOffset' }).formatToParts(new Date()).find((item) => item.type === 'timeZoneName');
			return part ? part.value.replace('GMT', 'UTC') : '';
		} catch (error) {
			return '';
		}
	}

	/** Every IANA zone the browser knows, with a readable city, region and current UTC offset. */
	function zones() {
		if (zoneIndex) return zoneIndex;
		let ids = [];
		try {
			ids = Intl.supportedValuesOf('timeZone');
		} catch (error) {
			ids = [];
		}
		[detectedZone, state.timezone].forEach((zone) => { if (!ids.includes(zone)) ids.unshift(zone); });
		zoneIndex = ids.map((id) => {
			const parts = id.split('/');
			const city = parts.pop().replace(/_/g, ' ');
			const region = parts.join(' / ').replace(/_/g, ' ');
			const offset = offsetOf(id);
			return { id, city, region, offset, key: normalize(`${city} ${region} ${id} ${offset}`), cityKey: normalize(city) };
		});
		return zoneIndex;
	}

	function renderZones() {
		const query = normalize(ui.tzSearch.value.trim());
		const all = zones();
		if (query) {
			zoneMatches = all.filter((zone) => zone.key.includes(query)).sort((a, b) => Number(!b.cityKey.startsWith(query)) - Number(!a.cityKey.startsWith(query)) || a.city.localeCompare(b.city));
		} else {
			const current = all.find((zone) => zone.id === state.timezone);
			zoneMatches = [current, ...all.filter((zone) => zone !== current)].filter(Boolean);
		}
		zoneMatches = zoneMatches.slice(0, 80);
		zoneActive = Math.min(zoneActive, Math.max(zoneMatches.length - 1, 0));
		if (!zoneMatches.length) {
			const empty = document.createElement('li');
			empty.className = 'px-3 py-4 text-center font-secondary text-xs text-secondary/50';
			empty.textContent = t('tzNoMatch');
			ui.tzList.replaceChildren(empty);
			ui.tzSearch.removeAttribute('aria-activedescendant');
			return;
		}
		ui.tzList.replaceChildren(...zoneMatches.map((zone, index) => {
			const item = document.createElement('li');
			item.id = `${ui.tzList.id}-${index}`;
			item.setAttribute('role', 'option');
			item.setAttribute('aria-selected', zone.id === state.timezone ? 'true' : 'false');
			item.dataset.zone = zone.id;
			item.dataset.index = String(index);
			item.className = `flex cursor-pointer items-center justify-between gap-3 rounded-xl px-3 py-2 transition-colors ${index === zoneActive ? 'bg-accent' : 'hover:bg-secondary/[0.05]'}`;
			const label = document.createElement('span');
			label.className = 'grid min-w-0';
			const city = document.createElement('span');
			city.className = 'truncate font-primary text-sm text-secondary';
			city.textContent = zone.city;
			const region = document.createElement('span');
			region.className = 'truncate font-secondary text-[0.65rem] uppercase tracking-wider text-secondary/50';
			region.textContent = zone.region || zone.id;
			label.append(city, region);
			const meta = document.createElement('span');
			meta.className = 'shrink-0 font-secondary text-xs tabular-nums text-secondary/60';
			meta.textContent = `${zone.id === state.timezone ? '✓ ' : ''}${zone.offset}`;
			item.append(label, meta);
			return item;
		}));
		ui.tzSearch.setAttribute('aria-activedescendant', `${ui.tzList.id}-${zoneActive}`);
	}

	function highlightZone(index) {
		zoneActive = Math.max(0, Math.min(index, zoneMatches.length - 1));
		ui.tzList.querySelectorAll('[role="option"]').forEach((item) => {
			const active = Number(item.dataset.index) === zoneActive;
			item.classList.toggle('bg-accent', active);
			item.classList.toggle('hover:bg-secondary/[0.05]', !active);
			if (active) item.scrollIntoView({ block: 'nearest' });
		});
		ui.tzSearch.setAttribute('aria-activedescendant', `${ui.tzList.id}-${zoneActive}`);
	}

	function setTimezone(zone) {
		toggleTimezone(false);
		ui.tzToggle.focus({ preventScroll: true });
		if (!zone || zone === state.timezone) return;
		state.timezone = zone;
		swapText(ui.tzLabel, t('timezone', tzName()));
		state.date = '';
		state.days = {};
		clearSelection();
		loadMonth();
	}

	async function toggleTimezone(open) {
		ui.tzToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
		stopAnimations(ui.tzPicker);
		if (open) {
			ui.tzSearch.value = '';
			zoneActive = 0;
			renderZones();
			ui.tzDetected.hidden = state.timezone === detectedZone;
			ui.tzDetected.textContent = t('tzUseLocal', detectedZone.replace(/_/g, ' '));
			ui.tzPicker.hidden = false;
			ui.tzList.scrollTop = 0;
			play(ui.tzPicker, [{ opacity: 0, transform: 'translateY(-4px) scale(0.98)' }, { opacity: 1, transform: 'none' }], { duration: 200 });
			ui.tzSearch.focus({ preventScroll: true });
			return;
		}
		if (ui.tzPicker.hidden) return;
		await play(ui.tzPicker, [{ opacity: 1 }, { opacity: 0, transform: 'translateY(-4px)' }], { duration: 140, fill: 'forwards' });
		ui.tzPicker.hidden = true;
		stopAnimations(ui.tzPicker);
	}

	/* ---------- Steps ---------- */

	function updateProgress(name) {
		const current = STEPS.indexOf(name);
		root.querySelectorAll('[data-elab-progress]').forEach((item) => {
			const index = STEPS.indexOf(item.dataset.elabProgress);
			item.classList.toggle('opacity-40', index > current);
			STEP_DOT_ACTIVE.forEach((name2) => item.querySelector('span').classList.toggle(name2, index === current));
			if (index === current) item.setAttribute('aria-current', 'step');
			else item.removeAttribute('aria-current');
		});
	}

	/** Fade the current step out, animate the stage height, fade the next step in. */
	function goTo(name) {
		state.transition = state.transition.then(async () => {
			const current = steps[state.step];
			const next = steps[name];
			if (current === next) return;
			state.step = name;
			updateProgress(name);
			if (root.getBoundingClientRect().top < 96) root.scrollIntoView({ behavior: reducedMotion() ? 'auto' : 'smooth', block: 'start' });
			const from = ui.stage.offsetHeight;
			await play(current, [{ opacity: 1, transform: 'none' }, { opacity: 0, transform: 'translateY(-8px)' }], { duration: 160, easing: 'ease-in', fill: 'forwards' });
			current.hidden = true;
			stopAnimations(current);
			next.hidden = false;
			const to = ui.stage.offsetHeight;
			if (from !== to) play(ui.stage, [{ height: `${from}px` }, { height: `${to}px` }], { duration: 320 });
			next.querySelector('h2')?.focus({ preventScroll: true });
			if (name === 'time') moveIndicator();
			if (name === 'done') play(ui.doneMark, [{ transform: 'scale(0.6)', opacity: 0 }, { transform: 'scale(1.06)', opacity: 1, offset: 0.7 }, { transform: 'scale(1)', opacity: 1 }], { duration: 480, delay: 120, fill: 'backwards' });
			await play(next, [{ opacity: 0, transform: 'translateY(10px)' }, { opacity: 1, transform: 'none' }], { duration: 300, delay: 40, fill: 'backwards' });
		});
		return state.transition;
	}

	function fillSummary() {
		ui.summaryDay.textContent = longDate(state.date);
		ui.summaryTime.textContent = timeLabel(state.slot.value);
		ui.summaryMeta.textContent = `${callDescription()} · ${tzName()}`;
	}

	function showError(text) {
		if (!text) {
			ui.error.hidden = true;
			return;
		}
		ui.error.textContent = text;
		ui.error.hidden = false;
		play(ui.error, [{ opacity: 0, transform: 'translateY(-4px)' }, { opacity: 1, transform: 'none' }], { duration: 220 });
	}

	function showCalendarChoices(calendar) {
		ui.calendarActions.replaceChildren();
		if (!calendar) return;
		if (calendar.url && /^https:\/\/(www\.)?(calendar\.)?google\.com\//.test(calendar.url)) {
			const google = document.createElement('a');
			google.href = calendar.url;
			google.target = '_blank';
			google.rel = 'noopener noreferrer';
			google.className = ACTION_PRIMARY;
			google.textContent = t('addGoogle');
			ui.calendarActions.appendChild(google);
		}
		const escape = (value) => String(value).replace(/\\/g, '\\\\').replace(/,/g, '\\,').replace(/;/g, '\\;').replace(/\n/g, '\\n');
		const stamp = (value) => value.replace(/[-:]/g, '');
		const ics = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//El Altillo//Book a Call//EN', 'METHOD:PUBLISH', 'BEGIN:VEVENT', `UID:${escape(calendar.uid || 'elab-call')}`, `DTSTAMP:${stamp(new Date().toISOString().slice(0, 19) + 'Z')}`, `DTSTART:${stamp(calendar.start)}`, `DTEND:${stamp(calendar.end)}`, `SUMMARY:${escape(calendar.title)}`, 'END:VEVENT', 'END:VCALENDAR'].join('\r\n');
		const download = document.createElement('a');
		download.href = URL.createObjectURL(new Blob([ics], { type: 'text/calendar;charset=utf-8' }));
		download.download = 'el-altillo-call.ics';
		download.className = calendar.url ? ACTION_SECONDARY : ACTION_PRIMARY;
		download.textContent = t('addIcs');
		ui.calendarActions.appendChild(download);
	}

	async function submit(event) {
		event.preventDefault();
		if (ui.submit.disabled || !state.slot) return;
		const invalid = Array.from(ui.form.elements).find((field) => field.willValidate && !field.checkValidity());
		if (invalid) {
			invalid.reportValidity();
			invalid.focus();
			return;
		}
		const data = new FormData(ui.form);
		data.set('start', state.slot.value);
		data.set('timezone', state.timezone);
		data.set('advisor', state.advisor);
		ui.submit.disabled = true;
		const label = ui.submit.textContent;
		ui.submit.textContent = t('sending');
		showError('');
		try {
			const result = await api('/bookings', { method: 'POST', body: data });
			const booking = result.booking || {};
			ui.doneTitle.textContent = t('doneTitle');
			ui.doneWhen.textContent = `${longDate(state.date)} · ${timeLabel(state.slot.value)}`;
			ui.doneMeta.textContent = [booking.advisor ? t('withAdvisor', booking.advisor) : '', booking.language, tzName()].filter(Boolean).join(' · ');
			ui.doneMessage.textContent = result.message || '';
			showCalendarChoices(result.calendar);
			await goTo('done');
			ui.form.reset();
			ui.files.textContent = filesHint;
		} catch (error) {
			if (error.status === 409) {
				await goTo('time');
				state.slot = null;
				updateChoice();
				announce(error.message || t('taken'));
				loadSlots(false);
				return;
			}
			showError(error.message || t('genericError'));
		} finally {
			ui.submit.disabled = false;
			ui.submit.textContent = label;
		}
	}

	/* ---------- Events ---------- */

	ui.prev.addEventListener('click', () => shiftMonth(-1));
	ui.next.addEventListener('click', () => shiftMonth(1));
	ui.days.addEventListener('click', (event) => {
		const button = event.target.closest('button[data-date]');
		if (button && !button.disabled && button.dataset.date !== state.date) selectDate(button.dataset.date);
	});
	ui.days.addEventListener('keydown', (event) => {
		const moves = { ArrowLeft: -1, ArrowRight: 1, ArrowUp: -7, ArrowDown: 7 };
		if (!(event.key in moves)) return;
		const buttons = cells.map((cell) => cell.button);
		const current = buttons.indexOf(document.activeElement);
		if (current < 0) return;
		event.preventDefault();
		for (let index = current + moves[event.key]; index >= 0 && index < buttons.length; index += moves[event.key]) {
			if (!buttons[index].disabled) {
				buttons[index].focus();
				break;
			}
		}
	});
	ui.periodButtons.forEach((button) => button.addEventListener('click', () => choosePeriod(button.dataset.elabPeriod)));
	ui.times.addEventListener('click', (event) => {
		const button = event.target.closest('button[data-value]');
		if (button) selectSlot(button.dataset.value);
	});
	ui.preferSelect.addEventListener('change', () => {
		state.advisor = ui.preferSelect.value;
		swapText(ui.choiceDuration, t('minutes', durationOf(chosenAdvisors())));
	});
	ui.tzToggle.addEventListener('click', () => toggleTimezone(ui.tzPicker.hidden));
	ui.tzSearch.addEventListener('input', () => {
		zoneActive = 0;
		renderZones();
		ui.tzList.scrollTop = 0;
	});
	ui.tzSearch.addEventListener('keydown', (event) => {
		if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
			event.preventDefault();
			highlightZone(zoneActive + (event.key === 'ArrowDown' ? 1 : -1));
		} else if (event.key === 'Enter') {
			event.preventDefault();
			setTimezone(zoneMatches[zoneActive]?.id);
		}
	});
	ui.tzList.addEventListener('click', (event) => {
		const item = event.target.closest('[data-zone]');
		if (item) setTimezone(item.dataset.zone);
	});
	ui.tzList.addEventListener('mousemove', (event) => {
		const item = event.target.closest('[data-index]');
		if (item && Number(item.dataset.index) !== zoneActive) highlightZone(Number(item.dataset.index));
	});
	ui.tzDetected.addEventListener('click', () => setTimezone(detectedZone));
	root.addEventListener('keydown', (event) => {
		if (event.key === 'Escape' && !ui.tzPicker.hidden) {
			toggleTimezone(false);
			ui.tzToggle.focus();
		}
	});
	document.addEventListener('click', (event) => {
		if (!ui.tzPicker.hidden && !ui.tzPicker.contains(event.target) && !ui.tzToggle.contains(event.target)) toggleTimezone(false);
	});
	ui.continue.addEventListener('click', () => {
		if (!state.slot) return;
		fillSummary();
		showError('');
		goTo('details');
	});
	ui.back.addEventListener('click', () => goTo('time'));
	ui.form.addEventListener('submit', submit);
	ui.form.querySelector('input[type="file"]').addEventListener('change', (event) => {
		const files = Array.from(event.target.files || []);
		swapText(ui.files, files.length === 1 ? files[0].name : files.length ? t('filesChosen', files.length) : filesHint);
	});
	ui.again.addEventListener('click', async () => {
		state.date = '';
		state.days = {};
		state.slot = null;
		state.advisor = '';
		updateChoice();
		await goTo('time');
		loadMonth();
	});
	window.addEventListener('resize', moveIndicator, { passive: true });

	/* ---------- Start ---------- */

	const [year, month] = today().split('-').map(Number);
	state.year = year;
	state.month = month - 1;
	renderWeekdays();
	ui.tzLabel.textContent = t('timezone', tzName());
	updateProgress('time');
	fillTimes(skeleton());
	loadMonth();
}
