import { http, HttpResponseError } from '@inertiajs/core';
import type { ScheduleIssue } from '@/types';

const isRecord = (value: unknown): value is Record<string, unknown> =>
    typeof value === 'object' && value !== null && !Array.isArray(value);

// Scheduling commands use structured conflict envelopes, not Laravel form errors.
// useHttp consumes 422 responses, so use the same XHR client without that conversion.
export async function saveScheduleEntry(
    route: { method: 'post' | 'patch'; url: string },
    data: object,
    idempotencyKey?: string,
): Promise<string> {
    const response = await http.getClient().request({
        ...route,
        data: JSON.stringify(data),
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            ...(idempotencyKey ? { 'Idempotency-Key': idempotencyKey } : {}),
        },
    });

    if (response.status < 200 || response.status >= 300) {
        throw new HttpResponseError(
            `Request failed with status ${response.status}`,
            response,
            route.url,
        );
    }

    const body: unknown = JSON.parse(response.data);

    if (
        !isRecord(body) ||
        !isRecord(body.data) ||
        typeof body.data.id !== 'string'
    ) {
        throw new Error(
            'The server returned an unexpected schedule response. Refresh the timetable before trying again.',
        );
    }

    return body.data.id;
}

export function schedulingError(error: unknown): {
    message: string;
    issues: ScheduleIssue[];
} {
    const result: { message: string; issues: ScheduleIssue[] } = {
        message:
            error instanceof Error
                ? error.message
                : 'The schedule could not be saved. Please try again.',
        issues: [],
    };

    if (!(error instanceof HttpResponseError)) {
        return result;
    }

    let body: unknown;

    try {
        body = JSON.parse(error.response.data);
    } catch (parseError) {
        if (!(parseError instanceof SyntaxError)) {
            throw parseError;
        }

        result.message =
            'The server returned an unreadable response. Please try again or contact your administrator.';

        return result;
    }

    if (!isRecord(body) || !isRecord(body.error)) {
        return result;
    }

    if (typeof body.error.message === 'string') {
        result.message = body.error.message;
    }

    if (!Array.isArray(body.error.issues)) {
        return result;
    }

    for (const issue of body.error.issues) {
        if (
            !isRecord(issue) ||
            typeof issue.code !== 'string' ||
            typeof issue.message !== 'string' ||
            typeof issue.field !== 'string' ||
            (issue.severity !== 'hard' && issue.severity !== 'soft')
        ) {
            continue;
        }

        result.issues.push({
            code: issue.code,
            field: issue.field,
            severity: issue.severity,
            message: issue.message,
            rule_code:
                typeof issue.rule_code === 'string'
                    ? issue.rule_code
                    : issue.code,
            details: isRecord(issue.details) ? issue.details : {},
            resource:
                isRecord(issue.resource) &&
                typeof issue.resource.name === 'string'
                    ? {
                          id:
                              typeof issue.resource.id === 'string'
                                  ? issue.resource.id
                                  : null,
                          name: issue.resource.name,
                      }
                    : undefined,
            conflicting_entry_id:
                typeof issue.conflicting_entry_id === 'string'
                    ? issue.conflicting_entry_id
                    : undefined,
        });
    }

    return result;
}
