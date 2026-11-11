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
    ['Decklists', 'Libraries', 'Card Stats', 'Players', 'Events', 'Posts', 'Formats', /Links/, 'Feedback']);
  // the bug report form, boxed, right of Links (Ketura, 2026-11-09)
  await expect(bar.locator('.rb-nav-feedback > a')).toHaveCSS('border-top-style', 'solid');
  // the footer: About and API (Ketura, 2026-11-09), Formats beside API (2026-11-11)
  await expect(page.locator('.rb-footer-links a')).toHaveText(['About', 'API', 'Formats']);
  await expect(bar.locator('a.is-soon')).toHaveCount(5);
  await expect(bar.locator('a.is-soon[href]')).toHaveCount(0);
  // logged out, the right side is a Login or Register button (Ketura, 2026-11-11), the theme
  // and the language
  await expect(bar.locator('.rb-nav-user > li:visible > a')).toHaveCount(1);
  await expect(bar.locator('.rb-nav-user > li:visible > a')).toHaveText('Login or Register');
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

test('the front page: the cover, the decks feed and its tabs, the feeds still to come', async ({ page }) => {
  await page.goto('/');
  await expect(page.locator('.rb-cover h1')).toHaveAccessibleName('Red Book');
  await expect(page.locator('header.rb-topbar')).toHaveClass(/rb-hinge/);
  const newFeed = page.locator('#feed-new'), popular = page.locator('#feed-popular');
  await expect(newFeed).toBeVisible();
  await expect(popular).toBeHidden();
  await page.locator('.rb-tabs button[aria-controls=feed-popular]').click();
  await expect(popular).toBeVisible();
  await expect(newFeed).toBeHidden();
  await expect(page.locator('.rb-tabs button[aria-controls=feed-popular]')).toHaveAttribute('aria-selected', 'true');
  await expect(page.locator('.rb-feed-soon h2')).toHaveText(['Posts', 'Events', 'This Week in Years Past']);
  // Deck of the Day, when there's one (Ketura, 2026-11-09): its heading and the date on a plate
  // of their own, over the deck as the Decks feed shows it
  if(await page.locator('.rb-dotd').count()) {
    await expect(page.locator('.rb-dotd-label time')).toHaveText(/^\d{4}-\d{2}-\d{2}$/);
    await expect(page.locator('.rb-dotd .rb-bookplate .rb-deckrow .rb-deck-name')).toBeVisible();
  }
});

test('the theme: kept across pages, and Auto follows the system', async ({ page }) => {
  await page.emulateMedia({ colorScheme: 'light' });
  await page.goto('/');
  const html = page.locator('html');
  const button = page.locator('.rb-bar-tools [data-theme-cycle]');
  // nothing chosen: the system's, which here is light; one click, dark
  await expect(html).not.toHaveClass(/pc-dark/);
  await button.click();
  await expect(html).toHaveClass(/pc-dark/);
  // set before the page paints, on the next page too
  await page.goto('/decklists');
  await expect(html).toHaveClass(/pc-dark/);
  // Light, then Auto, which follows the system
  await button.click();
  await button.click();
  await expect(button).toHaveAttribute('data-mode', 'auto');
  await page.emulateMedia({ colorScheme: 'dark' });
  await expect(html).toHaveClass(/pc-dark/);
  await page.emulateMedia({ colorScheme: 'light' });
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

test('the Formats page: Gemp\'s format definitions, and a Timeline tab', async ({ page, request }) => {
  // (Ketura, 2026-11-11) every hall format, as Gemp's Help > Format Definitions
  await page.goto('/formats');
  await expect(page.locator('.rb-tab.is-active')).toHaveText('Formats');
  await expect(page.locator('#format-fotr_block .rb-fd-name')).toHaveText('Fellowship Block');
  await expect(page.locator('.rb-fd-nav li')).toHaveCount(await page.locator('.rb-fd-format').count());
  const standard = page.locator('#format-standard');
  await expect(standard.locator('.rb-fd-list.is-banned .rb-fd-list-title')).toHaveText('X-list');
  // a listed card is Red Book's card, with its page
  await expect(standard.locator('.rb-fd-list.is-banned a.rb-fd-chip').first()).toHaveAttribute('href', /\/card\/\d+_\d+$/);
  // the box finds formats by name, and every format listing a card
  await page.fill('.rb-fd-filter', 'standard');
  await expect(page.locator('.rb-fd-status')).toContainText('formats');
  await expect(page.locator('#format-fotr_block')).toBeHidden();
  await page.fill('.rb-fd-filter', '');
  await expect(page.locator('#format-fotr_block')).toBeVisible();
  // Card images: the lists as pictures
  await page.locator('.rb-fd-images').check();
  await expect(standard.locator('.rb-fd-thumbs').first()).toBeVisible();
  await page.locator('.rb-fd-images').uncheck();
  // the list goes to a format, and puts it in the address bar
  await page.locator('.rb-fd-nav a[data-anchor="format-standard"]').click();
  await expect(page.locator('.rb-fd-nav li.is-active')).toHaveAttribute('data-anchor', 'format-standard');
  await expect(page).toHaveURL(/#format-standard$/);
  await expect(standard).toBeInViewport();
  // a format's own address goes to its place on the page, lit up
  await page.goto('/formats/movie');
  await expect(page).toHaveURL(/\/formats#format-movie$/);
  await expect(page.locator('#format-movie')).toHaveClass(/is-highlight/);
  await expect(page.locator('#format-movie')).toBeInViewport();
  await snap(page, 'formats');
  // the Timeline tab, still to come
  await page.locator('.rb-tab', { hasText: 'Timeline' }).click();
  await expect(page).toHaveURL(/\/formats\/timeline$/);
  await expect(page.locator('.rb-tab.is-active')).toHaveText('Timeline');
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
  // the list pages' title (Ketura, 2026-11-09): My Decks' size and line, without a tab around it
  await expect(page.locator('.rb-browse-title .rb-tabs .rb-tab-solo')).toHaveText('Decklists');
  await expect(page.locator('.rb-browse-title .rb-tabs')).toHaveCSS('border-bottom-style', 'solid');
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

test('anonymous users are sent to the login page for their drafts', async ({ page }) => {
  await page.goto('/drafts/decks');
  await expect(page).toHaveURL(/\/login/);
  await page.goto('/drafts/libraries');
  await expect(page).toHaveURL(/\/login/);
});

// the theme: one button, each click the next of Auto, Dark, Light (Ketura, 2026-11-11)
test('the theme button cycles Auto, Dark, Light', async ({ page }) => {
  await page.emulateMedia({ colorScheme: 'light' });
  await page.goto('/decklists');
  const button = page.locator('.rb-bar-tools [data-theme-cycle]');
  await expect(button).toHaveAttribute('data-mode', 'auto');
  await expect(button).toHaveAttribute('title', 'Theme: Auto (match system). Click for Dark.');
  await button.click();
  await expect(button).toHaveAttribute('data-mode', 'dark');
  await expect(page.locator('html')).toHaveClass(/pc-dark/);
  await button.click();
  await expect(button).toHaveAttribute('data-mode', 'light');
  await expect(page.locator('html')).not.toHaveClass(/pc-dark/);
  await page.reload();
  await expect(button).toHaveAttribute('data-mode', 'light');
  await button.click();
  await expect(button).toHaveAttribute('data-mode', 'auto');
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
