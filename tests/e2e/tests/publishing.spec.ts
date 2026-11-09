import fs from 'fs';
import path from 'path';
import { Page } from '@playwright/test';
import { test, expect, runId, snap, waitForCardData } from '../helpers/test';
import { loadUsers, storageFor } from '../helpers/users';
import { appConsole } from '../helpers/console';

// The publishing model (Ketura, 2026-11-07): a deck's publications form a line of
// versions; within 72 hours of publishing the author can undo it, fix the cards and
// publish again at the same address; after that the cards are fixed. Plus the
// derived-from picker. The tests build on each other, so they run in order.

test.describe.configure({ mode: 'serial' });
test.use({ storageState: storageFor('author') });

const lineName = `E2E line ${runId}`;
let deckUuid = '';
let v1 = { id: '', url: '' };
let v2 = { id: '', url: '' };

async function publish(page: Page, name: string): Promise<{ id: string; url: string }> {
  await page.fill('#name', name);
  await page.click('.rb-form-actions button[form=decklist-form]:has-text("Publish")');
  await expect(page).toHaveURL(/\/decklist\/view\/(\d+)\//);
  const url = page.url();
  return { id: url.match(/\/decklist\/view\/(\d+)\//)![1], url };
}

test('publishing a deck again defaults to its next version', async ({ page }) => {
  const [, gemp] = fs.readFileSync(path.join(__dirname, '..', 'fixtures', 'fellowship-gemp.txt'), 'utf8').trim().split('\n');
  await page.goto('/deck/import');
  await page.selectOption('#target_format', 'fotr_block');
  await page.fill('#content', `E2E versions ${runId}\n${gemp}`);
  await page.click('#new-deck-submit');
  await expect(page).toHaveURL(/\/deck\/edit\//);
  await waitForCardData(page);
  deckUuid = page.url().match(/\/deck\/edit\/([0-9a-f-]{36})/)![1];

  // never published: a new decklist
  await page.goto(`/deck/publish/${deckUuid}`);
  // the version publishing makes, under the Deck Name heading (Ketura, 2026-11-08)
  await expect(page.locator('#version-line')).toHaveText('Publishing as version v1.0');
  v1 = await publish(page, `${lineName} one`);
  await expect(page.locator('.rb-reader-version')).toHaveText('v1.0');
  // the decklist's History tab: its one version, so far (Ketura, 2026-11-08)
  await page.click('a[href="#tab-pane-history"]');
  await expect(page.locator('.rb-dh-version')).toHaveCount(1);
  await expect(page.locator('.rb-dh-version.is-current .rb-dh-number')).toHaveText('v1.0');

  // the deck's own page reads as the decklist's, less likes and comments, with what it was
  // published as under the commentary (Ketura, 2026-11-08)
  await page.goto(`/deck/view/${deckUuid}`);
  await expect(page.locator('.rb-reader-saved')).toBeVisible();
  await expect(page.locator('.rb-social')).toHaveCount(0);
  await expect(page.locator('#comment-form')).toHaveCount(0);
  await expect(page.locator('.rb-reader-desc .rb-reader-comments #table-successor')).toContainText(`${lineName} one`);
  // the lineage is one line with its counts, opening to the tables and closing from a heading
  const tree = page.locator('#reader-tree');
  await expect(tree.locator('.rb-tree-summary')).toContainText('Derived From: 0');
  await expect(tree.locator('.rb-tree-count.is-some')).toHaveText('1');
  await tree.locator('.rb-tree-summary').click();
  await expect(tree.locator('#table-successor')).toBeVisible();
  await tree.locator('#table-successor thead th').click();
  await expect(tree.locator('#table-successor')).toBeHidden();
  // the same three buttons as a decklist's, and no Publish
  await expect(page.locator('.rb-reader-buttons-wide > *')).toHaveText(['Clone', 'Copy Text', 'Download']);
  await expect(page.locator('.rb-reader-buttons .deck-publish')).toHaveCount(0);
  await expect(page.locator('.rb-reader-posted')).toHaveText(/^Posted by /);
  // who can see it as it stands, and that it's been published, apart
  await expect(page.locator('.rb-reader-meta .rb-vis')).toHaveText(['Private', 'Published']);
  // the commentary widens from the bar beside it
  const desc = page.locator('.rb-reader-desc');
  const before = (await desc.boundingBox())!.width;
  const bar = (await page.locator('#reader-splitter-desc').boundingBox())!;
  await page.mouse.move(bar.x + bar.width / 2, bar.y + 200);
  await page.mouse.down();
  await page.mouse.move(bar.x + bar.width / 2 - 60, bar.y + 200, { steps: 4 });
  await page.mouse.up();
  expect((await desc.boundingBox())!.width).toBeCloseTo(before + 60, -1);
  await page.locator('#reader-splitter-desc').dblclick();
  await snap(page, 'saved deck in the reader');

  /*
   * Still as published (Ketura, 2026-11-08): greyed, on the Notes tab, with New Revision; a
   * change is held off (the banner shows again), Build too, until New Revision makes 1.1.
   * Looking around still works.
   */
  await page.goto(`/deck/edit/${deckUuid}`);
  await waitForCardData(page);
  await expect(page.locator('.rb-builder')).toHaveClass(/is-locked/);
  await expect(page.locator('#revision-banner')).toContainText('v1.0');
  await expect(page.locator('#revision-banner')).toContainText('A new private revision can be started to prepare the next deck version.');
  await expect(page.locator('#tab-pane-infos')).toBeVisible();
  await expect(page.locator('#btn-publish')).toBeDisabled();
  await expect(page.locator('#btn-publish')).toHaveText(/Published as 1\.0/);
  await page.click('a[href="#tab-pane-stats"]');
  await expect(page.locator('#tab-pane-stats')).toBeVisible();
  await expect(page.locator('#btn-preview')).toHaveAttribute('href', new RegExp(`/deck/view/${deckUuid}$`));
  await page.click('a[href="#tab-pane-build"]', { force: true });
  await expect(page.locator('#tab-pane-build')).toBeHidden();
  const contentBefore = await page.evaluate(() => JSON.stringify((window as any).app.deck.get_content()));
  await page.locator('#deck [data-qty]').first().click();
  await expect(page.locator('#revision-banner')).toHaveClass(/is-pulse/);
  expect(await page.evaluate(() => JSON.stringify((window as any).app.deck.get_content()))).toBe(contentBefore);
  await page.click('#btn-new-revision');
  await expect(page.locator('.rb-builder')).not.toHaveClass(/is-locked/);
  await expect(page.locator('#save-status')).toContainText('Private revision 1.1 started');
  // Private and Hidden are who sees the deck as it stands; publishing is its own button
  await page.click('#btn-visibility');
  await expect(page.locator('#menu-visibility [data-visibility]')).toHaveCount(2);
  await page.keyboard.press('Escape');
  // the deck pane widens from the bar between the panes
  const left = page.locator('.rb-col-left');
  const leftBefore = (await left.boundingBox())!.width;
  const panes = (await page.locator('#builder-splitter').boundingBox())!;
  await page.mouse.move(panes.x + panes.width / 2, panes.y + 300);
  await page.mouse.down();
  await page.mouse.move(panes.x + panes.width / 2 - 50, panes.y + 300, { steps: 4 });
  await page.mouse.up();
  expect((await left.boundingBox())!.width).toBeCloseTo(leftBefore - 50, -1);
  await page.locator('#builder-splitter').dblclick();

  // published before: its next version, in its own line
  await page.goto(`/deck/publish/${deckUuid}`);
  await expect(page.locator('#version-line')).toHaveText('Publishing as version v2.0');
  await snap(page, 'publishing version 2');
  v2 = await publish(page, `${lineName} two`);

  // each version is its own decklist; the menu lists the line, and the older one points on
  await page.locator('#dropdownVersions').click();
  await expect(page.locator('#dropdownVersions')).toContainText('v2.0');
  await expect(page.locator('.rb-reader-versions .dropdown-menu a')).toHaveText([`v2.0 · ${lineName} two`, `v1.0 · ${lineName} one`]);
  // the History tab: both, with what changed between them; the deck is 2.0 now
  await page.click('a[href="#tab-pane-history"]');
  await expect(page.locator('.rb-dh-version .rb-dh-head .rb-dh-number')).toHaveText(['v2.0', 'v1.0']);
  await page.goto(v1.url);
  await expect(page.locator('.rb-newer-note')).toContainText('v2.0');
});

test('the author undoes publishing, replaces the cards and publishes again', async ({ page, pageAs }) => {
  await page.goto(v2.url);
  const withdraw = page.locator('.rb-reader-buttons .decklist-withdraw');
  await expect(withdraw).toBeVisible();
  await expect(withdraw).toHaveAttribute('title', /possible until/);
  page.once('dialog', (dialog) => dialog.accept());
  await Promise.all([page.waitForURL(new RegExp(`/decklist/edit/${v2.id}$`)), withdraw.click()]);
  await expect(page.locator('#decklist-state')).toHaveAttribute('data-state', 'withdrawn');
  // while withdrawn, the release date can change too
  await expect(page.locator('#effective_date')).toBeEnabled();
  await snap(page, 'withdrawn decklist');

  // gone for everyone else: the page says so, the lists and the line leave it out
  const voter = await pageAs('voter');
  expect((await voter.goto(v2.url))?.status()).toBe(410);
  await expect(voter.locator('h1')).toHaveText('Decklist Withdrawn');
  await voter.goto(v1.url);
  await expect(voter.locator('.rb-newer-note')).toHaveCount(0);
  await expect(voter.locator('.rb-reader-version')).toHaveText('v1.0');
  await voter.goto(`/decklists?name=${encodeURIComponent(lineName)}`);
  await expect(voter.getByRole('link', { name: `${lineName} one` })).toBeVisible();
  await expect(voter.getByRole('link', { name: `${lineName} two` })).toHaveCount(0);
  expect((await voter.request.get(`/api/public/decklist/${v2.id}`)).status()).toBe(404);

  // the author still sees it, marked withdrawn
  await page.goto(v2.url);
  await expect(page.locator('.rb-withdrawn-note')).toBeVisible();

  // the fix: the deck's cards again, then public at the same address
  await page.goto(`/decklist/edit/${v2.id}`);
  await page.getByRole('button', { name: 'Replace Cards From Deck' }).click();
  await expect(page.locator('.alert-success[role=alert]')).toContainText('The cards were replaced from the deck.');
  await page.getByRole('button', { name: 'Publish Again' }).first().click();
  await expect(page).toHaveURL(new RegExp(`/decklist/view/${v2.id}/`));
  expect((await voter.goto(v2.url))?.status()).toBe(200);
  await voter.goto(v1.url);
  await expect(voter.locator('.rb-newer-note')).toContainText('v2.0');
});

test('after the grace period the cards are fixed', async ({ page }) => {
  appConsole(['dbal:run-sql', `UPDATE published_deck SET date_published = DATE_SUB(NOW(), INTERVAL 4 DAY) WHERE id = ${v1.id}`]);
  await page.goto(`/decklist/edit/${v1.id}`);
  await expect(page.locator('#decklist-state')).toHaveAttribute('data-state', 'fixed');
  await expect(page.locator('#effective_date')).toBeDisabled();
  await expect(page.getByRole('button', { name: 'Undo Publish' })).toHaveCount(0);
  await page.goto(v1.url);
  await expect(page.locator('.rb-reader-buttons .decklist-edit')).toBeVisible();
  await expect(page.locator('.decklist-withdraw')).toHaveCount(0);
});

test('derived from: search by author and name, or paste an address', async ({ page }) => {
  const users = loadUsers();
  await page.goto(`/decklist/edit/${v1.id}`);
  await page.fill('#derived-author', users.author.username);
  await page.fill('#derived-name', `${lineName} two`);
  const result = page.locator('.rb-derived-result', { hasText: `${lineName} two` });
  await expect(result).toBeVisible();
  await expect(result).toContainText(users.author.username);
  // the decklist being edited is never offered
  await page.fill('#derived-name', lineName);
  await expect(page.locator('.rb-derived-result')).toHaveCount(1);
  await result.click();
  await expect(page.locator('#derived-chosen')).toContainText(`${lineName} two`);
  await expect(page.locator('#derived-chosen')).toContainText('v2.0');
  await expect(page.locator('#precedents')).toHaveValue(v2.id);
  await snap(page, 'derived-from picked');
  await page.click('.rb-form-actions button[form=decklist-form]:has-text("Save")');
  await expect(page.locator('#table-predecessor')).toContainText(`${lineName} two`);

  // several (Ketura, 2026-11-08): remove it, paste an address, then add another by name
  await page.goto(`/decklist/edit/${v1.id}`);
  await page.click('#derived-chosen .rb-derived-remove');
  await expect(page.locator('#precedents')).toHaveValue('');
  await page.fill('#derived-name', v2.url);
  await expect(page.locator('#derived-chosen .rb-derived-pick')).toHaveCount(1);
  await expect(page.locator('#precedents')).toHaveValue(v2.id);
  await page.fill('#derived-author', '');
  await page.fill('#derived-name', 'E2E');
  const other = page.locator('.rb-derived-result').filter({ hasNotText: `${lineName} two` }).first();
  await expect(other).toBeVisible();
  await other.click();
  await expect(page.locator('#derived-chosen .rb-derived-pick')).toHaveCount(2);
  expect((await page.inputValue('#precedents')).split(',')).toHaveLength(2);
  await page.click('.rb-form-actions button[form=decklist-form]:has-text("Save")');
  await expect(page.locator('#reader-tree .rb-tree-count').first()).toHaveText('2');
});

test('the thumbnail card can be framed by hand, one card at a time', async ({ page }) => {
  await page.goto(`/decklist/edit/${v1.id}`);
  await page.click('#thumb-select');
  const card = page.locator('#thumb-options .rb-thumb-option[data-group="fp"]').first();
  const code = await card.getAttribute('data-value');
  await card.click();

  // the pencil over the thumbnail opens the editor: the card, with the template's box on it
  await page.hover('.rb-thumb-preview');
  await expect(page.locator('#thumb-edit')).toBeVisible();
  await page.click('#thumb-edit');
  const frame = page.locator('.rb-crop-frame');
  await expect(frame).toBeVisible();
  const image = (await page.locator('.rb-crop-image').boundingBox())!;
  let box = (await frame.boundingBox())!;

  // moved by dragging, and never off the card
  await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2);
  await page.mouse.down();
  await page.mouse.move(box.x - 2000, box.y + box.height / 2, { steps: 4 });
  await page.mouse.up();
  box = (await frame.boundingBox())!;
  expect(Math.abs(box.x - image.x)).toBeLessThan(1.5);

  // resized from a corner, keeping the tile's shape, down to an eighth of the card's width at most
  await page.mouse.move(box.x + box.width, box.y + box.height);
  await page.mouse.down();
  await page.mouse.move(box.x - 2000, box.y - 2000, { steps: 4 });
  await page.mouse.up();
  box = (await frame.boundingBox())!;
  expect(Math.abs(box.width - image.width / 8)).toBeLessThan(1.5);
  expect(Math.abs(box.width / box.height - 260 / 231)).toBeLessThan(0.02);
  await snap(page, 'thumbnail crop editor');
  await page.click('.rb-crop-confirm');
  await expect(frame).toBeHidden();
  const crop = JSON.parse(await page.inputValue('#thumbnail_crop'));
  expect(crop).toMatchObject({ code, w: 800, l: 0 });
  await page.click('.rb-form-actions button[form=decklist-form]:has-text("Save")');

  // the lists show it framed so
  await page.goto(`/decklists?name=${encodeURIComponent(`${lineName} one`)}`);
  const thumb = page.locator('.rb-col-thumb .rb-thumb-card img').first();
  await expect(thumb).toHaveAttribute('style', /width: 800%; left: 0%/);

  // Cancel keeps it; Reset and Confirm drop it
  await page.goto(`/decklist/edit/${v1.id}`);
  await page.hover('.rb-thumb-preview');
  await page.click('#thumb-edit');
  await page.mouse.move(image.x + 5, image.y + 5);
  await page.click('.rb-crop-cancel');
  expect(JSON.parse(await page.inputValue('#thumbnail_crop'))).toMatchObject({ code, w: 800 });
  await page.hover('.rb-thumb-preview');
  await page.click('#thumb-edit');
  await page.click('.rb-crop-reset');
  await page.click('.rb-crop-confirm');
  await expect(page.locator('#thumbnail_crop')).toHaveValue('');
  await page.click('.rb-form-actions button[form=decklist-form]:has-text("Save")');
  await page.goto(`/decklists?name=${encodeURIComponent(`${lineName} one`)}`);
  await expect(page.locator('.rb-col-thumb .rb-thumb-card img').first()).not.toHaveAttribute('style', /.+/);
});

test('editing a published decklist: patches, the deck, and its private revision', async ({ page }) => {
  // the deck is as published, 2.0: an edit to the decklist is 2.0.1, and the deck takes it quietly
  await page.goto(`/decklist/edit/${v2.id}`);
  await page.fill('#starting-notes', `Notes one ${runId}`);
  await page.click('.rb-form-actions button[form=decklist-form]:has-text("Save")');
  await expect(page).toHaveURL(new RegExp(`/decklist/view/${v2.id}/`));
  await page.click('a[href="#tab-pane-details"]');
  await expect(page.locator('.rb-reader-dates dd').first()).toHaveText('v2.0.1');
  await page.click('a[href="#tab-pane-history"]');
  const edits = page.locator('.rb-dh-version.is-current .rb-dh-edits li');
  await expect(edits).toHaveCount(1);
  await expect(edits.first()).toContainText('2.0.1');
  await expect(edits.first()).toContainText('starting notes');

  // the deck still reads as published, with the notes; a revision begins, 2.1
  await page.goto(`/deck/edit/${deckUuid}`);
  await waitForCardData(page);
  await expect(page.locator('.rb-builder')).toHaveClass(/is-locked/);
  await expect(page.locator('#starting-notes')).toHaveValue(`Notes one ${runId}`);
  await page.click('#deck-name');
  await expect(page.locator('#deck-name')).not.toBeFocused();
  await page.click('#btn-new-revision');
  await expect(page.locator('#save-status')).toContainText('Private revision 2.1 started');
  await page.fill('#deck-name', `${lineName} private`);
  await page.click('#btn-save');
  await expect(page.locator('#save-status')).toContainText('Saved as version 2.2');
  await expect(page.locator('#btn-publish')).toHaveText(/Publish 3\.0/);
  await page.click('#tab-header-history');
  await expect(page.locator('.rb-history-group')).toContainText('3.0');
  await expect(page.locator('.rb-history-changed').first()).toContainText('name');
  await expect(page.locator('.rb-history-patch').first()).toContainText('2.0.1');

  // My Decks: who sees it as it stands, and what it was published as
  await page.goto(`/decks?name=${encodeURIComponent(`${lineName} private`)}`);
  const row = page.locator('#decks tr').first();
  await expect(row.locator('.rb-col-visibility')).toContainText('Private');
  await expect(row.locator('.rb-col-visibility')).toContainText('v2.2 · changed');
  await expect(row.locator('.rb-col-published')).toContainText('v2.0');

  // another edit to the decklist asks whether the private 2.2 takes it too
  await page.goto(`/decklist/edit/${v2.id}`);
  await page.fill('#starting-notes', `Notes two ${runId}`);
  await page.click('.rb-form-actions button[form=decklist-form]:has-text("Save")');
  await expect(page.locator('#apply-to-deck-modal')).toBeVisible();
  await expect(page.locator('#apply-to-deck-modal')).toContainText('2.2');
  await page.click('#apply-to-deck-modal [data-apply="1"]');
  await expect(page).toHaveURL(new RegExp(`/decklist/view/${v2.id}/`));
  await page.goto(`/deck/edit/${deckUuid}`);
  await waitForCardData(page);
  await expect(page.locator('.rb-builder')).not.toHaveClass(/is-locked/);
  await expect(page.locator('#starting-notes')).toHaveValue(`Notes two ${runId}`);
  await expect(page.locator('#deck-name')).toHaveValue(`${lineName} private`);
  await page.click('#tab-header-history');
  await expect(page.locator('.rb-history-edit').first()).toContainText("From the decklist's 2.0.2 edit");
  await expect(page.locator('#tab-pane-history .rb-history-saved').first()).toContainText('2.3');

  // History's Revert puts the format back too (Ketura, 2026-11-08)
  const format = await page.inputValue('#deck-format');
  const other = await page.$$eval('#deck-format option', (options, f) => options.map(o => (o as HTMLOptionElement).value).find(v => v && v !== f), format);
  await page.selectOption('#deck-format', other!);
  await page.click('#btn-save');
  await expect(page.locator('#save-status')).toContainText('Saved as version 2.4');
  await page.click('#tab-header-history');
  await page.locator('#tab-pane-history .rb-history-saved').nth(1).locator('a[role=button]').click();
  await expect(page.locator('#deck-format')).toHaveValue(format);
  await page.click('#btn-revert');

  // the deck's page has a History tab like a decklist's, ending with where it came from
  await page.goto(`/deck/view/${deckUuid}`);
  await page.click('a[href="#tab-pane-history"]');
  await expect(page.locator('.rb-dh-revision').first()).toContainText('v2.4');
  await expect(page.locator('.rb-dh-published').first()).toContainText('v2.0');
  await expect(page.locator('.rb-dh-origin')).toContainText('Deck imported from pasted text');
  await expect(page.locator('.rb-dh-origin .rb-dh-number')).toHaveText('v0.1');

  // deleting it says the decklists stay; the decklist then offers Recreate, which brings it back
  await page.goto(`/deck/edit/${deckUuid}`);
  await waitForCardData(page);
  await page.click('#btn-more');
  await page.click('#menu-more [data-action=delete]');
  await expect(page.locator('#deleteModal .rb-delete-published')).toBeVisible();
  await page.click('#deleteModal button[type=submit]');
  await expect(page).toHaveURL(/\/decks/);
  await page.goto(v2.url);
  await expect(page.locator('.rb-reader-buttons-wide .decklist-recreate')).toBeVisible();
  await expect(page.locator('.rb-reader-buttons-wide .rb-reader-clone')).toHaveCount(0);
  await page.locator('.rb-reader-buttons-wide .decklist-recreate').click();
  await expect(page).toHaveURL(new RegExp(`/deck/edit/${deckUuid}`));
  await waitForCardData(page);
  await page.click('#tab-header-history');
  await expect(page.locator('#tab-pane-history')).toContainText('Recreated after it was deleted');
});

test('only a legal deck, or a fragment of one side, can be published', async ({ page }) => {
  // Ketura, 2026-11-08 (decision 0025)
  const [, gemp] = fs.readFileSync(path.join(__dirname, '..', 'fixtures', 'fellowship-gemp.txt'), 'utf8').trim().split('\n');
  const [rb, ring, sites, draw] = gemp.split('|');
  const cards = draw.split(',');
  const importDeck = async (name: string, deck: string) => {
    await page.goto('/deck/new');
    await page.fill('#content', `${name}\n${deck}`);
    await page.click('#new-deck-submit');
    await expect(page).toHaveURL(/\/deck\/edit\//);
    await waitForCardData(page);
    return page.url().match(/\/deck\/edit\/([0-9a-f-]{36})/)![1];
  };

  // a whole deck a Free Peoples card short: Publish says why, and stays
  const short = await importDeck(`E2E short ${runId}`, [rb, ring, sites, cards.slice(1).join(',')].join('|'));
  await page.click('#btn-publish');
  const gate = page.locator('#publish-gate-modal');
  await expect(gate).toBeVisible();
  await expect(gate).toContainText('Your deck is not legal for the Fellowship Block format for the following reasons:');
  await expect(gate.locator('.rb-validation-list li')).toContainText([
    'Deck contains below minimum number of cards: 59<60.',
    'Deck contains different number of Shadow and Free peoples cards.',
  ]);
  await expect(gate).toContainText('You may only publish legal decks.');
  await expect(gate.locator('strong')).toHaveText('deck fragment');
  await gate.locator('.modal-footer button').click();
  await expect(gate).toBeHidden();
  await expect(page).toHaveURL(new RegExp(`/deck/edit/${short}`));

  // the publish page by its address: the same popup, back to the deckbuilder, and no sending it
  await page.goto(`/deck/publish/${short}`);
  await expect(page.locator('#publish-gate-modal')).toBeVisible();
  await expect(page.locator('#decklist-submit')).toBeDisabled();
  await page.click('#publish-gate-back');
  await expect(page).toHaveURL(new RegExp(`/deck/edit/${short}`));

  // one side only, but 29 cards: not a fragment
  await importDeck(`E2E small half ${runId}`, [rb, ring, sites, cards.slice(0, 29).join(',')].join('|'));
  await page.click('#btn-publish');
  await expect(page.locator('#publish-gate-modal')).toBeVisible();

  // 30 Free Peoples cards and no Shadow: a fragment, published
  await importDeck(`E2E fragment ${runId}`, [rb, ring, sites, cards.slice(0, 30).join(',')].join('|'));
  await page.click('#btn-publish');
  await expect(page).toHaveURL(/\/deck\/publish\//);
  await expect(page.locator('#publish-gate-modal')).toHaveCount(0);
  await publish(page, `E2E fragment ${runId}`);
});
