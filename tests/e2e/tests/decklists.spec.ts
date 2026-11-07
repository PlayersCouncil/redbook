import fs from 'fs';
import path from 'path';
import { test, expect, runId, snap, waitForCardData } from '../helpers/test';
import { storageFor } from '../helpers/users';
import { appConsole } from '../helpers/console';

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
  await page.fill('#tag-entry', 'dwarves');
  await page.keyboard.press('Enter');
  await page.click('#btn-save');
  await expect(page.locator('#save-status')).toContainText('Saved as version');

  // Publish is the deckbuilder's Visibility menu (Ketura, 2026-11-02)
  await page.click('#btn-visibility');
  await page.click('#menu-visibility [data-visibility=published]');
  await expect(page).toHaveURL(/\/deck\/publish\//);
  // one form for publishing and editing (Ketura, 2026-11-06), laid out as the deckbuilder
  // (2026-11-07): the deck on the left with its format, the form as a tab on the right
  await expect(page.locator('.rb-publish-title')).toHaveText('Publish Deck');
  await expect(page.locator('.rb-publish-format')).toHaveText('Fellowship Block');
  await expect(page.locator('#deck.rb-readonly')).toContainText('Gimli');
  await expect(page.locator('#release-note')).toContainText('three days');
  // the deck's notes came with it, in the Notes tab's own editor
  await expect(page.locator('#starting-list')).toContainText('Gimli, Dwarf of Erebor');
  await expect(page.locator('#starting-notes')).toHaveValue('Start [[Gimli, Dwarf of Erebor]] and bid low.');
  await expect(page.locator('#tag-input .rb-tag-chip')).toContainText('dwarves');
  // the starting fellowship can be changed here too, picking from the deck on the left
  await page.locator('#starting-list [data-unstart="1_12"]').click();
  await expect(page.locator('#starting-list')).toHaveText('No starting companions');
  await page.click('#start-pick');
  await page.locator('#deck .rb-line[data-code="1_12"]').click();
  await expect(page.locator('#starting-list .rb-starting-pill')).toHaveText(/Gimli, Dwarf of Erebor/);
  await page.fill('#name', decklistName);
  // [[card]] links resolve when the text is saved (Ketura, 2026-11-03)
  await page.fill('#description', 'Published by the **smoke test**. Lead with [[Aragorn HTTWC]], errata [[3_38.1]], not [[Gandalf]] or [[Nobody At All]].');
  // a note on a card, picked from the deck on the left
  await page.click('#annot-pick');
  await page.locator('#deck .rb-line[data-code="1_12"]').click();
  await page.fill('#annot-list textarea[data-annotation="1_12"]', 'The only companion.');
  // the thumbnail: the cultures until a card is chosen, then that card (Ketura, 2026-11-05)
  await expect(page.locator('#thumb-cultures .rb-thumb-cultures')).toBeVisible();
  await expect(page.locator('#thumbnail optgroup')).toHaveCount(3);
  await page.locator('#thumbnail').selectOption({ label: 'Gimli, Dwarf of Erebor' });
  await expect(page.locator('#thumb-card img')).toHaveAttribute('src', /.+/);
  await expect(page.locator('#thumb-cultures')).toBeHidden();
  await snap(page, 'publish form');
  await page.click('.rb-form-actions button[form=decklist-form]:has-text("Publish")');

  await expect(page).toHaveURL(/\/decklist\/view\/(\d+)\//);
  await expect(page.locator('h1')).toContainText(decklistName);
  await expect(page.locator('body')).toContainText('Published by the smoke test.');
  await expect(page.locator('a.rb-cardlink[data-code="3_38"]')).toHaveText('Aragorn, Heir to the White City');
  await expect(page.locator('a.rb-cardlink[href$="/card/53_38#v1"]')).toHaveAttribute('data-image', /LOTR-EN03S038\.1/);
  await expect(page.locator('a.rb-cardlink.is-ambiguous')).toHaveText('Gandalf');
  await expect(page.locator('.rb-cardlink.is-missing')).toHaveText('[[Nobody At All]]');
  await expect(page.locator('.deck-validation')).toContainText('Legal in Fellowship Block');
  await expect(page.locator('#reader-notes')).toContainText('The only companion.');
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
  // the deck's tags beside its version
  await expect(page.locator('.rb-reader-tags')).toContainText('dwarves');
  // Copy List: the list on the clipboard, format by name, no codes, no Gemp string
  await page.context().grantPermissions(['clipboard-read', 'clipboard-write']);
  await page.locator('.rb-reader-buttons-wide [data-copy-list]').click();
  await expect(page.locator('.rb-toast')).toHaveText('Copied to clipboard!');
  const list = await page.evaluate(() => navigator.clipboard.readText());
  expect(list).toContain('Format: Fellowship Block');
  expect(list).toMatch(/^1x Gimli, Dwarf of Erebor$/m);
  expect(list).not.toMatch(/\(\d+_\d+\)|Gemp:/);
  // Download Text File: named for the account, then the deck
  const download = await page.request.get(await page.locator('.rb-reader-buttons-wide a[href*="/decklist/export/text/"]').getAttribute('href') as string);
  expect(download.headers()['content-disposition']).toMatch(/filename="?e2e_author_[a-z0-9]+_e2e-decklist-/);
  // and holding the same list as Copy List (Ketura, 2026-11-04)
  expect((await download.text()).replace(/\r\n/g, '\n').trim()).toBe(list.trim());
  await snap(page, 'decklist published');
  decklistUrl = page.url();
  decklistId = decklistUrl.match(/\/decklist\/view\/(\d+)\//)![1];

  // Authors can't like their own decklist, nor set it on fire
  await expect(page.locator('#social-like')).toBeDisabled();
  await expect(page.locator('#social-fire')).toBeDisabled();
  await expect(page.locator('#social-fire')).toHaveClass(/is-tucked/);
});

test('the author edits the decklist from its page; nobody else gets Edit', async ({ page, pageAs }) => {
  await page.goto(decklistUrl);
  const edit = page.locator('.rb-reader-buttons .decklist-edit');
  await expect(edit).toBeVisible();
  await Promise.all([page.waitForURL(new RegExp(`/decklist/edit/${decklistId}$`)), edit.click()]);
  await expect(page.locator('#name')).toHaveValue(decklistName);
  // the same form, editing, and Cancel goes back to the decklist; within the grace period
  // (Ketura, 2026-11-07) the cards and release date change only by undoing publishing
  await expect(page.locator('.rb-publish-title')).toHaveText('Edit Decklist');
  await expect(page.locator('#annot-list textarea[data-annotation="1_12"]')).toHaveValue('The only companion.');
  await expect(page.locator('#decklist-state')).toHaveAttribute('data-state', 'grace');
  await expect(page.locator('#effective_date')).toBeDisabled();
  await expect(page.locator('#version-line')).toContainText('Version 1');
  await expect(page.locator('.rb-form-actions a')).toHaveAttribute('href', new URL(decklistUrl).pathname);
  await page.fill('#description', 'Published by the **smoke test**, then edited. Lead with [[Aragorn HTTWC]], errata [[3_38.1]], not [[Gandalf]] or [[Nobody At All]].');
  await page.click('.rb-form-actions button[form=decklist-form]:has-text("Save")');
  await expect(page).toHaveURL(new RegExp(`/decklist/view/${decklistId}/`));
  await expect(page.locator('body')).toContainText('Published by the smoke test, then edited.');
  await snap(page, 'decklist edited');
  // what the description said before is kept
  expect(appConsole(['dbal:run-sql', `SELECT description_md FROM published_deck_revision WHERE published_deck_id = ${decklistId}`]))
    .toContain('Published by the **smoke test**. Lead with');

  const voter = await pageAs('voter');
  await voter.goto(decklistUrl);
  await expect(voter.locator('#social-like')).toBeVisible();
  await expect(voter.locator('.decklist-edit')).toHaveCount(0);
  expect((await voter.goto(`/decklist/edit/${decklistId}`))?.status()).toBe(403);
});

test('another user likes, favourites and comments on it', async ({ pageAs }) => {
  const page = await pageAs('voter');
  await page.goto(decklistUrl);

  // buttons that show what this user has done (Ketura, 2026-11-04)
  const like = page.locator('#social-like');
  const fire = page.locator('#social-fire');
  await expect(like.locator('.num')).toHaveText('0');
  await expect(like).toHaveAttribute('aria-pressed', 'false');
  // the fire waits behind Like, its place kept so nothing moves when it comes out
  await expect(fire).toHaveClass(/is-tucked/);
  const likeBox = await like.boundingBox();
  await like.click();
  await expect(like.locator('.num')).toHaveText('1');
  await expect(like).toHaveAttribute('aria-pressed', 'true');
  // liked: the fire slides out to the left, ready; Like hasn't moved
  await expect(fire).not.toHaveClass(/is-tucked/);
  await expect(fire).toBeEnabled();
  expect(await like.boundingBox()).toEqual(likeBox);
  await fire.click();
  await expect(fire.locator('.num')).toHaveText('1');
  await expect(fire).toHaveAttribute('aria-pressed', 'true');

  const favorite = page.locator('#social-favorite');
  await expect(favorite.locator('.num')).toHaveText('0');
  await favorite.click();
  await expect(favorite.locator('.num')).toHaveText('1');
  await expect(favorite).toHaveClass(/is-on/);

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
  await expect(page.locator('#social-like .num')).toHaveText('1');
  await expect(page.locator('#social-like')).toHaveClass(/is-on/);
  await expect(page.locator('#social-favorite .num')).toHaveText('1');
  await expect(page.locator('#social-favorite')).toHaveClass(/is-on/);
  await expect(page.locator('#social-comment .num')).toHaveText('1');
  await expect(page.locator('#social-comment')).toHaveClass(/is-on/);
  await expect(page.locator('#social-fire')).toHaveClass(/is-on/);
  await expect(page.locator('#social-fire .num')).toHaveText('1');
  // unliking puts the fire out with it, and the button goes back behind Like
  await page.locator('#social-like').click();
  await expect(page.locator('#social-like .num')).toHaveText('0');
  await expect(page.locator('#social-fire .num')).toHaveText('0');
  await expect(page.locator('#social-fire')).toHaveClass(/is-tucked/);
  await page.locator('#social-like').click();
  await expect(page.locator('#social-like .num')).toHaveText('1');
  await expect(page.locator('#social-fire')).toBeEnabled();
  await expect(page.locator('#social-fire')).not.toHaveClass(/is-on/);
  await expect(page.locator('.comment-text').filter({ hasText: comment })).toBeVisible();
  await snap(page, 'after reload: counts and comment kept');
});

test('the decklist shows up in search and in the favourites list', async ({ page, pageAs }) => {
  await page.goto(`/decklists?name=${encodeURIComponent(decklistName)}&sort=rated`);
  const row = page.locator('.rb-decks tbody tr').filter({ hasText: decklistName });
  await expect(row).toHaveCount(1);
  // format first, then the size per side
  await expect(row.locator('.rb-col-format')).toHaveText('Fellowship Block');
  await expect(row.locator('.rb-size')).toHaveText('30/30');
  // the columns in Ketura's order, and the upload date beside the author as written
  await expect(page.locator('.rb-decks thead th')).toHaveText(['', 'Score', 'Size', 'Deck', 'Format', 'Cultures', 'Tags']);
  await expect(row.locator('.rb-deck-by time')).toHaveText(/^[A-Z][a-z]{2} \d{1,2}, \d{4}$/);
  await expect(row.locator('.rb-row-tags')).toContainText('dwarves');
  await expect(row.locator('.rb-thumb-card img')).toHaveAttribute('alt', 'Gimli, Dwarf of Erebor');
  await expect(row).toHaveClass(/rb-kind-whole/);
  await snap(page, 'search by name');

  const voter = await pageAs('voter');
  await voter.goto('/decklists?favorites=1');
  await expect(voter.getByRole('link', { name: decklistName })).toBeVisible();
  await expect(voter.locator('.rb-browse-also')).toContainText('My Favourites');
  await snap(voter, "voter's favourites");
});

test('the decklists page filters by card, kind and culture', async ({ page }) => {
  await page.goto(`/decklists?name=${encodeURIComponent(decklistName)}`);
  await page.waitForFunction(() => (window as any).app?.data?.cards?.find().length > 100);
  // a card picked from the suggestions is searched for straight away
  await page.locator('#browse-card').fill('Gimli, Dwarf of Er');
  await Promise.all([page.waitForURL(/cards/), page.locator('.tt-suggestion').first().click()]);
  await expect(page.locator('.rb-card-pick')).toContainText('Gimli, Dwarf of Erebor');
  await expect(page.getByRole('link', { name: decklistName })).toBeVisible();
  // fragments only: a whole deck drops out, the results swapped in without loading the page
  await page.evaluate(() => { (window as any).samePage = true; });
  await Promise.all([page.waitForURL(/kind/), page.locator('.rb-toggle-whole span').click()]);
  await expect(page.locator('.rb-results-none')).toBeVisible();
  expect(await page.evaluate(() => (window as any).samePage)).toBe(true);
  // Back restores the list and the filters
  await page.goBack();
  await expect(page.getByRole('link', { name: decklistName })).toBeVisible();
  await expect(page.locator('.rb-toggle-whole input')).toBeChecked();
  await Promise.all([page.waitForURL(/kind/), page.locator('.rb-toggle-whole span').click()]);
  await expect(page.locator('.rb-results-none')).toBeVisible();
  // the last kind can't be turned off
  await Promise.all([page.waitForURL(/kind/), page.locator('.rb-toggle-free_peoples span').click()]);
  await Promise.all([page.waitForURL(/kind/), page.locator('.rb-toggle-shadow span').click()]);
  await expect(page.locator('.rb-toggle input:checked[name="kind[]"]')).toHaveCount(2);
  // a culture the deck doesn't have
  await page.goto(`/decklists?name=${encodeURIComponent(decklistName)}&culture[]=dwarven`);
  await expect(page.getByRole('link', { name: decklistName })).toBeVisible();
  await page.goto(`/decklists?name=${encodeURIComponent(decklistName)}&culture[]=raider`);
  await expect(page.locator('.rb-results-none')).toBeVisible();
  // a tag on a row searches for it, without leaving the page (Ketura, 2026-11-06)
  await page.goto(`/decklists?name=${encodeURIComponent(decklistName)}`);
  await Promise.all([page.waitForURL(/tags/), page.locator('.rb-tag-link', { hasText: 'dwarves' }).first().click()]);
  await expect(page.locator('#browse-tags .rb-tag-pick')).toHaveText(/dwarves/);
  await expect(page.getByRole('link', { name: decklistName })).toBeVisible();
  await page.locator('#browse-tag').fill('no-such-tag');
  await Promise.all([page.waitForURL(/no-such-tag/), page.locator('#browse-tag').press('Enter')]);
  await expect(page.locator('.rb-results-none')).toBeVisible();
  // the page size: not a filter, so nothing for Clear All
  await page.goto('/decklists?limit=10');
  expect(await page.locator('.rb-decks tbody tr').count()).toBeLessThanOrEqual(10);
  await expect(page.locator('select[name=limit]')).toHaveValue('10');
  await expect(page.locator('.rb-browse-clear')).toHaveCount(0);
  // submitted dates from the calendar, applied as they're picked (Ketura, 2026-11-06)
  await page.goto(`/decklists?name=${encodeURIComponent(decklistName)}`);
  await Promise.all([page.waitForURL(/to=2003-12-31/), page.locator('input[name=to]').fill('2003-12-31')]);
  await expect(page.locator('.rb-results-none')).toBeVisible();
  // Clear All keeps nothing but the sort
  await page.goto(`/decklists?name=x&sort=rated`);
  await page.getByRole('link', { name: 'Clear All' }).click();
  await expect(page).toHaveURL(/\/decklists\?sort=rated$/);
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
