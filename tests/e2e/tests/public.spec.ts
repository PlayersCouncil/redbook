import { test, expect, snap } from '../helpers/test';
import { baseURL } from '../playwright.config';

// Pages and API endpoints anyone can see without logging in.

test('home page renders with navigation', async ({ page }) => {
  await page.goto('/');
  await expect(page.locator('nav.navbar')).toBeVisible();
  await expect(page.locator('nav.navbar')).toContainText('Red Book');
  await expect(page.getByRole('link', { name: 'Decklists' }).first()).toBeVisible();
  await snap(page, 'home page');
  // The built CSS and JS bundles (gulp output) are referenced and load
  const css = await page.locator('link[rel=stylesheet][href*="/css/"]').count();
  const js = await page.locator('script[src*="/js/app"]').count();
  expect(css).toBeGreaterThan(0);
  expect(js).toBeGreaterThan(0);
});

test('card search finds cards by name and by key', async ({ page }) => {
  await page.goto('/find?q=legolas');
  await expect(page.locator('body')).toContainText('Legolas, Greenleaf');
  await page.goto('/find?q=f:elven+t:companion');
  await expect(page.locator('body')).toContainText('Arwen');
  await snap(page, 'Elven companions');
});

test('a card page shows the card, its revisions and its errata', async ({ page }) => {
  await page.goto('/card/1_50');
  await expect(page.locator('body')).toContainText('Legolas');
  await expect(page.locator('body')).toContainText('51_50');
  await snap(page, 'card 1_50');
});

test('formats are copied from Gemp, without the debug format', async ({ page, request }) => {
  await page.goto('/formats');
  await expect(page.locator('body')).toContainText('Fellowship Block');
  await page.goto('/formats/standard');
  await expect(page.locator('body')).toContainText('Banned');
  const formats = await (await request.get('/api/public/formats/')).json();
  expect(formats.length).toBeGreaterThan(10);
  expect(formats.map((f) => f.code)).not.toContain('debug');
});

test('decklist listings load, the old tabs as presets', async ({ page }) => {
  for (const [type, url] of [['popular', /\/decklists\?sort=rated$/], ['recent', /\/decklists$/], ['tournament', /\/decklists\?played=1$/]] as const) {
    const res = await page.goto(`/decklists/${type}`);
    expect(res?.status(), type).toBe(200);
    await expect(page).toHaveURL(url);
    await expect(page.locator('#browse-form')).toBeVisible();
    await expect(page.locator('.rb-preset.is-active')).toHaveCount(1);
  }
  // the pager: first and previous off on page 1, nine pages at most
  await page.goto('/decklists?sort=old');
  const pager = page.locator('.rb-results-head .rb-pager');
  if (await pager.count()) {
    await expect(pager.locator('.rb-page-step.is-disabled')).toHaveCount(2);
    expect(await pager.locator('.rb-page:not(.rb-page-step)').count()).toBeLessThanOrEqual(9);
    await Promise.all([page.waitForURL(/page=2/), pager.getByRole('link', { name: 'Next Page' }).click()]);
    await expect(pager.locator('.rb-page.is-current')).toHaveText('2');
  }
  await page.goto('/decklists/search');
  await expect(page).toHaveURL(/\/decklists$/);
});

test('anonymous users are sent to the login page for their decks', async ({ page }) => {
  await page.goto('/decks');
  await expect(page).toHaveURL(/\/login/);
});

test('public card API returns the card pool', async ({ request }) => {
  const cards = await (await request.get('/api/public/cards/')).json();
  expect(cards.length).toBeGreaterThan(3000);
  expect(cards[0]).toHaveProperty('code');
  expect(cards[0]).toHaveProperty('culture_code');

  const card = await (await request.get('/api/public/card/1_50')).json();
  expect(card.name).toBe('Legolas');
  expect(card.subtitle).toBe('Greenleaf');

  const packs = await (await request.get('/api/public/packs/')).json();
  expect(packs.length).toBeGreaterThan(10);
});

test('the Spanish site is served from the es. subdomain', async ({ page }) => {
  const url = new URL(baseURL);
  test.skip(!/[a-z]/i.test(url.hostname), 'needs a hostname, not an IP address');
  url.hostname = `es.${url.hostname}`;
  await page.goto(url.toString());
  await expect(page.locator('html')).toHaveAttribute('lang', 'es');
  await snap(page, 'Spanish home page');
});
