import fs from 'fs';
import path from 'path';
import { test, expect, runId, waitForCardData, snap } from '../helpers/test';
import { storageFor } from '../helpers/users';

// Private decks: build one in the deckbuilder with Gemp's checklist, convert it
// to PC errata, import and export Gemp deck strings, delete.
// Gemp is the stub in docker-compose.e2e.yml (tests/e2e/gemp-stub).

test.use({ storageState: storageFor('author') });

const fixture = (name: string) => fs.readFileSync(path.join(__dirname, '..', 'fixtures', name), 'utf8');

async function pick(page, filter: string, control: string) {
  await page.fill('#filter-text', filter);
  await expect(page.locator('#collection-table tr').first()).toBeVisible();
  await page.locator(`#collection-table tr:first-child ${control}`).first().click();
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

  await pick(page, '"Frodo, Son of Drogo"', '.btn-ring-bearer');
  await expect(checklist).not.toContainText("Ring-bearer", { timeout: 15_000 });
  await pick(page, 't:the_one_ring "Ruling Ring"', '.btn-toggle-card');
  await pick(page, '"Bag End"', '.btn-toggle-card');
  await pick(page, '"Legolas, Greenleaf" i:card', 'label:has-text("2")');
  await expect(page.locator('#deck')).toContainText('2x');
  await expect(page.locator('#deck')).toContainText('Legolas, Greenleaf');
  await expect(checklist).toContainText('below minimum number of cards: 2<60');
  await snap(page, 'deck in progress with the checklist');

  // Convert to PC errata: Legolas 1_50 becomes 51_50
  await page.click('#btn-convert-pc');
  await expect(page.locator('#deck .deck-card[data-key="51_50"]')).toBeVisible();
  await snap(page, 'after convert to PC');

  await page.locator('#save_form button[type=submit].btn-success').click();
  await expect(page).toHaveURL(/\/deck\/view\//);
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
  await expect(page.locator('#deck-validation')).toContainText('Legal in Fellowship Block');
  await page.locator('#save_form button[type=submit].btn-success').click();
  await expect(page).toHaveURL(/\/deck\/view\//);
  await expect(page.locator('body')).toContainText('Draw deck: 60');
  await expect(page.locator('#btn-publish')).toBeVisible();
  await snap(page, 'imported deck');

  await waitForCardData(page);
  const exported = await page.evaluate(() => (window as any).app.deck.get_export('gemp'));
  const sorted = (s: string) => s.split('|').map((p) => p.split(',').sort().join(',')).join('|');
  expect(sorted(exported)).toBe(sorted(gemp));
});
