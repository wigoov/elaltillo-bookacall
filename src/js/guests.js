/**
 * Optional extra guests: the visitor types an email and adds it as a chip. The chips are
 * kept in a hidden "guests" field (comma separated) that the server validates again.
 */

export const MAX_GUESTS = 3;
const EMAIL = /^[^\s@,;]+@[^\s@,;]+\.[^\s@,;]{2,}$/;

/**
 * Decide whether an address can be added. Pure, so it is unit-tested on its own.
 * @returns {{ok: true, email: string} | {ok: false, reason: 'empty'|'invalid'|'self'|'duplicate'|'full'}}
 */
export function checkGuest(value, current = [], visitorEmail = '') {
	const email = String(value || '').trim();
	if (!email) return { ok: false, reason: 'empty' };
	if (!EMAIL.test(email) || email.length > 190) return { ok: false, reason: 'invalid' };
	const key = email.toLowerCase();
	if (key === String(visitorEmail || '').trim().toLowerCase()) return { ok: false, reason: 'self' };
	if (current.some((guest) => guest.toLowerCase() === key)) return { ok: false, reason: 'duplicate' };
	if (current.length >= MAX_GUESTS) return { ok: false, reason: 'full' };
	return { ok: true, email };
}

/** Wire the guests field inside `root`. `t` translates messages. */
export function initGuests(root, t) {
	const box = root.querySelector('[data-elab-guests]');
	if (!box) return { commit: () => true, reset() {} };
	const input = box.querySelector('[data-elab-guest-input]');
	const add = box.querySelector('[data-elab-guest-add]');
	const list = box.querySelector('[data-elab-guest-list]');
	const hidden = box.querySelector('[data-elab-guests-value]');
	const hint = box.querySelector('[data-elab-guest-hint]');
	const hintText = hint.textContent;
	const visitor = root.querySelector('input[name="email"]');
	let guests = [];

	const sync = () => {
		hidden.value = guests.join(',');
		add.disabled = guests.length >= MAX_GUESTS;
		input.disabled = guests.length >= MAX_GUESTS;
	};
	const say = (message, isError) => {
		hint.textContent = message || hintText;
		hint.classList.toggle('text-red-800', Boolean(isError));
		input.setAttribute('aria-invalid', isError ? 'true' : 'false');
	};
	const render = () => {
		list.replaceChildren(...guests.map((email) => {
			const item = document.createElement('li');
			item.className = 'inline-flex max-w-full items-center gap-2 rounded-full bg-accent py-1.5 pr-1.5 pl-4 font-secondary text-xs';
			const label = document.createElement('span');
			label.className = 'truncate';
			label.textContent = email;
			const remove = document.createElement('button');
			remove.type = 'button';
			remove.className = 'flex h-6 w-6 shrink-0 cursor-pointer items-center justify-center rounded-full transition-colors hover:bg-secondary hover:text-primary focus-visible:outline-2 focus-visible:outline-secondary';
			remove.setAttribute('aria-label', t('guestRemove', email));
			remove.textContent = '×';
			remove.addEventListener('click', () => {
				guests = guests.filter((guest) => guest !== email);
				render();
				say('');
				input.focus();
			});
			item.append(label, remove);
			return item;
		}));
		sync();
	};
	/** Add what is typed. Returns false (and explains) when it cannot be added. */
	const commit = (quietWhenEmpty = true) => {
		const result = checkGuest(input.value, guests, visitor?.value);
		if (!result.ok) {
			if (result.reason === 'empty' && quietWhenEmpty) return true;
			say(t(`guest_${result.reason}`, MAX_GUESTS), true);
			return false;
		}
		guests = [...guests, result.email];
		input.value = '';
		render();
		say(t('guestAdded', result.email));
		return true;
	};

	add.addEventListener('click', () => {
		commit(false);
		input.focus();
	});
	input.addEventListener('keydown', (event) => {
		if (event.key === 'Enter' || event.key === ',' || event.key === ';') {
			event.preventDefault();
			commit(false);
		}
	});
	input.addEventListener('input', () => say(''));
	sync();
	return {
		// Called on submit: a typed but not yet added address is added (or blocks with a message).
		commit: () => commit(true),
		reset() {
			guests = [];
			input.value = '';
			render();
			say('');
		},
	};
}
