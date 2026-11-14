/**
 * fetch() wrapper for the Laravel JSON API (routes/api.php).
 *
 * Adds the CSRF token (Laravel's XSRF-TOKEN cookie) and JSON Accept header,
 * and sends the session cookie so admin endpoints are authenticated.
 * Use it exactly like fetch().
 */
function xsrfToken(): string | undefined {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);
    return match ? decodeURIComponent(match[1]) : undefined;
}

export function apiFetch(input: string, init: RequestInit = {}): Promise<Response> {
    const headers = new Headers(init.headers);
    headers.set('Accept', headers.get('Accept') ?? 'application/json');
    headers.set('X-Requested-With', 'XMLHttpRequest');

    const token = xsrfToken();
    if (token) headers.set('X-XSRF-TOKEN', token);

    return fetch(input, { credentials: 'same-origin', ...init, headers });
}

export default apiFetch;
