import fs from 'fs';
import path from 'path';
import { test, expect, runId, waitForCardData, snap } from '../helpers/test';
import { storageFor } from '../helpers/users';

// Private decks: build one in the deckbuilder with Gemp's checklist, convert it
// to PC errata, import and export Gemp deck strings, delete.
// Gemp is the stub in docker-compose.e2e.yml (tests/e2e/gemp-stub).

test.use({ storageState: storageFor('author') });

const fixture = (name: string) => fs.readFileSync(path.join(__dirname, '..', 'fixtures', name), 'utf8');

/**
 * Searches the card pool (list view), clicks a button on the card's row (+ adds a
 * copy, the ring button makes a Ring-bearer) and waits for the deck to show it
 */
async function pick(page, filter: string, card: string, control: '[data-add]' | '[data-rb]') {
  if (await page.locator('[data-pool-view=list][aria-pressed=false]').count()) {
    await page.click('[data-pool-view=list]');
  }
  await page.fill('#filter-text', filter);
  const row = page.locator('.rb-pool-row', { hasText: card }).first();
  await row.locator(control).first().click();
  await expect(page.locator('#deck')).toContainText(card.split(',')[0]);
}

test('build a deck: target format, Gemp checklist, convert to PC, save, delete', async ({ page }) => {
  const deckName = `E2E built ${runId}`;

  await page.goto('/deck/new');
  await page.selectOption('#target_format', 'pc_fotr_block');
  await page.fill('#name', deckName);
  await snap(page, 'new deck: format chosen');
  await page.locator('form[action$="/deck/build"] button[type=submit]').click();
  await expect(page).toHaveURL(/\/deck\/edit\//);
  await waitForCardData(page);

  // An empty deck: every problem listed at once
  const checklist = page.locator('#deck-validation');
  await expect(checklist).toContainText("Deck doesn't have a Ring-bearer");
  await expect(checklist).toContainText("Deck doesn't have a One Ring");

  // In a PC format the pool shows the errata, so Legolas is 51_50 from the start
  await page.selectOption('#deck-format', 'fotr_block');
  await pick(page, 'frodo', 'Frodo, Son of Drogo', '[data-rb]');
  await expect(checklist).not.toContainText("Ring-bearer", { timeout: 15_000 });
  await pick(page, 'ruling', 'The One Ring, The Ruling Ring', '[data-add]');
  await pick(page, 'bag', 'Bag End', '[data-add]');
  await pick(page, 'legolas', 'Legolas, Greenleaf', '[data-add]');
  await page.locator('#deck .rb-line[data-code="1_50"] .rb-n[data-qty="2"]').click();
  await expect(page.locator('#deck .rb-line[data-code="1_50"] .rb-n.active')).toHaveText('2');
  await expect(checklist).toContainText('below minimum number of cards: 2<60');
  await expect(page.locator('#save-status')).toContainText('Unsaved changes');
  await snap(page, 'deck in progress with the checklist');

  // Suggest a format only shows without one: Fellowship sites and set 1 cards
  await page.selectOption('#deck-format', '');
  await page.click('#btn-suggest-format');
  await expect(page.locator('#format-suggestions .rb-suggestion').first()).toContainText('Fellowship sites');
  await snap(page, 'format suggestions');

  // Convert to PC errata shows in a PC format with errata to convert: Legolas 1_50 becomes 51_50
  await page.selectOption('#deck-format', 'pc_fotr_block');
  await page.click('#btn-convert-pc');
  await expect(page.locator('#deck .rb-line[data-code="51_50"]')).toBeVisible();
  await snap(page, 'after convert to PC');

  // Save records a version and stays in the deckbuilder
  await page.click('#btn-save');
  await expect(page.locator('#save-status')).toContainText('Saved as version');
  await page.goto(await page.evaluate(() => (window as any).app.deckbuilder_page.view_url));
  await expect(page.locator('h1')).toContainText(deckName);
  await expect(page.locator('.deck-validation')).toContainText('below minimum number of cards');
  await snap(page, 'saved deck');

  await page.click('#btn-delete');
  await page.locator('#delete-deck-form [type=submit]').click();
  await expect(page).toHaveURL(/\/decks/);
  await expect(page.getByRole('link', { name: deckName })).toHaveCount(0);
});

test('import a Gemp deck string and export it back unchanged', async ({ page }) => {
  const deckName = `E2E imported ${runId}`;
  const [, gemp] = fixture('fellowship-gemp.txt').trim().split('\n');

  await page.goto('/deck/import');
  await page.selectOption('#target_format', 'fotr_block');
  await page.fill('#content', `${deckName}\n${gemp}`);
  await page.click('form[action$="/deck/fileimport"] button[type=submit]');
  await expect(page).toHaveURL(/\/deck\/edit\//);
  await waitForCardData(page);
  await expect(page.locator('#deck-validation')).toContainText('Deck is valid for Fellowship Block');
  await page.goto(await page.evaluate(() => (window as any).app.deckbuilder_page.view_url));
  await expect(page.locator('body')).toContainText('Draw deck: 60');
  await expect(page.locator('#btn-publish')).toBeVisible();
  await snap(page, 'imported deck');

  await waitForCardData(page);
  const exported = await page.evaluate(() => (window as any).app.deck.get_export('gemp'));
  const sorted = (s: string) => s.split('|').map((p) => p.split(',').sort().join(',')).join('|');
  expect(sorted(exported)).toBe(sorted(gemp));
});

test('deckbuilder clicks: pool cards add, counts fill, sites collapse, menus float', async ({ page }) => {
  const deckName = `E2E clicks ${runId}`;
  await page.goto('/deck/new');
  await page.selectOption('#target_format', 'fotr_block');
  await page.fill('#name', deckName);
  await page.locator('form[action$="/deck/build"] button[type=submit]').click();
  await expect(page).toHaveURL(/\/deck\/edit\//);
  await waitForCardData(page);

  // a click on a pool row adds a copy, like its + (Ketura, 2026-11-03), and opens no card window
  if (await page.locator('[data-pool-view=list][aria-pressed=false]').count()) {
    await page.click('[data-pool-view=list]');
  }
  await page.fill('#filter-text', 'legolas');
  const row = page.locator('.rb-pool-row', { hasText: 'Legolas, Greenleaf' }).first();
  await row.locator('.rb-name').click();
  await expect(page.locator('#deck .rb-line[data-code="1_50"] .rb-n.active')).toHaveText('1');
  await row.locator('.rb-name').click();
  await expect(page.locator('#cardModal')).not.toBeVisible();
  // every count up to the current one is filled
  const legolas = page.locator('#deck .rb-line[data-code="1_50"]');
  await expect(legolas.locator('.rb-n.active')).toHaveText('2');
  await expect(legolas.locator('.rb-n.is-filled')).toHaveCount(2);

  // ... and so does a click on a thumbnail
  await page.click('[data-pool-view=thumb]');
  await page.fill('#filter-text', 'gimli');
  const gimli = page.locator('.rb-pool-tile .rb-pool-img[data-code="1_13"]');
  await expect(gimli).toBeVisible();
  await gimli.click();
  await expect(page.locator('#deck')).toContainText('Gimli');
  await expect(page.locator('#cardModal')).not.toBeVisible();

  // the view buttons sit above the deck's scrolling area; the totals heading collapses the top part
  await page.locator('#deck-toolbar [data-view=thumb]').click();
  await expect(page.locator('#deck .rb-deck-head')).toContainText('Draw Deck (3), Adventure Deck (0)');
  await page.locator('#deck .rb-deck-head .rb-section-toggle').click();
  await expect(page.locator('#deck .rb-deck-top.is-collapsed')).toBeVisible();
  await expect(page.locator('#deck .rb-site-list.is-columns')).toBeVisible();
  await snap(page, 'collapsed deck top');
  await page.locator('#deck .rb-deck-head .rb-section-toggle').click();
  await expect(page.locator('#deck .rb-deck-top.is-collapsed')).toHaveCount(0);
  // Free Peoples collapses away entirely, and back
  await page.locator('#deck .rb-side-free_peoples .rb-section-toggle').click();
  await expect(page.locator('#deck .rb-side-free_peoples .rb-tile')).toHaveCount(0);
  await page.locator('#deck .rb-side-free_peoples .rb-section-toggle').click();
  await expect(page.locator('#deck .rb-side-free_peoples .rb-tile').first()).toBeVisible();
  await page.locator('#deck-toolbar [data-view=list]').click();

  // menus hang off the window, so the deck information pane can't cut them off
  await page.click('#btn-more');
  await expect(page.locator('#menu-more')).toBeVisible();
  expect(await page.locator('#menu-more').evaluate((el) => getComputedStyle(el).position)).toBe('fixed');
  await page.keyboard.press('Escape');

  await page.click('#btn-more');
  await page.locator('#menu-more [data-action=delete]').click();
  await page.locator('#delete-deck-form [type=submit]').click();
  await expect(page).toHaveURL(/\/decks/);
});
