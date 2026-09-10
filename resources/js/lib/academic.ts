export const academicWeekdays = [
    'Monday',
    'Tuesday',
    'Wednesday',
    'Thursday',
    'Friday',
    'Saturday',
    'Sunday',
];

export function formatAcademicDate(date: string): string {
    return new Intl.DateTimeFormat(undefined, {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    }).format(new Date(`${date}T00:00:00`));
}

export function formatAcademicTime(minutes: number): string {
    return minutes === 1440
        ? '24:00'
        : `${Math.floor(minutes / 60)
              .toString()
              .padStart(2, '0')}:${(minutes % 60).toString().padStart(2, '0')}`;
}
