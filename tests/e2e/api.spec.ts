import { test, expect } from '@playwright/test';
import { TEST_EVENT_ID, TEST_EVENT_NAME, TEST_TOURNAMENT_ID, FIGHTERS } from './helpers/test-data';

/**
 * Read-only REST API (api.php) — public, no login. Uses the `request`
 * fixture directly against the HTTP contract; no browser needed.
 */
test('GET /v1/events returns the seeded event in a json envelope', async ({ request }) => {
  const res = await request.get('/api.php/v1/events');
  expect(res.status()).toBe(200);
  expect(res.headers()['content-type']).toContain('application/json');

  const body = await res.json();
  expect(body).toHaveProperty('data');
  const events = Object.values(body.data) as { eventName: string }[];
  expect(events.some((e) => e.eventName === TEST_EVENT_NAME)).toBe(true);
});

test('POST /v1/events is rejected with 405', async ({ request }) => {
  const res = await request.post('/api.php/v1/events');
  expect(res.status()).toBe(405);
  expect(res.headers()['allow']).toBe('GET');

  const body = await res.json();
  expect(body.error.code).toBe('method_not_allowed');
});

test('unknown API version returns 404', async ({ request }) => {
  const res = await request.get('/api.php/v2/events');
  expect(res.status()).toBe(404);
});

test('unknown route returns 404', async ({ request }) => {
  const res = await request.get('/api.php/v1/nonsense');
  expect(res.status()).toBe(404);
});

test('GET /v1/events/:id returns event detail with publication flags', async ({ request }) => {
  const res = await request.get(`/api.php/v1/events/${TEST_EVENT_ID}`);
  expect(res.status()).toBe(200);

  const body = await res.json();
  expect(body.data.eventName).toContain(TEST_EVENT_NAME);
  expect(body.data.matchesPublished).toBe(true);
});

test('GET /v1/events/:id/tournaments returns the seeded tournament', async ({ request }) => {
  const res = await request.get(`/api.php/v1/events/${TEST_EVENT_ID}/tournaments`);
  expect(res.status()).toBe(200);

  const body = await res.json();
  expect(body.data).toHaveProperty(String(TEST_TOURNAMENT_ID));
});

test('GET /v1/events/:id/participants returns the seeded fighters', async ({ request }) => {
  const res = await request.get(`/api.php/v1/events/${TEST_EVENT_ID}/participants`);
  expect(res.status()).toBe(200);

  const body = await res.json();
  const lastNames = (body.data as { lastName: string }[]).map((f) => f.lastName);
  for (const fighter of FIGHTERS) {
    expect(lastNames).toContain(fighter.lastName);
  }
});

test('GET /v1/events/:id for a nonexistent event returns 404', async ({ request }) => {
  const res = await request.get('/api.php/v1/events/999999');
  expect(res.status()).toBe(404);
});

test("GET /v1/events/:id for the internal reserved TEST_EVENT_ID (2) returns 404", async ({ request }) => {
  const res = await request.get('/api.php/v1/events/2');
  expect(res.status()).toBe(404);
});
