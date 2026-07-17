/**
 * Thin fetch wrapper for the Laravel JSON API.
 *
 * Uses relative `/api` by default (Vite proxies to the backend in monorepo/local).
 * Override with VITE_API_BASE_URL for a separately hosted frontend.
 */
const apiBase = (import.meta.env.VITE_API_BASE_URL ?? '').replace(/\/$/, '');

export async function apiGet<T>(path: string): Promise<T> {
    const url = `${apiBase}${path.startsWith('/') === true ? path : `/${path}`}`;
    const response = await fetch(url, {
        headers: {
            Accept: 'application/json',
        },
    });

    if (response.ok === false) {
        throw new Error(`API request failed (${response.status}) for ${url}`);
    }

    return (await response.json()) as T;
}
