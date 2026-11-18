import { getConfig } from '@/config';

/** Error of a REST call: HTTP status, the plugin's error code and, for validation problems, the findings. */
export class ApiError extends Error {
  constructor(
    public readonly status: number,
    public readonly code: string,
    message: string,
    public readonly payload: Record<string, unknown> = {},
  ) {
    super(message);
    this.name = 'ApiError';
  }
}

type Method = 'GET' | 'POST' | 'PUT' | 'DELETE';

/** Calls the plugin's REST API with the cookie session and the REST nonce (CSRF protection of the admin routes). */
export async function request<T>(
  method: Method,
  route: string,
  body?: unknown,
  query?: Record<string, string>,
): Promise<T> {
  const config = getConfig();
  const url = new URL(config.restRoot.replace(/\/$/, '') + route, window.location.origin);
  for (const [key, value] of Object.entries(query ?? {})) url.searchParams.set(key, value);
  const response = await fetch(url.toString(), {
    method,
    credentials: 'same-origin',
    headers: {
      'X-WP-Nonce': config.nonce,
      Accept: 'application/json',
      ...(body === undefined ? {} : { 'Content-Type': 'application/json' }),
    },
    body: body === undefined ? undefined : JSON.stringify(body),
  });
  const payload = (await response.json().catch(() => ({}))) as Record<string, unknown>;
  if (!response.ok) {
    const code = typeof payload.code === 'string' ? payload.code : 'request_failed';
    const message =
      typeof payload.message === 'string' ? payload.message : `HTTP ${response.status}`;
    throw new ApiError(response.status, code, message, payload);
  }
  return payload as T;
}
