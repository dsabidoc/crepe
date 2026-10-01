<x-layouts.app title="Nueva cita">
    @php
        $serviceCategories = $services->pluck('category')->unique('id')->values();
        $selectedEmployeeIds = old('employee_ids', old('primary_employee_id') ? [old('primary_employee_id')] : ($selectedEmployeeId ? [$selectedEmployeeId] : []));
    @endphp

    <section class="page-title">
        <div><p class="eyebrow">AGENDA · CITA</p><h1>Nueva cita</h1><p>Selecciona clienta, estilista, fecha, hora y servicios para abrir su ticket operativo.</p></div>
        <a class="button button-secondary" href="{{ route('agenda.index', ['date' => $date]) }}">Cancelar</a>
    </section>

    <form class="surface detail-form appointment-form" method="POST" action="{{ route('appointments.store') }}">
        @csrf
        <div class="appointment-action-bar"><span>Los campos marcados con * son obligatorios.</span><button class="button button-primary" type="submit">Crear cita y ticket</button></div>
        <div class="form-section appointment-customer-section">
            <h2>Clienta y estilistas</h2><p>Busca una clienta existente o escribe su nombre para registrarla al crear la cita.</p>
            <div class="field-grid appointment-people-grid">
<div class="customer-picker"><span>Clienta <b class="required-mark">*</b></span><div class="customer-input-wrap"><input id="customer-search" name="customer_name" value="{{ old('customer_name', $selectedCustomerId ? $customers->firstWhere('id', $selectedCustomerId)?->full_name : '') }}" placeholder="Busca o escribe el nombre de la clienta" autocomplete="off" required><button type="button" id="customer-toggle" aria-label="Mostrar clientas">⌄</button></div><div class="customer-results" id="customer-results" hidden>@foreach($customers as $customer)<button type="button" class="customer-result" data-id="{{ $customer->id }}" data-name="{{ $customer->full_name }}"><strong>{{ $customer->full_name }}</strong><small>{{ $customer->phone ?: 'Sin celular registrado' }}</small></button>@endforeach</div><input type="hidden" name="customer_id" id="customer-id" value="{{ old('customer_id', $selectedCustomerId) }}"><small id="customer-match">Si no existe, se creará con este nombre.</small></div>
                <label><span>Celular <small>Opcional para una clienta nueva</small></span><input name="customer_phone" value="{{ old('customer_phone') }}" placeholder="999 000 0000" inputmode="tel"></label>
            </div>
            <div class="stylist-selector"><div><span>Estilistas <b class="required-mark">*</b></span><small>Selecciona una o dos. Si eliges dos, sólo aparecerán horarios disponibles para ambas.</small></div><div class="stylist-summary" id="stylist-summary" hidden><span class="stylist-summary-names" id="stylist-summary-names"></span><button type="button" id="edit-stylists">Agregar otra / Cambiar</button></div><div class="stylist-options" id="stylist-options">@foreach($employees as $employee)<label class="stylist-option"><input type="checkbox" name="employee_ids[]" value="{{ $employee->id }}" @checked(in_array($employee->id, $selectedEmployeeIds))><span class="profile-avatar small">{{ str($employee->first_name)->substr(0, 1) }}</span><span><strong>{{ $employee->full_name }}</strong><small>{{ $employee->position }}</small></span><i>✓</i></label>@endforeach</div><input type="hidden" name="primary_employee_id" id="primary-employee-id" value="{{ old('primary_employee_id', $selectedEmployeeId) }}">@error('employee_ids')<small class="form-error">{{ $message }}</small>@enderror</div>
        </div>

        <div class="form-section appointment-notes"><label class="full-field"><span>Notas <small>Opcional</small></span><textarea name="notes" rows="3" placeholder="Detalles útiles para el equipo…">{{ old('notes') }}</textarea></label></div>

        <div class="form-section appointment-schedule-section">
            <h2>Fecha, hora y duración</h2>
            <p>La duración sugerida toma como referencia los servicios, pero puedes ajustarla para reservar exactamente el tiempo necesario.</p>
            <div class="appointment-scheduling">
                <div class="schedule-control">
                    <span>Selecciona una fecha</span>
                    <input type="hidden" id="appointment-date" name="date" value="{{ old('date') ?: $date }}">
                    <button type="button" class="schedule-trigger" id="date-trigger"><b>▣</b><strong id="date-trigger-label"></strong><i>⌄</i></button>
                    <div class="schedule-popover date-popover" id="date-popover" hidden><div class="calendar-top"><button type="button" data-calendar-shift="-1">‹</button><strong id="calendar-title"></strong><button type="button" data-calendar-shift="1">›</button></div><div class="calendar-weekdays"><span>Lun</span><span>Mar</span><span>Mié</span><span>Jue</span><span>Vie</span><span>Sáb</span><span>Dom</span></div><div class="calendar-grid" id="calendar-grid"></div></div>
                </div>
                <div class="schedule-control">
                    <span>Selecciona una hora de inicio</span>
                    <input type="hidden" id="appointment-time" name="time" value="{{ old('time') ?: $time }}">
                    <button type="button" class="schedule-trigger" id="time-trigger"><b>◷</b><strong id="time-trigger-label"></strong><i>⌄</i></button>
                    <div class="schedule-slot-preview" id="schedule-slot-preview" aria-live="polite"></div>
                    <div class="schedule-popover time-popover" id="time-popover" hidden><h3>Selecciona una hora de inicio</h3><p class="time-popover-hint">Los bloques se reservan según la duración seleccionada.</p><div id="time-options">@foreach(['09:00','10:00','11:00','12:00','13:00','14:00','15:00','16:00','17:00','18:00'] as $time)<button type="button" class="time-option" data-time="{{ $time }}"><span>◷</span><b>{{ $time }}</b><small></small></button>@endforeach</div></div>
                    @error('time')<small class="form-error">{{ $message }}</small>@enderror
                </div>
                <div class="schedule-control schedule-duration-control">
                    <span>Duración de la cita</span>
                    <select class="schedule-duration" id="appointment-duration" name="duration_minutes" required>@foreach(range(30, 720, 30) as $minutes)<option value="{{ $minutes }}" @selected((int) old('duration_minutes', 60) === $minutes)>{{ $minutes >= 60 ? intdiv($minutes, 60).' h'.($minutes % 60 ? ' '.($minutes % 60).' min' : '') : $minutes.' min' }}</option>@endforeach</select>
                    <small class="duration-hint" id="duration-hint"></small>
                    @error('duration_minutes')<small class="form-error">{{ $message }}</small>@enderror
                </div>
            </div>
        </div>

        <div class="form-section appointment-services-section">
            <div class="service-picker-head"><div><h2>Servicios</h2><p>Elige uno o varios servicios. La estimación queda en la cita y se ajusta en el ticket si es necesario.</p></div><span id="service-selection-count">0 seleccionados</span></div>
            <div class="appointment-service-search"><span>⌕</span><input id="service-search" type="search" placeholder="Buscar servicio por nombre o categoría" autocomplete="off"></div>
            <div class="service-category-tabs"><button type="button" class="selected" data-service-filter="all">Todos</button>@foreach($serviceCategories as $category)<button type="button" data-service-filter="{{ $category->id }}"><i class="category-dot" style="background: {{ $category->color }}"></i>{{ $category->name }}</button>@endforeach</div>
            <div class="appointment-service-cards">@foreach($services as $service)<label class="appointment-service-card" data-service-card data-search="{{ str($service->name.' '.$service->category?->name)->lower() }}" data-category="{{ $service->service_category_id }}" data-duration="{{ $service->estimated_duration_minutes }}"><input type="checkbox" name="service_ids[]" value="{{ $service->id }}" @checked(in_array($service->id, old('service_ids', [])))><span class="service-card-mark" style="background: {{ $service->category->color }}">{{ str($service->category->name)->substr(0, 1) }}</span><span class="service-card-copy"><strong>{{ $service->name }}</strong><small>{{ $service->category->name }} · {{ $service->estimated_duration_minutes }} min</small><em>{{ $service->price_type === 'variable' ? 'Precio variable / cotizar' : '$'.number_format((float) $service->base_price, 0) }}</em></span><b class="service-card-add">+</b>@if($service->requires_color_bar)<i>Color Bar</i>@endif</label>@endforeach</div><p class="empty-state" id="service-empty" hidden>No encontramos servicios con esa búsqueda.</p>
            @error('service_ids')<small class="form-error">{{ $message }}</small>@enderror
        </div>

        <footer class="form-footer"><a href="{{ route('agenda.index', ['date' => $date]) }}">Cancelar</a><button class="button button-primary" type="submit">Crear cita y ticket</button></footer>
    </form>

    <script>
        (() => {
            const dateInput = document.getElementById('appointment-date');
            const timeInput = document.getElementById('appointment-time');
            const durationInput = document.getElementById('appointment-duration');
            const datePopover = document.getElementById('date-popover');
            const timePopover = document.getElementById('time-popover');
            const grid = document.getElementById('calendar-grid');
            const title = document.getElementById('calendar-title');
            const dateLabel = document.getElementById('date-trigger-label');
            const timeLabel = document.getElementById('time-trigger-label');
            const slotPreview = document.getElementById('schedule-slot-preview');
            const durationHint = document.getElementById('duration-hint');
            const cards = [...document.querySelectorAll('[data-service-card]')];
            const serviceSearch = document.getElementById('service-search');
            const serviceEmpty = document.getElementById('service-empty');
            const customerSearch = document.getElementById('customer-search');
            const customerId = document.getElementById('customer-id');
            const customerMatch = document.getElementById('customer-match');
            const customerResults = document.getElementById('customer-results');
            const customerToggle = document.getElementById('customer-toggle');
            const customerOptions = [...document.querySelectorAll('.customer-result')];
            const stylistChecks = [...document.querySelectorAll('.stylist-option input')];
            const primaryEmployee = document.getElementById('primary-employee-id');
            const stylistOptions = document.getElementById('stylist-options');
            const stylistSummary = document.getElementById('stylist-summary');
            const stylistSummaryNames = document.getElementById('stylist-summary-names');
            const editStylists = document.getElementById('edit-stylists');
            const count = document.getElementById('service-selection-count');
            const timeOptions = [...document.querySelectorAll('.time-option')];
            let selected = new Date(`${dateInput.value}T12:00:00`);
            let shown = new Date(selected);
            let availableTimes = null;
            let durationWasSelected = {{ old('duration_minutes') ? 'true' : 'false' }};
            const businessToday = @js(now()->toDateString());
            const monthFormatter = new Intl.DateTimeFormat('es-MX', { month: 'long', year: 'numeric' });
            const fullFormatter = new Intl.DateTimeFormat('es-MX', { day: 'numeric', month: 'long', year: 'numeric' });
            const pad = (number) => String(number).padStart(2, '0');
            const iso = (day) => `${day.getFullYear()}-${pad(day.getMonth() + 1)}-${pad(day.getDate())}`;
            const toMinutes = (time) => Number(time.slice(0, 2)) * 60 + Number(time.slice(3));
            const toTime = (minutes) => `${pad(Math.floor(minutes / 60) % 24)}:${pad(minutes % 60)}`;
            const durationLabel = (minutes) => minutes >= 60 ? `${Math.floor(minutes / 60)} h${minutes % 60 ? ` ${minutes % 60} min` : ''}` : `${minutes} min`;
            const estimatedDuration = () => cards.filter((card) => card.querySelector('input').checked).reduce((total, card) => total + Number(card.dataset.duration), 0);
            const selectedDuration = () => Number(durationInput.value);
            const roundedDuration = (minutes) => Math.min(720, Math.max(30, Math.ceil(minutes / 30) * 30));

            const syncCustomer = () => {
                const value = customerSearch.value.trim().toLowerCase();
                const match = customerOptions.find((option) => option.dataset.name.toLowerCase() === value);
                customerId.value = match?.dataset.id || '';
                customerMatch.textContent = match ? 'Clienta existente seleccionada.' : 'Si no existe, se creará con este nombre.';
                customerResults.hidden = false;
                customerOptions.forEach((option) => { option.hidden = value !== '' && !option.dataset.name.toLowerCase().includes(value); });
            };
            customerSearch.addEventListener('input', syncCustomer);
            customerSearch.addEventListener('focus', () => { customerResults.hidden = false; syncCustomer(); });
            customerToggle.addEventListener('click', () => { customerResults.hidden = !customerResults.hidden; if (!customerResults.hidden) customerSearch.focus(); });
            customerOptions.forEach((option) => option.addEventListener('click', () => { customerSearch.value = option.dataset.name; customerId.value = option.dataset.id; customerMatch.textContent = 'Clienta existente seleccionada.'; customerResults.hidden = true; }));
            document.addEventListener('click', (event) => { if (!event.target.closest('.customer-picker')) customerResults.hidden = true; });
            const syncStylists = (changed) => {
                const selected = stylistChecks.filter((input) => input.checked);
                if (selected.length > 2) { changed.checked = false; return; }
                primaryEmployee.value = selected[0]?.value || '';
                stylistChecks.forEach((input) => { input.disabled = !input.checked && selected.length >= 2; });
                stylistSummaryNames.textContent = selected.map((input) => input.closest('.stylist-option').querySelector('strong').textContent).join(' · ');
                stylistSummary.hidden = selected.length === 0;
                stylistOptions.hidden = selected.length > 0 && !stylistOptions.dataset.open;
            };
            stylistChecks.forEach((input) => input.addEventListener('change', () => { stylistOptions.dataset.open = ''; syncStylists(input); refreshAvailability(); }));
            editStylists.addEventListener('click', () => { stylistOptions.dataset.open = stylistOptions.hidden ? '1' : ''; stylistOptions.hidden = !stylistOptions.hidden; });
            syncStylists(stylistChecks[0]);

            const renderReservation = () => {
                const duration = selectedDuration();
                const start = timeInput.value;
                const end = toTime(toMinutes(start) + duration);
                const estimated = estimatedDuration();
                const now = new Date();
                const today = businessToday;
                const nowMinutes = now.getHours() * 60 + now.getMinutes();
                const isPastToday = dateInput.value === today;
                timeLabel.textContent = `${start} – ${end}`;
                durationHint.textContent = estimated ? `Sugerido por servicios: ${durationLabel(estimated)}` : 'Selecciona servicios para ver una sugerencia';
                timeOptions.forEach((button) => {
                    const optionEnd = toMinutes(button.dataset.time) + duration;
                    const startsInPast = isPastToday && toMinutes(button.dataset.time) <= nowMinutes;
                    button.disabled = startsInPast || optionEnd > 19 * 60 || (availableTimes !== null && !availableTimes.includes(button.dataset.time));
                    button.hidden = button.disabled;
                    button.querySelector('small').textContent = `${button.dataset.time} – ${toTime(optionEnd)} · ${durationLabel(duration)}`;
                });
                const selectedButton = timeOptions.find((button) => button.dataset.time === timeInput.value);
                if (selectedButton?.hidden) {
                    const nextButton = timeOptions.find((button) => !button.hidden);
                    if (nextButton) { timeInput.value = nextButton.dataset.time; renderReservation(); return; }
                }
                let slots = '';
                for (let offset = 0; offset < duration; offset += 60) {
                    slots += `<span>${toTime(toMinutes(start) + offset)} – ${toTime(toMinutes(start) + Math.min(offset + 60, duration))}</span>`;
                }
                slotPreview.innerHTML = `<strong>${durationLabel(duration)} reservados</strong><div class="slot-list">${slots}</div>`;
            };

            const refreshAvailability = async () => {
                const employeeIds = stylistChecks.filter((input) => input.checked).map((input) => input.value);
                if (!employeeIds.length) { availableTimes = null; renderReservation(); return; }
                const params = new URLSearchParams({ date: dateInput.value, duration_minutes: durationInput.value });
                employeeIds.forEach((id) => params.append('employee_ids[]', id));
                try { const response = await fetch(`{{ route('appointments.availability') }}?${params}`); availableTimes = response.ok ? (await response.json()).available : null; } catch { availableTimes = null; }
                if (availableTimes.length && !availableTimes.includes(timeInput.value)) timeInput.value = availableTimes[0];
                renderReservation();
            };

            const renderCalendar = () => {
                title.textContent = monthFormatter.format(shown);
                grid.innerHTML = '';
                const first = new Date(shown.getFullYear(), shown.getMonth(), 1);
                const offset = (first.getDay() + 6) % 7;
                const start = new Date(shown.getFullYear(), shown.getMonth(), 1 - offset);
                for (let index = 0; index < 42; index++) {
                    const day = new Date(start);
                    day.setDate(start.getDate() + index);
                    const button = document.createElement('button');
                    button.type = 'button';
                    button.textContent = day.getDate();
                    button.className = `calendar-day${day.getMonth() !== shown.getMonth() ? ' muted' : ''}${iso(day) === iso(selected) ? ' selected' : ''}`;
                    const today = new Date(`${businessToday}T00:00:00`);
                    button.disabled = day < today;
                    button.addEventListener('click', () => {
                        selected = day;
                        dateInput.value = iso(day);
                        dateLabel.textContent = fullFormatter.format(day);
                        datePopover.hidden = true;
                        renderCalendar();
                        refreshAvailability();
                    });
                    grid.appendChild(button);
                }
            };

            dateLabel.textContent = fullFormatter.format(selected);
            renderCalendar();
            document.getElementById('date-trigger').addEventListener('click', () => { datePopover.hidden = !datePopover.hidden; timePopover.hidden = true; });
            document.getElementById('time-trigger').addEventListener('click', () => { timePopover.hidden = !timePopover.hidden; datePopover.hidden = true; });
            document.querySelectorAll('[data-calendar-shift]').forEach((button) => button.addEventListener('click', () => { shown.setMonth(shown.getMonth() + Number(button.dataset.calendarShift)); renderCalendar(); }));
            timeOptions.forEach((button) => button.addEventListener('click', () => { if (!button.disabled) { timeInput.value = button.dataset.time; renderReservation(); timePopover.hidden = true; } }));
            durationInput.addEventListener('change', () => { durationWasSelected = true; renderReservation(); refreshAvailability(); });
            let serviceCategory = 'all';
            const filterServices = () => { const term = serviceSearch.value.toLowerCase().trim(); const visible = cards.filter((card) => { const show = card.dataset.search.includes(term) && (serviceCategory === 'all' || card.dataset.category === serviceCategory); card.hidden = !show; return show; }); serviceEmpty.hidden = visible.length > 0; };
            serviceSearch.addEventListener('input', filterServices);
            document.querySelectorAll('[data-service-filter]').forEach((button) => button.addEventListener('click', () => { serviceCategory = button.dataset.serviceFilter; document.querySelectorAll('[data-service-filter]').forEach((item) => item.classList.toggle('selected', item === button)); filterServices(); }));
            const updateServices = () => {
                const selectedCards = cards.filter((card) => card.querySelector('input').checked);
                const estimated = estimatedDuration();
                cards.forEach((card) => card.classList.toggle('selected', card.querySelector('input').checked));
                count.textContent = `${selectedCards.length} seleccionado${selectedCards.length === 1 ? '' : 's'}`;
                if (!durationWasSelected && estimated) { durationInput.value = roundedDuration(estimated); }
                renderReservation();
            };
            cards.forEach((card) => card.querySelector('input').addEventListener('change', updateServices));
            updateServices();
            refreshAvailability();
        })();
    </script>
</x-layouts.app>
