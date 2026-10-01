<x-layouts.app title="Agenda">
    @php
        $agendaParameters = ['view' => $view];

        if ($selectedEmployeeIds !== []) {
            $agendaParameters['employees'] = $selectedEmployeeIds;
        }

        $previousDate = match ($view) {
            'week' => $date->copy()->subWeek(),
            'month' => $date->copy()->subMonth(),
            default => $date->copy()->subDay(),
        };
        $nextDate = match ($view) {
            'week' => $date->copy()->addWeek(),
            'month' => $date->copy()->addMonth(),
            default => $date->copy()->addDay(),
        };
        $calendarLabel = match ($view) {
            'week' => 'Semana del '.$periodStart->translatedFormat('l d').' al '.$periodEnd->translatedFormat('l d \d\e F \d\e Y'),
            'month' => $periodStart->translatedFormat('F \d\e Y'),
            default => $date->translatedFormat('l, d \d\e F \d\e Y'),
        };
        $timeSlots = ['09:00', '10:00', '11:00', '12:00', '13:00', '14:00', '15:00', '16:00', '17:00', '18:00'];
    @endphp

    <section class="page-title">
        <div>
            <p class="eyebrow">AGENDA</p>
            <h1>Calendario de estilistas</h1>
            <p>{{ $calendarLabel }}</p>
        </div>
        <a class="button button-primary" href="{{ route('appointments.create', ['date' => $date->toDateString()]) }}">+ Nueva cita</a>
    </section>

    <section class="agenda-toolbar">
        <a class="date-shift" aria-label="Periodo anterior" href="{{ route('agenda.index', array_merge($agendaParameters, ['date' => $previousDate->toDateString()])) }}">←</a>
        <form method="GET" class="agenda-date-form">
            <input type="hidden" name="view" value="{{ $view }}">
            @foreach ($selectedEmployeeIds as $employeeId)
                <input type="hidden" name="employees[]" value="{{ $employeeId }}">
            @endforeach
            <input type="date" aria-label="Fecha de agenda" name="date" value="{{ $date->toDateString() }}" onchange="this.form.submit()">
        </form>
        <a class="date-shift" aria-label="Periodo siguiente" href="{{ route('agenda.index', array_merge($agendaParameters, ['date' => $nextDate->toDateString()])) }}">→</a>
        <a class="today-link" href="{{ route('agenda.index', $agendaParameters) }}">Hoy</a>

        <details class="agenda-filter">
            <summary>
                <span class="filter-icon">⌄</span>
                {{ $selectedEmployeeIds === [] ? 'Todas las estilistas' : count($selectedEmployeeIds).' estilistas' }}
            </summary>
            <form method="GET">
                <input type="hidden" name="date" value="{{ $date->toDateString() }}">
                <input type="hidden" name="view" value="{{ $view }}">
                <div class="agenda-filter-list">
                    @foreach ($filterEmployees as $employee)
                        <label>
                            <input type="checkbox" name="employees[]" value="{{ $employee->id }}" @checked(in_array($employee->id, $selectedEmployeeIds, true))>
                            <span class="employee-dot"></span>
                            {{ $employee->full_name }}
                        </label>
                    @endforeach
                </div>
                <footer>
                    <a href="{{ route('agenda.index', ['date' => $date->toDateString(), 'view' => $view]) }}">Limpiar</a>
                    <button class="button button-primary" type="submit">Aplicar filtro</button>
                </footer>
            </form>
        </details>

        <div class="segmented agenda-view" aria-label="Vista de calendario">
            <a class="{{ $view === 'day' ? 'selected' : '' }}" href="{{ route('agenda.index', array_merge($agendaParameters, ['date' => $date->toDateString(), 'view' => 'day'])) }}">Día</a>
            <a class="{{ $view === 'week' ? 'selected' : '' }}" href="{{ route('agenda.index', array_merge($agendaParameters, ['date' => $date->toDateString(), 'view' => 'week'])) }}">Semana</a>
            <a class="{{ $view === 'month' ? 'selected' : '' }}" href="{{ route('agenda.index', array_merge($agendaParameters, ['date' => $date->toDateString(), 'view' => 'month'])) }}">Mes</a>
        </div>
    </section>

    @if ($view === 'day')
        <section class="agenda-board surface">
            <div class="agenda-head" style="grid-template-columns:74px repeat({{ $employees->count() }}, minmax(220px,1fr))">
                <span>HORA</span>
                @foreach ($employees as $employee)
                    <span><i class="employee-dot"></i>{{ $employee->full_name }}</span>
                @endforeach
            </div>
            <div class="agenda-body" style="grid-template-columns:74px repeat({{ $employees->count() }}, minmax(220px,1fr))">
                <div class="time-column">
                    @foreach ($timeSlots as $time)
                        <span>{{ $time }}</span>
                    @endforeach
                </div>
                @forelse ($employees as $employee)
                    <div class="employee-column" data-new-appointment-date="{{ $date->toDateString() }}" data-employee-id="{{ $employee->id }}" title="Doble clic para crear una cita a esta hora">
                        @forelse ($appointmentsByEmployee->get($employee->id, collect()) as $appointment)
                            @php
                                $minutesFromStart = max(0, $periodStart->copy()->setTime(9, 0)->diffInMinutes($appointment->starts_at, false));
                                $durationMinutes = max(30, $appointment->starts_at->diffInMinutes($appointment->ends_at));
                            @endphp
                            <a class="appointment-block {{ $appointment->status }}" style="--agenda-top: {{ round($minutesFromStart * 56 / 60) }}px; --agenda-height: {{ round($durationMinutes * 56 / 60) }}px" href="{{ $appointment->ticket ? route('tickets.show', $appointment->ticket) : route('agenda.index') }}">
                                <small>Inicio · {{ $appointment->starts_at->format('H:i') }}</small>
                                <strong>{{ $appointment->customer->full_name }}</strong>
                                <span>{{ $appointment->services->pluck('name_snapshot')->join(' + ') }}</span>
                            </a>
                        @empty
                            <div class="free-slot">Disponible</div>
                        @endforelse
                    </div>
                @empty
                    <div class="agenda-empty-state">No hay estilistas que coincidan con este filtro.</div>
                @endforelse
            </div>
        </section>
    @elseif ($view === 'week')
        <section class="agenda-week surface">
            <div class="agenda-week-head" style="grid-template-columns:74px repeat(7, minmax(155px,1fr))">
                <span>HORA</span>
                @foreach ($weekDays as $weekDay)
                    <span class="{{ $weekDay->isToday() ? 'is-today' : '' }}">
                        <small>{{ $weekDay->translatedFormat('D') }}</small>
                        <strong>{{ $weekDay->format('d') }}</strong>
                    </span>
                @endforeach
            </div>
            <div class="agenda-week-body" style="grid-template-columns:74px repeat(7, minmax(155px,1fr))">
                <div class="time-column">
                    @foreach ($timeSlots as $time)
                        <span>{{ $time }}</span>
                    @endforeach
                </div>
                @foreach ($weekDays as $weekDay)
                    @php
                        $dayAppointments = $appointmentsByDate->get($weekDay->toDateString(), collect())->sortBy('starts_at')->values();
                        $laneEnds = [];
                        $appointmentLanes = [];

                        foreach ($dayAppointments as $dayAppointment) {
                            $lane = null;

                            foreach ($laneEnds as $laneIndex => $laneEnd) {
                                if ($laneEnd->lte($dayAppointment->starts_at)) {
                                    $lane = $laneIndex;
                                    break;
                                }
                            }

                            if ($lane === null) {
                                $lane = count($laneEnds);
                            }

                            $laneEnds[$lane] = $dayAppointment->ends_at;
                            $appointmentLanes[$dayAppointment->id] = $lane;
                        }

                        $laneCount = max(1, count($laneEnds));
                    @endphp
                    <div class="agenda-week-day {{ $weekDay->isToday() ? 'is-today' : '' }}" data-new-appointment-date="{{ $weekDay->toDateString() }}" title="Doble clic para crear una cita a esta hora">
                        @forelse ($dayAppointments as $appointment)
                            @php
                                $minutesFromStart = max(0, $weekDay->copy()->setTime(9, 0)->diffInMinutes($appointment->starts_at, false));
                                $durationMinutes = max(30, $appointment->starts_at->diffInMinutes($appointment->ends_at));
                                $appointmentLane = $appointmentLanes[$appointment->id] ?? 0;
                                $appointmentStart = $appointment->starts_at->format('H:i');
                                $appointmentEnd = $appointment->ends_at->format('H:i');
                                $appointmentServices = $appointment->services->pluck('name_snapshot')->join(' + ');
                                $appointmentDetails = $appointment->customer->full_name.' · '.$appointment->employee->full_name.' · '.$appointmentStart.'–'.$appointmentEnd.' · '.$appointmentServices;
                            @endphp
                            <a class="week-appointment {{ $appointment->status }} {{ $durationMinutes <= 60 ? 'compact' : '' }}" title="{{ $appointmentDetails }}" aria-label="{{ $appointmentDetails }}" style="--agenda-top: {{ round($minutesFromStart * 56 / 60) }}px; --agenda-height: {{ round($durationMinutes * 56 / 60) }}px; --agenda-left: {{ round($appointmentLane * 100 / $laneCount, 3) }}%; --agenda-width: {{ round(100 / $laneCount, 3) }}%" href="{{ $appointment->ticket ? route('tickets.show', $appointment->ticket) : route('agenda.index') }}">
                                <small class="week-appointment-time">{{ $appointmentStart }}–{{ $appointmentEnd }}</small>
                                <strong class="week-appointment-customer">{{ $appointment->customer->full_name }}</strong>
                                <span class="week-appointment-stylist">{{ $appointment->employee->full_name }}</span>
                                <span class="week-appointment-services">{{ $appointmentServices }}</span>
                            </a>
                        @empty
                            <span class="agenda-week-available">Disponible</span>
                        @endforelse
                    </div>
                @endforeach
            </div>
        </section>
    @else
        <section class="agenda-month surface">
            <div class="agenda-month-head">
                @foreach (['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'] as $weekday)
                    <span>{{ $weekday }}</span>
                @endforeach
            </div>
            <div class="agenda-month-grid">
                @foreach ($monthDays as $monthDay)
                    @php($dayAppointments = $appointmentsByDate->get($monthDay->toDateString(), collect()))
                    <article class="agenda-month-day {{ $monthDay->isSameMonth($periodStart) ? '' : 'outside-month' }} {{ $monthDay->isToday() ? 'is-today' : '' }}" data-new-appointment-date="{{ $monthDay->toDateString() }}" title="Doble clic para crear una cita">
                        <header>
                            <time datetime="{{ $monthDay->toDateString() }}">{{ $monthDay->format('d') }}</time>
                            @if ($monthDay->isSameMonth($periodStart) && $monthDay->day === 1)
                                <small>{{ $monthDay->translatedFormat('F') }}</small>
                            @endif
                        </header>
                        <div class="agenda-month-events">
                            @foreach ($dayAppointments->take(3) as $appointment)
                                <a class="month-appointment {{ $appointment->status }}" href="{{ $appointment->ticket ? route('tickets.show', $appointment->ticket) : route('agenda.index') }}">
                                    <time>{{ $appointment->starts_at->format('H:i') }}</time>
                                    <span>{{ $appointment->customer->full_name }}</span>
                                    <small>{{ $appointment->employee->first_name }}</small>
                                </a>
                            @endforeach
                            @if ($dayAppointments->count() > 3)
                                <span class="month-more">+ {{ $dayAppointments->count() - 3 }} citas</span>
                            @endif
                        </div>
                    </article>
                @endforeach
            </div>
        </section>
    @endif

    <script>
        (() => {
            const appointmentUrl = @json(route('appointments.create'));
            const scheduleStartMinutes = 9 * 60;
            const slotHeight = 56;
            const pad = (value) => String(value).padStart(2, '0');
            const toTime = (minutes) => `${pad(Math.floor(minutes / 60))}:${pad(minutes % 60)}`;

            document.querySelectorAll('[data-new-appointment-date]').forEach((calendarCell) => {
                calendarCell.addEventListener('dblclick', (event) => {
                    if (event.target.closest('a, button, input, select, textarea, details')) {
                        return;
                    }

                    const date = calendarCell.dataset.newAppointmentDate;
                    const isTimedGrid = calendarCell.classList.contains('agenda-week-day') || calendarCell.classList.contains('employee-column');
                    const minutesFromTop = isTimedGrid
                        ? Math.max(0, Math.floor((event.clientY - calendarCell.getBoundingClientRect().top) / slotHeight)) * 60
                        : 0;
                    const params = new URLSearchParams({ date, time: toTime(scheduleStartMinutes + minutesFromTop) });

                    if (calendarCell.dataset.employeeId) {
                        params.set('employee_id', calendarCell.dataset.employeeId);
                    }

                    window.location.assign(`${appointmentUrl}?${params.toString()}`);
                });
            });
        })();
    </script>
</x-layouts.app>
