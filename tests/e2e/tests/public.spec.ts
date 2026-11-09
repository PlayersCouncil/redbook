import { test, expect, snap } from '../helpers/test';
import { baseURL } from '../playwright.config';

// Pages and API endpoints anyone can see without logging in.

test('home page renders with navigation', async ({ page }) => {
  await page.goto('/');
  const bar = page.locator('header.rb-topbar');
  await expect(bar).toBeVisible();
  await expect(bar.locator('a.rb-brand')).toHaveAccessibleName('Red Book');
  await expect(page.getByRole('link', { name: 'Decklists' }).first()).toBeVisible();
  // the top bar (Ketura, 2026-11-06; the design, 2026-11-09); sections not built yet are
  // greyed out with no link
  await expect(bar.locator('.rb-nav-primary > li > a')).toHaveText(
    ['Decklists', 'Libraries', 'Card Stats', 'Players', 'Events', 'Posts', /Links/]);
  await expect(bar.locator('a.is-soon')).toHaveCount(5);
  await expect(bar.locator('a.is-soon[href]')).toHaveCount(0);
  // logged out, the right side is just the account menu, the theme and the language
  await expect(bar.locator('.rb-nav-user > li:visible > a')).toHaveCount(1);
  await expect(bar.locator('.rb-nav-user > li:visible > a')).toHaveAttribute('title', 'Account');
  await expect(bar.locator('.rb-bar-tools .rb-lang')).toContainText('EN');
  const links = bar.locator('.dropdown-menu a', { hasText: /Gemp|Wiki|Forums|Discord/ });
  await expect(links).toHaveText(['Play on Gemp', 'LOTR-TCG Wiki', 'PC Forums', 'PC Discord']);
  await snap(page, 'home page');
  // The built CSS and JS bundles (gulp output) are referenced and load
  const css = await page.locator('link[rel=stylesheet][href*="/css/"]').count();
  const js = await page.locator('script[src*="/js/app"]').count();
  expect(css).toBeGreaterThan(0);
  expect(js).toBeGreaterThan(0);
});

test('the theme switch: light, the system theme, or dark, kept across pages', async ({ page }) => {
  await page.emulateMedia({ colorScheme: 'light' });
  await page.goto('/');
  const html = page.locator('html');
  const button = (mode: string) => page.locator(`.rb-bar-tools [data-theme-set=${mode}]`);
  // nothing chosen: the system's, which here is light
  await expect(button('auto')).toHaveAttribute('aria-pressed', 'true');
  await expect(html).not.toHaveClass(/pc-dark/);
  await button('dark').click();
  await expect(html).toHaveClass(/pc-dark/);
  await expect(button('dark')).toHaveAttribute('aria-pressed', 'true');
  // set before the page paints, on the next page too
  await page.goto('/decklists');
  await expect(html).toHaveClass(/pc-dark/);
  await button('auto').click();
  await expect(html).not.toHaveClass(/pc-dark/);
  await page.emulateMedia({ colorScheme: 'dark' });
  await expect(html).toHaveClass(/pc-dark/);
  await button('light').click();
  await expect(html).not.toHaveClass(/pc-dark/);
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

test('decklist listings load; the old list addresses are gone', async ({ page }) => {
  // one page of filters (Ketura, 2026-11-06): the tab and search addresses aren't kept
  for (const type of ['popular', 'recent', 'tournament', 'favorites', 'mine', 'find', 'search']) {
    const res = await page.goto(`/decklists/${type}`);
    expect(res?.status(), type).toBe(404);
  }
  const res = await page.goto('/decklists?played=1');
  expect(res?.status()).toBe(200);
  await expect(page.locator('#browse-form')).toBeVisible();
  // a filter with no field of its own shows as a chip that drops it (Ketura, 2026-11-06)
  await expect(page.locator('.rb-browse-also .rb-card-pick')).toHaveCount(1);
  // the pager: first and previous off on page 1, nine pages at most
  await page.goto('/decklists?sort=old');
  const pager = page.locator('.rb-results-foot .rb-pager');
  if (await pager.count()) {
    await expect(pager.locator('.rb-page-step.is-disabled')).toHaveCount(2);
    expect(await pager.locator('.rb-page:not(.rb-page-step)').count()).toBeLessThanOrEqual(9);
    await Promise.all([page.waitForURL(/page=2/), pager.getByRole('link', { name: 'Next Page' }).click()]);
    await expect(pager.locator('.rb-page.is-current')).toHaveText('2');
  }
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
