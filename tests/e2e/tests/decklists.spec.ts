import fs from 'fs';
import path from 'path';
import { test, expect, runId, snap, waitForCardData } from '../helpers/test';
import { storageFor } from '../helpers/users';

// Publishing a deck as a decklist, and what other users can do with it:
// like, favourite, comment, find it in search; plus its public API entry.
// The tests build on each other, so they run in order.

test.describe.configure({ mode: 'serial' });
test.use({ storageState: storageFor('author') });

const decklistName = `E2E decklist ${runId}`;
let decklistUrl = '';
let decklistId = '';

test('publish a deck as a decklist, with a play record', async ({ page }) => {
  const deckName = `E2E to publish ${runId}`;
  const [, fixture] = fs.readFileSync(path.join(__dirname, '..', 'fixtures', 'fellowship-gemp.txt'), 'utf8').trim().split('\n');
  // one Axe Strike becomes Gimli, so the deck has a companion to start
  const gemp = fixture.replace('1_3,1_3,', '1_3,1_12,');

  await page.goto('/deck/import');
  await page.selectOption('#target_format', 'fotr_block');
  await page.fill('#content', `${deckName}\n${gemp}`);
  await page.click('form[action$="/deck/fileimport"] button[type=submit]');
  await expect(page).toHaveURL(/\/deck\/edit\//);
  await waitForCardData(page);

  // Gimli starts, with a starting note; both go with the deck when it's published
  await page.locator('#deck [data-start="1_12"]').click();
  await page.click('a[href="#tab-pane-infos"]');
  await page.fill('#starting-notes', 'Start [[Gimli, Dwarf of Erebor]] and bid low.');
  await page.click('#btn-save');
  await expect(page.locator('#save-status')).toContainText('Saved as version');

  // Publish is the deckbuilder's Visibility menu (Ketura, 2026-11-02)
  await page.click('#btn-visibility');
  await page.click('#menu-visibility [data-visibility=published]');
  await expect(page).toHaveURL(/\/deck\/publish\//);
  await page.fill('#name', decklistName);
  // [[card]] links resolve when the text is saved (Ketura, 2026-11-03)
  await page.fill('#descriptionMd', 'Published by the **smoke test**. Lead with [[Aragorn HTTWC]], errata [[3_38.1]], not [[Gandalf]] or [[Nobody At All]].');
  await page.fill('input[name="play_event[]"]', `E2E Regionals ${runId}`);
  await page.fill('input[name="play_placing[]"]', '1st');
  await snap(page, 'publish form');
  await page.click('form[action="/decklist/create"] button[type=submit]');

  await expect(page).toHaveURL(/\/decklist\/view\/(\d+)\//);
  await expect(page.locator('h1')).toContainText(decklistName);
  await expect(page.locator('body')).toContainText('Published by the smoke test.');
  await expect(page.locator('a.rb-cardlink[data-code="3_38"]')).toHaveText('Aragorn, Heir to the White City');
  await expect(page.locator('a.rb-cardlink[href$="/card/53_38#v1"]')).toHaveAttribute('data-image', /LOTR-EN03S038\.1/);
  await expect(page.locator('a.rb-cardlink.is-ambiguous')).toHaveText('Gandalf');
  await expect(page.locator('.rb-cardlink.is-missing')).toHaveText('[[Nobody At All]]');
  await expect(page.locator('.deck-validation')).toContainText('Legal in Fellowship Block');
  await expect(page.locator('.decklist-plays')).toContainText(`E2E Regionals ${runId}`);
  // the reader: the deckbuilder's deck, read-only, "4x Card" lines
  await expect(page.locator('#deck.rb-readonly')).toContainText('Ring-bearer');
  await expect(page.locator('#deck .rb-qty-text').first()).toHaveText(/^\dx$/);
  await expect(page.locator('#deck .rb-n')).toHaveCount(0);
  await expect(page.locator('#tab-pane-stats #stats-chart-twilight svg')).toBeVisible();
  // the starting fellowship: an S in the deck, and the starting notes above the description,
  // which come with the page rather than after the card data (Ketura, 2026-11-04)
  await expect(page.locator('#deck .rb-line[data-code="1_12"] .rb-start-mark')).toHaveCount(1);
  await expect(page.locator('#deck .rb-start-mark')).toHaveCount(1);
  await expect(page.locator('#reader-starting .rb-starting-card')).toHaveText('Gimli, Dwarf of Erebor');
  await expect(page.locator('#reader-starting a.rb-cardlink[data-code="1_12"]')).toBeVisible();
  // copying the deck gives "2x Card" lines, Gemp's import format
  const copied = await page.evaluate(() => {
    const deck = document.querySelector('#deck')!;
    const range = document.createRange();
    range.selectNodeContents(deck);
    getSelection()!.removeAllRanges();
    getSelection()!.addRange(range);
    const data = new DataTransfer();
    deck.dispatchEvent(new ClipboardEvent('copy', { clipboardData: data, bubbles: true, cancelable: true }));
    getSelection()!.removeAllRanges();
    return data.getData('text/plain');
  });
  expect(copied).toMatch(/^Ring-bearer: Frodo, Son of Drogo$/m);
  expect(copied).toMatch(/^1x Gimli, Dwarf of Erebor$/m);
  expect(copied).toMatch(/^2x Axe Strike$|^1x Axe Strike$/m);
  await snap(page, 'decklist published');
  decklistUrl = page.url();
  decklistId = decklistUrl.match(/\/decklist\/view\/(\d+)\//)![1];

  // Authors can't like their own decklist
  await expect(page.locator('#social-icon-like')).toHaveCount(0);
});

test('another user likes, favourites and comments on it', async ({ pageAs }) => {
  const page = await pageAs('voter');
  await page.goto(decklistUrl);

  const like = page.locator('#social-icon-like');
  await expect(like.locator('.num')).toHaveText('0');
  await like.click();
  await expect(like.locator('.num')).toHaveText('1');

  const favorite = page.locator('#social-icon-favorite');
  await expect(favorite.locator('.num')).toHaveText('0');
  await favorite.click();
  await expect(favorite.locator('.num')).toHaveText('1');

  const comment = `Nice list from run ${runId}`;
  // Type it (not fill) so the Markdown preview renders as it would for a
  // person; the preview re-renders and moves the button, so let it settle.
  await page.locator('#comment-form-text').pressSequentially(comment);
  await expect(page.locator('#comment-form-preview')).toContainText(comment);
  await Promise.all([
    page.waitForResponse((r) => r.url().includes('/user/comment') && r.request().method() === 'POST'),
    page.locator('form:has(#comment-form-text) button[type=submit]').click(),
  ]);
  await expect(page.locator('.alert-success[role=alert]')).toContainText('comment has been posted');
  await snap(page, 'liked, favourited, comment posted');

  // The counts are stored, not just updated in the page
  await page.reload();
  await expect(page.locator('#social-icon-like .num')).toHaveText('1');
  await expect(page.locator('#social-icon-favorite .num')).toHaveText('1');
  await expect(page.locator('#social-icon-comment .num')).toHaveText('1');
  await expect(page.locator('.comment-text').filter({ hasText: comment })).toBeVisible();
  await snap(page, 'after reload: counts and comment kept');
});

test('the decklist shows up in search and in the favourites list', async ({ page, pageAs }) => {
  await page.goto(`/decklists/find?name=${encodeURIComponent(decklistName)}&sort=likes`);
  await expect(page.getByRole('link', { name: decklistName })).toBeVisible();
  await snap(page, 'search by name');

  const voter = await pageAs('voter');
  await voter.goto('/decklists/favorites');
  await expect(voter.getByRole('link', { name: decklistName })).toBeVisible();
  await snap(voter, "voter's favourites");
});

test('the public API serves the decklist', async ({ request }) => {
  const res = await request.get(`/api/public/decklist/${decklistId}`);
  expect(res.status()).toBe(200);
  const decklist = await res.json();
  expect(decklist.name).toBe(decklistName);
  const total = Object.values(decklist.slots as Record<string, number>).reduce((a, b) => a + b, 0);
  expect(total).toBe(71); // Ring-bearer, Ring, 9 sites, 60 draw
  expect(decklist.slots['rb:1_290']).toBe(1);
  expect(decklist.target_format).toBe('fotr_block');
  expect(decklist.completeness).toBe('complete');
});
