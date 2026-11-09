import fs from 'fs';
import path from 'path';
import { Page } from '@playwright/test';
import { test, expect, runId, waitForCardData } from '../helpers/test';
import { storageFor, loadUsers } from '../helpers/users';
import { appConsole } from '../helpers/console';

// Libraries (Ketura, 2026-11-08): collections of published decklists with the curator's
// notes on each. Made on My Libraries, filled from the editor's Build tab or from Add to
// Library on any list of decks, made public, and read one deck at a time.
// The tests build on each other, so they run in order.

test.describe.configure({ mode: 'serial' });
test.use({ storageState: storageFor('author') });

const name = `E2E library ${runId}`;
let editUrl = '';
let publicUrl = '';

test('a new library from My Libraries, filled from the Build tab', async ({ page }) => {
  await page.goto('/my/libraries');
  await expect(page.locator('h1')).toHaveText('My Libraries');
  await page.click('#btn-new-library');
  await expect(page).toHaveURL(/\/library\/new$/);
  await page.fill('#name', name);
  await page.fill('#description', 'Decks for **testing**.');
  await page.click('#new-library-submit');
  await expect(page).toHaveURL(/\/my\/library\/edit\//);
  editUrl = page.url();
  await waitForCardData(page);
  await expect(page.locator('#library-empty')).toBeVisible();
  await expect(page.locator('#btn-save')).toBeDisabled();

  // the decklists search, each with Add; added ones say so
  const results = page.locator('#browse-results tr[data-decklist]');
  await expect(results.first()).toBeVisible();
  const first = await results.nth(0).locator('.rb-deck-name').textContent();
  const second = await results.nth(1).locator('.rb-deck-name').textContent();
  await results.nth(0).locator('[data-add]').click();
  await results.nth(1).locator('[data-add]').click();
  await expect(results.nth(0).locator('.rb-library-added')).toBeVisible();
  const entries = page.locator('#library-entries .rb-lib-entry');
  await expect(entries).toHaveCount(2);
  await expect(entries.nth(0).locator('.rb-deck-name')).toHaveText(first!);
  await expect(page.locator('#save-status')).toHaveText('Unsaved changes');

  // the commentary beside each deck; the order by the arrows
  await entries.nth(0).locator('textarea[data-annotation]').fill('The first deck. [[Gimli, Dwarf of Erebor]] starts.');
  await entries.nth(0).locator('[data-move="1"]').click();
  await expect(entries.nth(0).locator('.rb-deck-name')).toHaveText(second!);
  await expect(entries.nth(1).locator('.rb-lib-num')).toHaveText('2');

  // the Notes tab: a tag and the general notes
  await page.click('a[href="#tab-pane-infos"]');
  await page.fill('#tag-entry', `e2etag${runId}`);
  await page.keyboard.press('Enter');
  await expect(page.locator('#tag-input .rb-tag-chip')).toContainText(`e2etag${runId}`);
  await page.fill('#library-notes-text', 'Notes on the library as a whole.');
  // its own thumbnail (Ketura, 2026-11-08/09): any card found by name, framed by hand with the
  // crop editor the decklist form shares...
  await page.click('[data-thumb-mode="card"]');
  await page.fill('#thumb-search', 'Gandalf, The Grey Wizard');
  await page.locator('.tt-suggestion').first().click();
  await expect(page.locator('#library-thumb-preview .rb-thumb-card img')).toBeVisible();
  await page.locator('#thumb-edit').click();
  await expect(page.locator('#thumb-crop')).toBeVisible();
  // framed once the card has loaded
  await expect(page.locator('#thumb-crop .rb-crop-frame')).toBeVisible();
  await page.locator('#thumb-crop .rb-crop-frame').focus();
  await page.keyboard.press('-');
  await page.keyboard.press('ArrowRight');
  await page.click('#thumb-crop .rb-crop-confirm');
  await expect(page.locator('#thumb-crop')).toBeHidden();
  await expect(page.locator('#library-thumb-crop')).toHaveValue(/"w":/);
  await expect(page.locator('#library-thumb-preview .rb-thumb-card img')).toHaveAttribute('style', /width/);
  // ...or a banner of one or two cultures, in any combination: a third replaces the first
  await page.click('[data-thumb-mode="cultures"]');
  for (const code of ['shire', 'moria', 'sauron']) {
    await page.click(`.rb-lib-culture[data-culture="${code}"]`);
  }
  await expect(page.locator('.rb-lib-culture[aria-pressed="true"]')).toHaveCount(2);
  await expect(page.locator('.rb-lib-culture[data-culture="moria"] .rb-lib-culture-order')).toHaveText('1');
  await expect(page.locator('#library-thumb-preview .rb-thumb-part')).toHaveCount(2);
  await expect(page.locator('#library-thumb-preview .rb-thumb-fp')).toHaveClass(/rb-thumb-c-moria/);
  await page.click('#thumb-swap');
  await expect(page.locator('#library-thumb-preview .rb-thumb-fp')).toHaveClass(/rb-thumb-c-sauron/);

  await page.click('#btn-save');
  await expect(page.locator('#save-status')).toHaveText('Saved');
  await expect(page.locator('#btn-save')).toBeDisabled();

  // as saved
  await page.reload();
  await waitForCardData(page);
  await expect(entries).toHaveCount(2);
  await expect(entries.nth(0).locator('.rb-deck-name')).toHaveText(second!);
  await expect(entries.nth(1).locator('textarea[data-annotation]')).toHaveValue('The first deck. [[Gimli, Dwarf of Erebor]] starts.');
  await expect(page.locator('#library-tags')).toHaveValue(`e2etag${runId}`);
  await expect(page.locator('[data-thumb-mode="cultures"]')).toHaveAttribute('aria-checked', 'true');
  await expect(page.locator('#library-thumb-preview .rb-thumb-shadow')).toHaveClass(/rb-thumb-c-moria/);
});

test('a deck comes out with X then the bin; Keep takes it back', async ({ page }) => {
  await page.goto(editUrl);
  await waitForCardData(page);
  const entries = page.locator('#library-entries .rb-lib-entry');
  await entries.nth(0).locator('.rb-x').click();
  await expect(entries.nth(0)).toHaveClass(/is-pending/);
  await entries.nth(0).locator('.rb-lib-keep').click();
  await expect(entries.nth(0)).not.toHaveClass(/is-pending/);
  await expect(page.locator('#btn-save')).toBeDisabled();
  await entries.nth(0).locator('.rb-x').click();
  await entries.nth(0).locator('.rb-trash').click();
  await expect(entries).toHaveCount(1);
  await page.click('#btn-save');
  await expect(page.locator('#save-status')).toHaveText('Saved');
});

test('private until made public; then listed, and read one deck at a time', async ({ page, pageAs }) => {
  // someone else can't see it, or change it
  const voter = await pageAs('voter');
  const uuid = editUrl.match(/edit\/([0-9a-f-]{36})/)![1];
  expect((await voter.goto(`/my/library/view/${uuid}`))?.status()).toBe(403);
  expect((await voter.goto(editUrl))?.status()).toBe(403);

  await page.goto(editUrl);
  await waitForCardData(page);
  await expect(page.locator('#btn-visibility')).toContainText('Private');
  // Public is a setting among the others (Ketura, 2026-11-08); the menu floats over the pane
  await page.click('#btn-visibility');
  await expect(page.locator('#menu-visibility')).toHaveCSS('position', 'fixed');
  await page.click('#menu-visibility [data-visibility="public"]');
  await expect(page.locator('#btn-visibility')).toContainText('Public');
  publicUrl = await page.locator('#save-status a').getAttribute('href') ?? '';
  expect(publicUrl).toMatch(/\/library\/view\/\d+\//);

  // User Libraries lists it, found by its tag; Libraries, the PC's official ones, doesn't
  // (Ketura, 2026-11-09)
  await voter.goto(`/libraries?tags[]=e2etag${runId}`);
  await expect(voter.locator('.rb-library-tabs .rb-tab.is-active')).toHaveText('Libraries');
  await expect(voter.locator('.rb-lib-cards .rb-deck-name')).toHaveCount(0);
  await voter.locator('.rb-library-tabs .rb-tab', { hasText: 'User Libraries' }).click();
  await expect(voter).toHaveURL(/\/libraries\/user\?/);
  // as cards, two to a row (Ketura, 2026-11-08)
  await expect(voter.locator('.rb-lib-cards .rb-deck-name')).toHaveText(name);
  await expect(voter.locator('.rb-lib-cards .rb-thumb-part')).toHaveCount(2);
  await voter.locator('.rb-lib-cards .rb-deck-name').click();
  await expect(voter).toHaveURL(/\/library\/view\/\d+\//);
  await expect(voter.locator('.rb-library-title')).toHaveText(name);
  await expect(voter.locator('.rb-lv-description strong')).toHaveText('testing');
  await expect(voter.locator('.rb-library-notes-body')).toContainText('Notes on the library as a whole.');
  await expect(voter.locator('#btn-edit-library')).toHaveCount(0);
  // the description, then the decks as contents beside the notes (Ketura, 2026-11-09); no deck
  // open until one is asked for
  await expect(voter.locator('.rb-lv-toc .rb-deck-name')).toHaveCount(await voter.locator('.rb-lv-entry').count());
  await expect(voter.locator('.rb-lv-toc .rb-deck-name').first()).toBeVisible();
  await expect(voter.locator('.rb-lv-entry.is-open')).toHaveCount(0);
  // a deck in the contents opens its row and brings it up under the header, which stays put
  await voter.locator('.rb-lv-toc-link').first().click();
  const open = voter.locator('.rb-lv-entry.is-open');
  await expect(open).toHaveCount(1);
  await expect(open).toHaveAttribute('id', 'deck-1');
  await expect(voter.locator('.rb-lv-header')).toHaveCSS('position', 'sticky');
  await expect.poll(async () => {
    const head = await voter.locator('.rb-lv-header').boundingBox(), row = await open.boundingBox();
    return head && row ? Math.abs(row.y - (head.y + head.height)) : 999;
  }).toBeLessThan(4);
  await expect(open.locator('#deck .rb-line').first()).toBeVisible();
  await expect(open.locator('.rb-lv-notes')).toContainText('Gimli, Dwarf of Erebor');
  // Previous and Next above and below the commentary (Ketura, 2026-11-09)
  await expect(open.locator('[data-step="1"]')).toHaveCount(2);
  await expect(open.locator('[data-step="1"]').first()).toBeDisabled();
  // and the decklist's own Clone, Copy Text and Download
  await expect(open.locator('.rb-lv-tools .rb-reader-clone')).toBeVisible();
  await expect(open.locator('.rb-lv-tools [data-copy-list]').first()).toBeVisible();
  await expect(open.locator('.rb-lv-tools a[href*="/decklist/export/text/"]').first()).toBeVisible();
  // the deck's view buttons together, not spread over the row
  const views = open.locator('.rb-display-opts').first();
  await expect(views).toHaveCSS('border-top-style', 'solid');
  // Back to Top
  await voter.locator('#btn-back-to-top').click();
  await expect(voter.locator('.rb-lv-description')).toBeInViewport();

  // Cheers, Favourite and Comment (Ketura, 2026-11-09)
  const like = voter.locator('#social-like'), fav = voter.locator('#social-favorite');
  await like.click();
  await expect(like).toHaveAttribute('aria-pressed', 'true');
  await expect(like.locator('.num')).toHaveText('1');
  await fav.click();
  await expect(fav).toHaveAttribute('aria-pressed', 'true');
  await expect(fav.locator('.num')).toHaveText('1');
  await voter.locator('#social-comment').click();
  await voter.locator('#comment-form-text').fill(`A good set for run ${runId}`);
  await voter.locator('.rb-comment-form button[type=submit]').click();
  await expect(voter.locator('#library-comments .comment-text')).toHaveText(`A good set for run ${runId}`);
  await expect(voter.locator('#social-comment .num')).toHaveText('1');
  // kept: on a fresh load, pressed and counted; the list shows the counts
  await voter.reload();
  await expect(like).toHaveAttribute('aria-pressed', 'true');
  await expect(voter.locator('#library-comments .comment-text')).toHaveCount(1);
  await voter.goto(`/libraries/user?tags[]=e2etag${runId}`);
  await expect(voter.locator('.rb-lib-cards .rb-votes .v-cheers')).toHaveText('1');
  await expect(voter.locator('.rb-lib-cards .rb-lib-card-description')).toContainText('testing');
  // its curator can't like their own
  await page.goto(publicUrl);
  await expect(page.locator('#social-like')).toBeDisabled();
  expect((await voter.goto(editUrl))?.status()).toBe(403);
});

test('Add to Library from the decklists page: to a library, or a new one made in the drawer', async ({ page }) => {
  await page.goto('/decklists');
  const row = page.locator('.rb-decks tbody tr').nth(2);
  await row.locator('[data-library-add]').click();
  const drawer = page.locator('.rb-library-drawer');
  await expect(drawer).toBeVisible();
  // most recently changed first, and searchable
  await drawer.locator('.rb-ld-search').fill(runId);
  await expect(drawer.locator('.rb-ld-library')).toHaveCount(1);
  await drawer.locator('.rb-ld-library').click();
  await expect(drawer.locator('.rb-ld-message')).toContainText(`Added to ${name}.`);
  await expect(drawer.locator('.rb-ld-library')).toHaveClass(/is-in/);

  // + Add New Library: named in the drawer, the deck goes in it
  await drawer.locator('.rb-ld-new').click();
  await drawer.locator('.rb-ld-new-name').fill(`E2E drawer library ${runId}`);
  await drawer.locator('.rb-ld-new-form button[type=submit]').click();
  await expect(drawer.locator('.rb-ld-message')).toContainText(`Added to E2E drawer library ${runId}.`);
  await page.keyboard.press('Escape');
  await expect(drawer).toHaveCount(0);

  await page.goto(`/my/libraries?name=${encodeURIComponent('E2E drawer library ' + runId)}`);
  await expect(page.locator('.rb-library-table tbody tr')).toHaveCount(1);
  await expect(page.locator('.rb-library-table .rb-library-count')).toHaveText('1');
  await expect(page.locator('.rb-library-table .rb-vis')).toContainText('Private');
  await page.goto(editUrl);
  await waitForCardData(page);
  await expect(page.locator('#library-entries .rb-lib-entry')).toHaveCount(2);
});


test('seeded libraries: official, public, and updated in place on a second import', async ({ page }) => {
  // a decklist this run published, from the public library's view
  await page.goto(publicUrl);
  const decklistUrl = await page.locator('.rb-lv-open').first().getAttribute('href') ?? '';
  const decklistId = Number(decklistUrl.match(/\/decklist\/view\/(\d+)/)![1]);
  const manifest = (title: string) => JSON.stringify({
    source: 'e2e', owner: loadUsers().author.username,
    libraries: [{ id: `seeded-${runId}`, name: title, description: 'Seeded **for the test**.', tags: [`e2eseed${runId}`],
      decks: [{ decklist: decklistId, annotation: 'From the manifest.' }, { decklist: 999999999 }] }],
  });
  const first = appConsole(['app:import:seed-libraries', '-'], manifest(`E2E seeded ${runId}`));
  expect(first).toContain('1 created');
  expect(first).toContain('1 decks not found');
  // listed under Libraries, the PC's, marked as official
  await page.goto(`/libraries?tags[]=e2eseed${runId}`);
  await expect(page.locator('.rb-lib-cards .rb-deck-name')).toHaveText(`E2E seeded ${runId}`);
  await expect(page.locator('.rb-lib-cards .rb-official-mark')).toBeVisible();
  await expect(page.locator('.rb-lib-cards .rb-library-count')).toHaveText('1');
  // the same manifest again updates it, rather than making another
  expect(appConsole(['app:import:seed-libraries', '-'], manifest(`E2E seeded again ${runId}`))).toContain('1 updated');
  await page.goto(`/libraries?tags[]=e2eseed${runId}`);
  await expect(page.locator('.rb-lib-cards .rb-deck-name')).toHaveText(`E2E seeded again ${runId}`);
});

async function importDeck(page: Page, deckName: string): Promise<string> {
  const [, gemp] = fs.readFileSync(path.join(__dirname, '..', 'fixtures', 'fellowship-gemp.txt'), 'utf8').trim().split('\n');
  await page.goto('/deck/new');
  await page.fill('#content', `${deckName}\n${gemp}`);
  await page.click('#new-deck-submit');
  await expect(page).toHaveURL(/\/deck\/edit\//);
  return page.url().match(/\/deck\/edit\/([0-9a-f-]{36})/)![1];
}

test('My Decks: published decks can go in a library, the rest say to publish first', async ({ page }) => {
  // one published, one not
  const uuid = await importDeck(page, `E2E library published ${runId}`);
  await page.goto(`/deck/publish/${uuid}`);
  await page.click('.rb-form-actions button[form=decklist-form]');
  await expect(page).toHaveURL(/\/decklist\/view\//);
  await importDeck(page, `E2E library unpublished ${runId}`);

  await page.goto('/decks');
  const published = page.locator('#decks tr[data-published] [data-library-add]');
  await expect(published.first()).toBeVisible();
  await expect(page.locator('#decks tr:not([data-library-id]) .rb-icon-btn.is-disabled').first()).toBeVisible();
  // and several at once from the selection
  await page.locator('#decks tr[data-library-id] .rb-col-pick input').first().check();
  await page.click('#btn-library-add-selected');
  await expect(page.locator('.rb-library-drawer')).toBeVisible();
});

test('deleting a library from My Libraries leaves its decks alone', async ({ page }) => {
  await page.goto(`/my/libraries?name=${encodeURIComponent('E2E drawer library ' + runId)}`);
  await page.locator('.btn-delete-library').click();
  await expect(page.locator('#delete-library-modal')).toBeVisible();
  await page.click('#delete-library-confirm');
  await expect(page.locator('.rb-results-none')).toBeVisible();
});
