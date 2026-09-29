/**
 * Reads the CSRF token Laravel put in a cookie.
 *
 * For plain fetch calls (a file download, a JSON preview, a secret reveal)
 * that are not Inertia visits and so have to carry the token themselves.
 */
export function csrfToken(): string {
    const cookie = document.cookie
        .split('; ')
        .find((entry) => entry.startsWith('XSRF-TOKEN='));

    return cookie ? decodeURIComponent(cookie.split('=')[1]) : '';
}
