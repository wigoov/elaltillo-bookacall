/** Weekly advisor hours editor. The stored format remains the booking API's day/range JSON. */
const schedule = document.querySelector('[data-elab-weekly-hours]');
const timezoneSearch = document.querySelector('#elab-timezone-search');

if (timezoneSearch) {
	const select = document.querySelector('#elab-timezone');
	const options = Array.from(select.querySelectorAll('option'));
	timezoneSearch.addEventListener('input', () => {
		const query = timezoneSearch.value.trim().toLocaleLowerCase();
		options.forEach((option) => {
			option.hidden = Boolean(query) && !option.textContent.toLocaleLowerCase().includes(query) && !option.selected;
		});
		select.querySelectorAll('optgroup').forEach((group) => {
			group.hidden = Array.from(group.children).every((option) => option.hidden);
		});
		select.size = query ? Math.min(8, options.filter((option) => !option.hidden).length || 2) : 1;
	});
	select.addEventListener('change', () => {
		timezoneSearch.value = '';
		options.forEach((option) => { option.hidden = false; });
		select.querySelectorAll('optgroup').forEach((group) => { group.hidden = false; });
		select.size = 1;
	});
}

if (schedule) {
	const setDayState = (row) => {
		const active = row.querySelector('input[type="checkbox"]').checked;
		row.querySelectorAll('[data-elab-range] input, [data-elab-add-range], [data-elab-remove-range]').forEach((control) => {
			control.disabled = !active;
		});
	};

	schedule.querySelectorAll('[data-elab-day]').forEach(setDayState);

	schedule.addEventListener('change', (event) => {
		if (event.target.matches('input[type="checkbox"]')) setDayState(event.target.closest('[data-elab-day]'));
	});

	schedule.addEventListener('click', (event) => {
		const row = event.target.closest('[data-elab-day]');
		if (!row) return;
		if (event.target.matches('[data-elab-remove-range]')) {
			event.target.closest('[data-elab-range]').remove();
			return;
		}
		if (!event.target.matches('[data-elab-add-range]')) return;
		const container = row.querySelector('[data-elab-ranges]');
		const day = row.dataset.elabDay;
		const existing = Array.from(container.querySelectorAll('input[name]'))
			.map((input) => Number(input.name.match(/\]\[(\d+)\]\[/)?.[1] ?? -1));
		const index = Math.max(Number(row.dataset.elabNextIndex || 0) - 1, ...existing) + 1;
		row.dataset.elabNextIndex = String(index + 1);
		const range = document.createElement('div');
		range.dataset.elabRange = '';
		const from = document.createElement('label');
		const fromText = document.createElement('span');
		fromText.className = 'screen-reader-text';
		fromText.textContent = 'From';
		from.appendChild(fromText);
		const start = document.createElement('input');
		start.type = 'time';
		start.name = `elab_availability[${day}][${index}][start]`;
		start.value = '09:00';
		start.setAttribute('aria-label', `${row.querySelector('strong').textContent} start time`);
		from.appendChild(start);
		const to = document.createElement('label');
		const toText = document.createElement('span');
		toText.className = 'screen-reader-text';
		toText.textContent = 'To';
		to.appendChild(toText);
		const end = document.createElement('input');
		end.type = 'time';
		end.name = `elab_availability[${day}][${index}][end]`;
		end.value = '17:00';
		end.setAttribute('aria-label', `${row.querySelector('strong').textContent} end time`);
		to.appendChild(end);
		const remove = document.createElement('button');
		remove.type = 'button';
		remove.className = 'button-link button-link-delete elab-remove-range';
		remove.dataset.elabRemoveRange = '';
		remove.setAttribute('aria-label', 'Remove time range');
		remove.textContent = '×';
		const separator = document.createElement('span');
		separator.className = 'elab-range-separator';
		separator.setAttribute('aria-hidden', 'true');
		separator.textContent = '—';
		range.append(from, separator, to, remove);
		container.appendChild(range);
		start.focus();
	});
}

document.querySelectorAll('[data-elab-blackouts]').forEach((editor) => {
	const body = editor.querySelector('[data-elab-blackout-rows]');
	const template = body.querySelector('[data-elab-blackout-row]').cloneNode(true);
	const prefix = editor.dataset.elabPrefix;
	let nextIndex = body.children.length;
	const syncAnnual = (row) => {
		const start = row.querySelector('[name$="[start]"]').value;
		const end = row.querySelector('[name$="[end]"]').value;
		const annual = row.querySelector('[name$="[annual]"]');
		annual.disabled = Boolean(end && start !== end);
		if (annual.disabled) annual.checked = false;
	};
	body.querySelectorAll('[data-elab-blackout-row]').forEach(syncAnnual);
	editor.addEventListener('change', (event) => {
		const row = event.target.closest('[data-elab-blackout-row]');
		if (row) syncAnnual(row);
	});
	editor.addEventListener('click', (event) => {
		if (event.target.matches('[data-elab-remove-blackout]')) {
			event.target.closest('[data-elab-blackout-row]').remove();
			return;
		}
		if (!event.target.matches('[data-elab-add-blackout]')) return;
		const row = template.cloneNode(true);
		row.querySelectorAll('input').forEach((input) => {
			input.name = input.name.replace(new RegExp(`^${prefix}\\[\\d+\\]`), `${prefix}[${nextIndex}]`);
			if (input.type === 'checkbox') {
				input.checked = false;
				input.disabled = false;
			} else {
				input.value = '';
			}
		});
		nextIndex += 1;
		body.appendChild(row);
		row.querySelector('input[type="date"]').focus();
	});
});

/* Connection link: shown once the Google email field has an address; saves the advisor, then emails it. */
const inviteButton = document.querySelector('[data-elab-send-invite]');
if (inviteButton) {
	const email = document.querySelector('#elab-email');
	const flag = document.querySelector('[data-elab-send-invite-flag]');
	const looksLikeEmail = () => /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(email.value.trim());
	const sync = () => { inviteButton.hidden = !looksLikeEmail(); };
	email.addEventListener('input', sync);
	sync();
	inviteButton.addEventListener('click', () => {
		if (!looksLikeEmail()) return;
		flag.value = '1';
		// The server names an unnamed advisor; hide the title placeholder so the field is not left looking empty.
		document.querySelector('#title-prompt-text')?.classList.add('screen-reader-text');
		inviteButton.disabled = true;
		(document.querySelector('#publish') || document.querySelector('#save-post'))?.click();
	});
}

