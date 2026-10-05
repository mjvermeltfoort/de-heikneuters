import ical from 'node-ical';

const CALENDAR_ID = 'heikneutersnijnsel@gmail.com';
const CALENDAR_URL =
  `https://calendar.google.com/calendar/ical/${encodeURIComponent(CALENDAR_ID)}/public/basic.ics`;

const SITE_TIMEZONE = 'Europe/Amsterdam';
const MAX_EVENTS = 100;

export type CalendarEvent = {
  id: string;
  title: string;
  start: Date;
  end: Date;
  location: string;
  description: string;
  allDay: boolean;
};

function toCalendarEvent(event: any, fallbackId: string): CalendarEvent | null {
  if (!event?.start) return null;

  const start = new Date(event.start);
  const end = event.end ? new Date(event.end) : new Date(event.start);

  if (Number.isNaN(start.getTime()) || Number.isNaN(end.getTime())) {
    return null;
  }

  return {
    id: String(event.uid ?? fallbackId),
    title: String(event.summary ?? 'Activiteit'),
    start,
    end,
    location: String(event.location ?? '').trim(),
    description: String(event.description ?? '').trim(),
    allDay: Boolean(event.isFullDay ?? event.datetype === 'date'),
  };
}

export async function getUpcomingEvents(): Promise<CalendarEvent[]> {
  const now = new Date();
  const from = new Date(now);
  from.setDate(from.getDate() - 1);

  const to = new Date(now);
  to.setFullYear(to.getFullYear() + 2);

  const data = await ical.async.fromURL(CALENDAR_URL, {
    headers: {
      'User-Agent': 'de-heikneuters.nl calendar build',
    },
    signal: AbortSignal.timeout(10_000),
  });

  const events: CalendarEvent[] = [];

  for (const [key, item] of Object.entries(data)) {
    const event: any = item;

    if (event?.type !== 'VEVENT') continue;

    // Recurrence overrides are applied by expandRecurringEvent on the parent event.
    if (event.recurrenceid) continue;

    if (event.rrule) {
      const instances = ical.expandRecurringEvent(event, {
        from,
        to,
        includeOverrides: true,
        excludeExdates: true,
        expandOngoing: true,
      });

      instances.forEach((instance: any, index: number) => {
        const parsed = toCalendarEvent(instance, `${key}-${index}`);
        if (parsed && parsed.end >= now) {
          events.push(parsed);
        }
      });

      continue;
    }

    const parsed = toCalendarEvent(event, key);
    if (parsed && parsed.end >= now && parsed.start <= to) {
      events.push(parsed);
    }
  }

  return events
    .sort((a, b) => a.start.getTime() - b.start.getTime())
    .slice(0, MAX_EVENTS);
}

export function formatDay(event: CalendarEvent): string {
  return new Intl.DateTimeFormat('nl-NL', {
    timeZone: SITE_TIMEZONE,
    day: '2-digit',
  }).format(event.start);
}

export function formatMonth(event: CalendarEvent): string {
  return new Intl.DateTimeFormat('nl-NL', {
    timeZone: SITE_TIMEZONE,
    month: 'short',
  })
    .format(event.start)
    .replace('.', '')
    .toUpperCase();
}

export function formatDate(event: CalendarEvent): string {
  return new Intl.DateTimeFormat('nl-NL', {
    timeZone: SITE_TIMEZONE,
    weekday: 'long',
    day: 'numeric',
    month: 'long',
    year: 'numeric',
  }).format(event.start);
}

export function formatTime(event: CalendarEvent): string {
  if (event.allDay) return 'Hele dag';

  return new Intl.DateTimeFormat('nl-NL', {
    timeZone: SITE_TIMEZONE,
    hour: '2-digit',
    minute: '2-digit',
    hour12: false,
  }).format(event.start);
}

export function formatEventMeta(event: CalendarEvent): string {
  return [event.location, formatTime(event)].filter(Boolean).join(' · ');
}
