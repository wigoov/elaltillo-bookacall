/** Public booking form behavior, bundled by this plugin's unified build. */
export function initElabBookingForms() {
	const forms = document.querySelectorAll('[data-elab-booking]');
	if (!forms.length || !window.elabBooking) return;

	forms.forEach((form) => {
		if (form.dataset.elabInitialized) return;
		form.dataset.elabInitialized = '1';
		const advisors = Array.from(form.querySelectorAll('input[name="advisor"]'));
		if (!advisors.length) return;
		const chosenAdvisor = () => advisors.find((input) => input.checked)?.value || '';
		const date = form.elements.date;
		const timezone = form.elements.visitor_timezone;
		const slot = form.elements.slot;
		const message = form.querySelector('[data-elab-message]');
		const calendarActions = form.querySelector('[data-elab-calendar-actions]');
		let slotRequest = 0;
		timezone.value = Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC';
		const today = new Date();
		today.setMinutes(today.getMinutes() - today.getTimezoneOffset());
		date.min = today.toISOString().slice(0, 10);

		function setMessage(text, isError = false) {
			message.textContent = text || '';
			message.classList.toggle('hidden', !text);
			message.classList.toggle('bg-accent', Boolean(text) && isError);
		}

		function showCalendarChoices(calendar) {
			calendarActions.replaceChildren();
			calendarActions.classList.remove('hidden');
			if (calendar.url) {
				const google = document.createElement('a');
				google.href = calendar.url;
				google.target = '_blank';
				google.rel = 'noopener noreferrer';
				google.className = 'rounded-full border border-secondary bg-accent px-4 py-2';
				google.textContent = 'Open in Google Calendar';
				calendarActions.appendChild(google);
			}
			const escape = (value) => String(value).replace(/\\/g, '\\\\').replace(/,/g, '\\,').replace(/;/g, '\\;').replace(/\n/g, '\\n');
			const stamp = (value) => value.replace(/[-:]/g, '');
			const ics = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//El Altillo//Book a Call//EN', 'METHOD:PUBLISH', 'BEGIN:VEVENT', `UID:${escape(calendar.uid || 'elab-call')}`, `DTSTAMP:${stamp(new Date().toISOString().slice(0, 19) + 'Z')}`, `DTSTART:${stamp(calendar.start)}`, `DTEND:${stamp(calendar.end)}`, `SUMMARY:${escape(calendar.title)}`, 'END:VEVENT', 'END:VCALENDAR'].join('\r\n');
			const download = document.createElement('a');
			download.href = URL.createObjectURL(new Blob([ics], { type: 'text/calendar;charset=utf-8' }));
			download.download = 'el-altillo-call.ics';
			download.className = 'rounded-full border border-secondary px-4 py-2';
			download.textContent = 'Apple, Outlook or another calendar';
			calendarActions.appendChild(download);
		}

		function loadSlots() {
			const requestId = ++slotRequest;
			setMessage('');
			slot.disabled = true;
			slot.innerHTML = '<option value="">Loading available times…</option>';
			if (!chosenAdvisor() || !date.value) {
				slot.innerHTML = '<option value="">Choose advisor and date first</option>';
				return;
			}
			const params = new URLSearchParams({ advisor: chosenAdvisor(), date: date.value, timezone: timezone.value });
			fetch(`${elabBooking.api}/slots?${params.toString()}`, { headers: { 'X-WP-Nonce': elabBooking.nonce } })
				.then(async (response) => {
					const data = await response.json();
					if (!response.ok) throw new Error(data.message || 'Could not load available times.');
					return data;
				})
				.then((slots) => {
					if (requestId !== slotRequest) return;
					slot.innerHTML = '';
					const first = document.createElement('option');
					first.value = '';
					first.textContent = slots.length ? 'Choose a time' : 'No times available on this date';
					slot.appendChild(first);
					slots.forEach((item) => {
						const option = document.createElement('option');
						option.value = item.value;
						option.textContent = item.label;
						slot.appendChild(option);
					});
					slot.disabled = !slots.length;
				})
				.catch((error) => {
					if (requestId !== slotRequest) return;
					slot.innerHTML = '<option value="">Times could not be loaded</option>';
					setMessage(error.message, true);
				});
		}

		advisors.forEach((input) => input.addEventListener('change', () => {
			form.querySelectorAll('[data-elab-advisor-card]').forEach((card) => {
				const selected = card.querySelector('input').checked;
				card.classList.toggle('bg-accent', selected);
				card.classList.toggle('bg-primary', !selected);
			});
			loadSlots();
		}));
		date.addEventListener('change', loadSlots);

		form.addEventListener('submit', (event) => {
			event.preventDefault();
			calendarActions.classList.add('hidden');
			setMessage('Sending your request…');
			const data = new FormData(form);
			data.set('start', slot.value);
			data.set('timezone', timezone.value);
			fetch(`${elabBooking.api}/bookings`, { method: 'POST', headers: { 'X-WP-Nonce': elabBooking.nonce }, body: data })
				.then(async (response) => {
					const result = await response.json();
					if (!response.ok) throw new Error(result.message || 'Your request could not be sent.');
					return result;
				})
				.then((result) => {
					++slotRequest;
					form.reset();
					form.querySelectorAll('[data-elab-advisor-card]').forEach((card) => { card.classList.remove('bg-accent'); card.classList.add('bg-primary'); });
					timezone.value = Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC';
					slot.disabled = true;
					slot.innerHTML = '<option value="">Choose advisor and date first</option>';
					setMessage(result.message);
					if (result.calendar) showCalendarChoices(result.calendar);
				})
				.catch((error) => { setMessage(error.message, true); loadSlots(); });
		});
	});
}
