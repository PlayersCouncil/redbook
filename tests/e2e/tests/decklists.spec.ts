import fs from 'fs';
import path from 'path';
import { test, expect, runId, snap, waitForCardData } from '../helpers/test';
import { storageFor, loadUsers } from '../helpers/users';
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
  await page.click('#new-deck-submit');
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

  // Publish is its own button, apart from who can see the deck as it stands (Ketura, 2026-11-08)
  await expect(page.locator('#btn-publish')).toHaveText(/^\s*Publish\s*$/);
  await page.click('#btn-publish');
  await expect(page).toHaveURL(/\/deck\/publish\//);
  // one form for publishing and editing (Ketura, 2026-11-06), laid out as the deckbuilder
  // (2026-11-07): the deck on the left with its format, the form as a tab on the right
  await expect(page.locator('.rb-publish-title')).toHaveText('Publish Deck');
  await expect(page.locator('.rb-publish-facts .rb-format')).toHaveText('Fellowship Block');
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
  await page.fill('#description', 'Published by the **smoke test**. Lead with [[Aragorn HTTWC]], errata [[3_38.1]], not [[Gandalf]] or [[Nobody At All]]. Beware [Moria] at 3T for (X).');
  // a note on a card, picked from the deck on the left
  await page.click('#annot-pick');
  await page.locator('#deck .rb-line[data-code="1_12"]').click();
  await page.fill('#annot-list textarea[data-annotation="1_12"]', 'The only companion.');
  // the thumbnail: the culture banner until a card is chosen, then that card (Ketura, 2026-11-05)
  await expect(page.locator('#thumb-cultures .rb-thumb-cultures')).toBeVisible();
  await expect(page.locator('#thumb-select')).toHaveText('Culture Banner');
  // a list of its own (Ketura, 2026-11-07): ring-bearer and ring, each side, the sites; the
  // wheel steps one option, and hovering an option previews it
  await page.locator('#thumb-select').hover();
  await page.mouse.wheel(0, 100);
  await expect(page.locator('#thumbnail')).toHaveValue(/^\d+_\d+$/);
  await page.mouse.wheel(0, -100);
  await expect(page.locator('#thumbnail')).toHaveValue('');
  await page.click('#thumb-select');
  await expect(page.locator('#thumb-options .rb-thumb-group')).toHaveCount(4);
  // the starting companion first among the Free Peoples cards
  await expect(page.locator('#thumb-options .rb-thumb-option[data-group="fp"]').first()).toHaveText('Gimli, Dwarf of Erebor');
  await page.locator('#thumb-options .rb-thumb-option[data-group="sites"]').first().hover();
  await expect(page.locator('#thumb-card')).toHaveClass(/rb-thumb-tpl-site/);
  await page.locator('#thumb-options .rb-thumb-option', { hasText: 'Gimli, Dwarf of Erebor' }).click();
  await expect(page.locator('#thumbnail')).toHaveValue('1_12');
  await expect(page.locator('#thumb-card img')).toHaveAttribute('src', /.+/);
  // framed to the picture by the card's template (Ketura, 2026-11-07)
  await expect(page.locator('#thumb-card')).toHaveClass(/rb-thumb-tpl-character/);
  await expect(page.locator('#thumb-cultures')).toBeHidden();
  await snap(page, 'publish form');
  await page.click('.rb-form-actions button[form=decklist-form]:has-text("Publish")');

  await expect(page).toHaveURL(/\/decklist\/view\/(\d+)\//);
  await expect(page.locator('h1')).toContainText(decklistName);
  await expect(page.locator('body')).toContainText('Published by the smoke test.');
  await expect(page.locator('a.rb-cardlink[data-code="3_38"]')).toHaveText('Aragorn, Heir to the White City');
  // game symbols, rendered when the text is saved (Ketura, 2026-11-07)
  await expect(page.locator('#deck-description .rb-sym[data-tip="Moria"] .rb-sym-moria')).toHaveCount(1);
  await expect(page.locator('#deck-description .rb-sym[data-tip="Towers Block"]')).toHaveCount(1);
  await expect(page.locator('#deck-description .rb-sym[data-tip="X Twilight"]')).toHaveCount(1);
  await expect(page.locator('a.rb-cardlink[href$="/card/53_38#v1"]')).toHaveAttribute('data-image', /LOTR-EN03S038\.1/);
  await expect(page.locator('a.rb-cardlink.is-ambiguous')).toHaveText('Gandalf');
  await expect(page.locator('.rb-cardlink.is-missing')).toHaveText('[[Nobody At All]]');
  await expect(page.locator('.deck-validation')).toContainText('Legal in Fellowship Block');
  await expect(page.locator('#reader-notes')).toContainText('The only companion.');
  // the reader: the deckbuilder's deck, read-only, "4x Card" lines
  await expect(page.locator('#deck.rb-readonly')).toContainText('Ring-bearer');
  await expect(page.locator('#deck .rb-qty-text').first()).toHaveText(/^\dx$/);
  await expect(page.locator('#deck .rb-n')).toHaveCount(0);
  // the page's own text, as a plain copy takes it when the selection starts outside the deck
  // (the deck's copy handler doesn't see that): "1x Card" lines still, the icons and the S
  // and note marks left out (Ketura, 2026-11-04; restored 2026-11-09)
  const selected = await page.evaluate(() => {
    const range = document.createRange();
    range.selectNodeContents(document.querySelector('#deck')!);
    const selection = window.getSelection()!;
    selection.removeAllRanges();
    selection.addRange(range);
    return selection.toString().split('\n');
  });
  expect(selected).toContain('1x Gimli, Dwarf of Erebor');
  expect(selected).toContain('2x Dwarven Armor');
  expect(selected).toContain('1 Bag End');
  expect(selected.filter((l: string) => /^\d+x$/.test(l))).toEqual([]);
  // and the way someone actually does it (Ketura, 2026-11-09): drag from the deck's title down
  // into the list, Ctrl+C, paste. The browser's own copy, the selection starting outside the deck.
  await page.context().grantPermissions(['clipboard-read', 'clipboard-write']);
  await page.evaluate(() => navigator.clipboard.writeText(''));
  // the deck scrolls in its own pane: bring the line to drag to into view first
  const target = page.locator('#deck .rb-line', { hasText: 'Dwarven Axe' });
  await target.evaluate((el) => el.scrollIntoView({ block: 'center' }));
  const from = (await page.locator('h1.decklist-name').boundingBox())!;
  const to = (await target.boundingBox())!;
  await page.mouse.move(from.x + 2, from.y + from.height / 2);
  await page.mouse.down();
  await page.mouse.move(to.x + to.width - 4, to.y + to.height / 2, { steps: 12 });
  await page.mouse.up();
  await page.keyboard.press('ControlOrMeta+c');
  const pasted = (await page.evaluate(() => navigator.clipboard.readText())).split(/\r?\n/).map((l: string) => l.trim());
  expect(pasted).toContain('1 Bag End');
  expect(pasted).toContain('1x Gimli, Dwarf of Erebor');
  expect(pasted).toContain('2x Dwarven Armor');
  // no count left on a line of its own (the Stats pane's axis numbers come along too: that's fine)
  expect(pasted.filter((l: string) => /^\d+x$/.test(l))).toEqual([]);
  await page.evaluate(() => window.getSelection()!.removeAllRanges());
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
  // a card note shows the card's top half beside it, centred against a short note; no line to
  // the deck any more (Ketura, 2026-11-08)
  const note = page.locator('.rb-reader-note[data-note="1_12"]');
  await expect(note.locator('.rb-note-thumb img')).toBeVisible();
  await expect(note).toHaveClass(/is-short/);
  await expect(page.locator('#annot-link')).toHaveCount(0);
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
  // on a row of their own under the others, beside the facts (Ketura, 2026-11-09); the
  // thumbnail in the corner
  await expect(page.locator('#btn-group-gated .decklist-edit')).toBeVisible();
  await expect(page.locator('#btn-group-decklist .decklist-edit')).toHaveCount(0);
  await expect(page.locator('.rb-reader-head .rb-reader-thumb .rb-thumb')).toBeVisible();
  await Promise.all([page.waitForURL(new RegExp(`/decklist/edit/${decklistId}$`)), edit.click()]);
  await expect(page.locator('#name')).toHaveValue(decklistName);
  // the same form, editing, and Cancel goes back to the decklist; within the grace period
  // (Ketura, 2026-11-07) the cards and release date change only by undoing publishing
  await expect(page.locator('.rb-publish-title')).toHaveText('Edit Decklist Details');
  await expect(page.locator('#annot-list textarea[data-annotation="1_12"]')).toHaveValue('The only companion.');
  await expect(page.locator('#decklist-state')).toHaveAttribute('data-state', 'grace');
  await expect(page.locator('#decklist-state')).toContainText('you can undo publishing if you need to fix the deck contents');
  // under it, always (Ketura, 2026-11-09): a new version starts from the deck
  const fixed = page.locator('.rb-state-fixed');
  await expect(fixed).toContainText('Once you publish a decklist, you cannot edit the cards');
  await expect(fixed.locator('a', { hasText: 'click here to open the private revision in My Decks' })).toHaveAttribute('href', /\/deck\/edit\//);
  // the deck greyed, on a darker panel, since its cards can't change here (Ketura, 2026-11-09)
  await expect(page.locator('.rb-col-left')).toHaveClass(/is-fixed/);
  await expect(page.locator('#effective_date')).toBeDisabled();
  await expect(page.locator('#version-line')).toContainText('Version v1.0');
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

  // the comment box is the deckbuilder's editor (Ketura, 2026-11-08): toolbar, Preview, its hint
  const comment = `Nice list from run ${runId}`;
  const box = page.locator('.rb-comment-form .md-editor');
  await expect(box.locator('.md-footer')).toContainText('@ to mention');
  await page.locator('.rb-comment-form.is-main textarea').pressSequentially(comment);
  await box.locator('button[data-handler="bootstrap-markdown-cmdPreview"]').click();
  await expect(box.locator('.md-preview')).toContainText(comment);
  await box.locator('button[data-handler="bootstrap-markdown-cmdPreview"]').click();
  await Promise.all([
    page.waitForResponse((r) => r.url().includes('/comment/post') && r.request().method() === 'POST'),
    page.locator('.rb-comment-form.is-main button[type=submit]').click(),
  ]);
  // it joins the thread straight away (Ketura, 2026-11-11), and the form is ready for another
  await expect(page.locator('.rb-cmt-text').filter({ hasText: comment })).toBeVisible();
  await expect(page.locator('.rb-comment-form.is-main textarea')).toHaveValue('');
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
  await expect(page.locator('.rb-cmt-text').filter({ hasText: comment })).toBeVisible();
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
  // (the cultures gave their room to the tags, Ketura 2026-11-07; Actions for Add to Library, 2026-11-08)
  await expect(page.locator('.rb-decks thead th')).toHaveText(['', 'Score', 'Size', 'Deck', 'Format', 'Tags', 'Actions']);
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

test("My Decks: the decklists page's filters, who can see each deck, and favourites", async ({ page, pageAs }) => {
  // My Decks (Ketura, 2026-11-07): the decklists page's search beside the user's decks
  const deckName = `E2E to publish ${runId}`;
  await page.goto(`/decks?name=${encodeURIComponent(deckName)}`);
  const row = page.locator('#decks tr').filter({ hasText: deckName });
  await expect(row).toHaveCount(1);
  await expect(page.locator('.rb-my-decks-table thead th')).toHaveText(['', '', 'Size', 'Deck', 'Format', 'Published', 'Current', 'Tags', 'Actions']);
  // who can see it as it stands, and what it was published as (Ketura, 2026-11-08); its tags,
  // which search when clicked
  await expect(row.locator('.rb-col-visibility')).toContainText('Private');
  await expect(row.locator('.rb-col-visibility')).toContainText('Same as v1.0');
  await expect(row.locator('.rb-col-published a')).toContainText('v1.0');
  await expect(row.locator('.rb-row-tags')).toContainText('dwarves');
  await expect(row.locator('.rb-thumb-card img')).toHaveAttribute('alt', 'Gimli, Dwarf of Erebor');
  // a row opens the deckbuilder; Preview shows the deck
  await expect(row).toHaveAttribute('data-href', /\/deck\/edit\//);
  await expect(row.locator('a[data-tip="Preview"]')).toHaveAttribute('href', /\/deck\/view\//);

  // which decks to include: leaving out the published ones leaves it out
  await page.click('label.rb-toggle-published');
  await expect(row).toHaveCount(0);
  await expect(page).toHaveURL(/show/);
  await page.click('label.rb-toggle-published');
  await expect(row).toHaveCount(1);
  await expect(page).not.toHaveURL(/show/);

  // the filters pane resizes from the bar beside it, and keeps its width
  const pane = page.locator('#browse-form');
  const before = (await pane.boundingBox())!.width;
  const bar = (await page.locator('#browse-splitter').boundingBox())!;
  await page.mouse.move(bar.x + bar.width / 2, bar.y + 100);
  await page.mouse.down();
  await page.mouse.move(bar.x + bar.width / 2 + 80, bar.y + 100, { steps: 4 });
  await page.mouse.up();
  expect((await pane.boundingBox())!.width).toBeCloseTo(before + 80, -1);
  await page.reload();
  expect((await pane.boundingBox())!.width).toBeCloseTo(before + 80, -1);
  await page.locator('#browse-splitter').dblclick();
  expect((await pane.boundingBox())!.width).toBeCloseTo(before, -1);

  // the selection's actions wait for ticked decks; Compare for two
  const selection = page.locator('#btn-group-selection');
  await expect(selection).toHaveClass(/is-off/);
  await expect(page.locator('#btn-tag-add')).toBeDisabled();
  await row.locator('.rb-col-pick input').check();
  await expect(selection).not.toHaveClass(/is-off/);
  await expect(selection.locator('.rb-selected-count')).toHaveText('(1)');
  await expect(page.locator('#btn-tag-add')).toBeEnabled();
  await expect(page.locator('#btn-compare')).toBeDisabled();
  await snap(page, 'my decks');

  // the favourites tab: the same filters, the favourites; removing one takes it off
  const voter = await pageAs('voter');
  await voter.goto('/decks');
  await expect(voter.locator('#decks tr').filter({ hasText: decklistName })).toHaveCount(0);
  await voter.fill('#browse-fields input[name=name]', decklistName);
  await voter.press('#browse-fields input[name=name]', 'Enter');
  await voter.click('#browse-tabs .rb-tab[data-tab=favorites]');
  await expect(voter).toHaveURL(/tab=favorites/);
  await expect(voter.locator('#browse-tabs .rb-tab[data-tab=favorites]')).toHaveClass(/is-active/);
  await expect(voter.locator('#browse-fields input[name=name]')).toHaveValue(decklistName);
  await expect(voter.locator('.rb-field-include')).toHaveClass(/is-off/);
  const favorite = voter.locator('#decks tr').filter({ hasText: decklistName });
  await expect(favorite).toHaveCount(1);
  await expect(voter.locator('.rb-my-decks-table thead th')).toHaveText(['', '', 'Size', 'Deck', 'Format', 'Tags', 'Actions']);
  await expect(favorite.locator('a[href*="/deck/copy/"]')).toBeVisible();
  // a favourite can't be edited, so its row opens it: no Preview
  await expect(favorite.locator('.fa-eye')).toHaveCount(0);
  await expect(voter.locator('#btn-unfavorite-selected')).toBeDisabled();
  await snap(voter, 'my favorites');
  await favorite.locator('button.btn-unfavorite').click();
  await expect(favorite).toHaveCount(0);
  await expect(voter.locator('.rb-results-none')).toBeVisible();
});

test('the decklists page filters by card, kind and culture', async ({ page }) => {
  await page.goto(`/decklists?name=${encodeURIComponent(decklistName)}`);
  await page.waitForFunction(() => (window as any).app?.data?.cards?.find().length > 100);
  // a card picked from the suggestions is searched for straight away
  await page.locator('#browse-card').fill('Gimli, Dwarf of Er');
  await Promise.all([page.waitForURL(/cards/), page.locator('.tt-suggestion').first().click()]);
  await expect(page.locator('#browse-cards .rb-card-pick')).toContainText('Gimli, Dwarf of Erebor');
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
  // the Tags drawer: the public decklists' tags, filtered as typed; a pick searches
  await page.goto(`/decklists?name=${encodeURIComponent(decklistName)}`);
  await page.click('#browse-tag');
  const drawer = page.locator('#browse-tag-suggest');
  await expect(drawer).toBeVisible();
  await page.locator('#browse-tag').fill('dwar');
  await expect(drawer.locator('button').first()).toContainText('dwarves');
  await Promise.all([page.waitForURL(/tags/), drawer.locator('button', { hasText: 'dwarves' }).click()]);
  await expect(page.locator('#browse-tags .rb-tag-pick')).toHaveText(/dwarves/);
  await expect(page.locator('#browse-tag')).toBeFocused();
  await expect(drawer.locator('button', { hasText: /^dwarves/ })).toHaveCount(0);
  await page.keyboard.press('Escape');
  await expect(drawer).toBeHidden();
  await page.goto(`/decklists?name=${encodeURIComponent(decklistName)}`);
  await Promise.all([page.waitForURL(/tags/), page.locator('.rb-tag-link', { hasText: 'dwarves' }).first().click()]);
  await expect(page.locator('#browse-tags .rb-tag-pick')).toHaveText(/dwarves/);
  await expect(page.getByRole('link', { name: decklistName })).toBeVisible();
  await page.locator('#browse-tag').fill('no-such-tag');
  await Promise.all([page.waitForURL(/no-such-tag/), page.locator('#browse-tag').press('Enter')]);
  await expect(page.locator('.rb-results-none')).toBeVisible();
  // the page size: not a filter, so nothing for Reset
  await page.goto('/decklists?limit=10');
  expect(await page.locator('.rb-decks tbody tr').count()).toBeLessThanOrEqual(10);
  await expect(page.locator('select[name=limit]')).toHaveValue('10');
  await expect(page.locator('.rb-browse-clear')).toHaveClass(/is-idle/);
  // submitted dates from the calendar, applied as they're picked (Ketura, 2026-11-06)
  await page.goto(`/decklists?name=${encodeURIComponent(decklistName)}`);
  await Promise.all([page.waitForURL(/to=2003-12-31/), page.locator('input[name=to]').fill('2003-12-31')]);
  await expect(page.locator('.rb-results-none')).toBeVisible();
  // Reset, beside Search, keeps nothing but the sort (Ketura, 2026-11-07)
  await page.goto(`/decklists?name=x&sort=rated`);
  await page.getByRole('link', { name: 'Reset' }).click();
  await expect(page).toHaveURL(/\/decklists\?sort=rated$/);
  await expect(page.locator('#browse-fields input[name=name]')).toHaveValue('');
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

test('tags excluded: Retired and Shireposting by default, taken off to show them', async ({ page }) => {
  await page.goto('/decklists');
  const excluded = page.locator('[data-tag-field=xtags] .rb-tag-pick');
  await expect(excluded).toHaveText([/Retired/, /Shireposting/]);
  // at the default nothing is sent
  expect(page.url()).not.toContain('xtags');
  await Promise.all([page.waitForURL(/xtags/), excluded.first().locator('button').click()]);
  await expect(page.locator('[data-tag-field=xtags] .rb-tag-pick')).toHaveText([/Shireposting/]);
  await Promise.all([page.waitForURL(/xtags%5B%5D=$|xtags\[\]=$/), page.locator('[data-tag-field=xtags] .rb-tag-pick button').click()]);
  await page.reload();
  await expect(page.locator('[data-tag-field=xtags] .rb-tag-pick')).toHaveCount(0);
  // Reset brings the default back
  await page.goto('/decklists');
  await expect(excluded).toHaveCount(2);
});

// Comments as threads (Ketura, 2026-11-11): replies under what they answer, Quote, Cheers and
// fire, Edit with its History, Report, links to a comment, folding, deleting; and avatars
test('comments thread: reply, quote, cheers, edit, history, report, link, delete; avatars', async ({ page, pageAs }) => {
  const voter = await pageAs('voter');
  const first = `Nice list from run ${runId}`;

  // the voter's avatar: a culture, chosen on the profile page
  await voter.goto('/user/profile_edit');
  await voter.locator('#avatar-field [data-thumb-mode="culture"]').click();
  await voter.locator('#avatar-field .rb-lib-culture[data-culture="gandalf"]').click();
  await expect(voter.locator('#avatar-field .rb-chooser-preview .rb-thumb-c-gandalf')).toBeVisible();
  await voter.locator('#avatar-field').locator('xpath=ancestor::form').locator('button[type=submit]').click();
  await expect(voter.locator('#avatar-field .rb-chooser-preview .rb-thumb-c-gandalf')).toBeVisible();

  // the author's: a card, found by name
  await page.goto('/user/profile_edit');
  await waitForCardData(page);
  await page.locator('#avatar-field [data-thumb-mode="card"]').click();
  await page.locator('#avatar-field .rb-chooser-search.tt-input').fill('Gimli, Dwarf of Erebor');
  await page.locator('#avatar-field .tt-suggestion').first().click();
  await expect(page.locator('#avatar-field .rb-chooser-preview .rb-thumb-card img')).toBeVisible();
  await page.locator('#avatar-field').locator('xpath=ancestor::form').locator('button[type=submit]').click();
  await expect(page.locator('#avatar-field .rb-chooser-preview .rb-thumb-card img')).toBeVisible();

  // the author replies to the voter's comment: it goes under it, a level in
  await page.goto(decklistUrl);
  const top = page.locator('.rb-cmt-list > .rb-cmt').filter({ has: page.locator('.rb-cmt-text', { hasText: first }) });
  await expect(top.locator('> .rb-cmt-body .rb-avatar .rb-thumb-c-gandalf')).toBeVisible();
  await top.locator('> .rb-cmt-body [data-act="reply"]').click();
  const replyText = `Thanks, from the author ${runId}`;
  await top.locator('> .rb-comment-form.is-reply textarea').fill(replyText);
  await top.locator('> .rb-comment-form.is-reply button[type=submit]').click();
  const reply = top.locator('> .rb-cmt-replies > .rb-cmt');
  await expect(reply.locator('.rb-cmt-text')).toHaveText(replyText);
  await expect(reply.locator('> .rb-cmt-body .rb-avatar .rb-thumb-card img')).toBeVisible();
  await expect(page.locator('.rb-comments-count')).toHaveText('2 Comments');

  // Cheers on the voter's comment, then the fire it unlocks; not on your own
  const cheer = top.locator('> .rb-cmt-body [data-act="cheer"]'), fire = top.locator('> .rb-cmt-body [data-act="fire"]');
  await expect(fire).toHaveClass(/is-tucked/);
  await cheer.click();
  await expect(cheer.locator('.num')).toHaveText('1');
  await expect(fire).not.toHaveClass(/is-tucked/);
  await fire.click();
  await expect(fire.locator('.num')).toHaveText('1');
  await expect(fire).toHaveAttribute('aria-pressed', 'true');
  await expect(reply.locator('> .rb-cmt-body [data-act="cheer"]')).toBeDisabled();

  // the author reports it; it waits in the table for the moderators
  await top.locator('> .rb-cmt-body .rb-cmt-more > button').click();
  await top.locator('> .rb-cmt-body [data-act="report"]').click();
  await page.locator('#rb-cmt-modal textarea').fill('Testing reports');
  await page.locator('#rb-cmt-modal .btn-primary').click();
  await expect(page.locator('#rb-cmt-modal .modal-body')).toContainText('moderators will look');
  await page.locator('#rb-cmt-modal [data-dismiss="modal"]').last().click();
  await expect(page.locator('#rb-cmt-modal')).toBeHidden();
  const topId = await top.getAttribute('data-id');
  expect(appConsole(['dbal:run-sql', `SELECT reason FROM comment_report WHERE comment_id = ${topId}`])).toContain('Testing reports');
  await snap(page, 'a reply, cheers and fire');

  // the voter quotes the reply: the quote starts the box, and the answer goes a level further in
  await voter.goto(decklistUrl);
  const vTop = voter.locator(`#comment-${topId}`);
  const vReply = vTop.locator('> .rb-cmt-replies > .rb-cmt');
  await vReply.locator('> .rb-cmt-body [data-act="quote"]').click();
  const quoteBox = vReply.locator('> .rb-comment-form.is-reply textarea');
  await expect(quoteBox).toHaveValue(new RegExp(`^> ${replyText}`));
  await quoteBox.press('End');
  await quoteBox.pressSequentially('Quoting you back');
  await vReply.locator('> .rb-comment-form.is-reply button[type=submit]').click();
  const deep = vReply.locator('> .rb-cmt-replies > .rb-cmt');
  await expect(deep.locator('.rb-cmt-text blockquote')).toContainText(replyText);
  await expect(deep).toHaveAttribute('data-depth', '2');

  // the voter edits their first comment: the time says "edited", and History has both
  await vTop.locator('> .rb-cmt-body .rb-cmt-more > button').click();
  await vTop.locator('> .rb-cmt-body [data-act="edit"]').click();
  const editBox = vTop.locator('> .rb-cmt-body .rb-comment-form.is-edit textarea');
  await expect(editBox).toHaveValue(first);
  await editBox.fill(`${first} (edited)`);
  await vTop.locator('> .rb-cmt-body .rb-comment-form.is-edit button[type=submit]').click();
  await expect(vTop.locator('> .rb-cmt-body .rb-cmt-text')).toHaveText(`${first} (edited)`);
  await expect(vTop.locator('> .rb-cmt-body .rb-cmt-time')).toContainText('edited');
  await vTop.locator('> .rb-cmt-body .rb-cmt-more > button').click();
  await vTop.locator('> .rb-cmt-body [data-act="history"]').click();
  await expect(voter.locator('#rb-cmt-modal .rb-cmt-version')).toHaveCount(2);
  await expect(voter.locator('#rb-cmt-modal .rb-cmt-version').last()).toContainText(first);
  await snap(voter, 'a comment history');
  await expect(voter.locator('#rb-cmt-modal')).toHaveClass(/\bin\b/);
  await expect(voter.locator('#rb-cmt-modal')).toBeFocused();
  await voter.keyboard.press('Escape');
  await expect(voter.locator('#rb-cmt-modal')).toBeHidden();

  // folding the thread by its line, and opening it again
  await vTop.locator('> .rb-cmt-replies > .rb-cmt-line').click();
  await expect(vReply).toBeHidden();
  await vTop.locator('> .rb-cmt-unfold-thread').click();
  await expect(vReply).toBeVisible();

  // a link to the quote reply opens on it, lit; its time is that link
  const deepId = await deep.getAttribute('data-id');
  await vTop.locator('> .rb-cmt-replies > .rb-cmt-line').click();
  await voter.goto(`${decklistUrl}#comment-${deepId}`);
  await voter.reload();
  await expect(voter.locator(`#comment-${deepId}`)).toHaveClass(/is-target/);
  await expect(voter.locator(`#comment-${deepId}`)).toBeInViewport();
  await expect(voter.locator(`#comment-${deepId} > .rb-cmt-body .rb-cmt-time`)).toHaveAttribute('href', new RegExp(`#comment-${deepId}$`));

  // deleting: the first comment, with replies under it, keeps its place; the leaf goes
  voter.on('dialog', (d) => d.accept());
  await vTop.locator('> .rb-cmt-body .rb-cmt-more > button').click();
  await vTop.locator('> .rb-cmt-body [data-act="delete"]').click();
  await expect(vTop).toHaveClass(/is-deleted/);
  await expect(vTop.locator('> .rb-cmt-body')).toContainText('Comment deleted');
  await expect(vReply.locator('> .rb-cmt-body .rb-cmt-text')).toHaveText(replyText);
  await expect(voter.locator('.rb-comments-count')).toHaveText('2 Comments');
  await deep.locator('> .rb-cmt-body .rb-cmt-more > button').click();
  await deep.locator('> .rb-cmt-body [data-act="delete"]').click();
  await expect(deep).toHaveCount(0);
  await expect(voter.locator('.rb-comments-count')).toHaveText('1 Comment');
  // kept on a fresh load: the deleted one stands over the author's reply
  await voter.reload();
  await expect(voter.locator(`#comment-${topId}`)).toHaveClass(/is-deleted/);
  await expect(voter.locator(`#comment-${topId} > .rb-cmt-replies > .rb-cmt .rb-cmt-text`)).toHaveText(replyText);
  await snap(voter, 'a deleted comment keeps its place');
  // and someone else can't edit or delete another's
  await expect(voter.locator(`#comment-${topId} > .rb-cmt-replies > .rb-cmt > .rb-cmt-body li.rb-cmt-own`).first()).toHaveAttribute('hidden', '');
});

// A comment from before the markdown was kept has only its html (Ketura, 2026-11-11): nothing
// is made back from that, so it can't be edited, and Quote takes it as it reads. And a page
// whose token has expired gets a fresh one and sends again, without the viewer reloading.
test('comments: an old comment, and a page left open past its token', async ({ pageAs }) => {
  const voterName = loadUsers().voter.username;
  appConsole(['dbal:run-sql', `INSERT INTO comment (user_id, published_deck_id, text, date_creation, is_hidden) SELECT id, ${decklistId}, '<p>From <strong>before</strong> markdown was kept</p>', NOW(), 0 FROM user WHERE username = '${voterName}'`]);
  const voter = await pageAs('voter');
  await voter.goto(decklistUrl);
  const old = voter.locator('.rb-cmt[data-legacy]').filter({ hasText: 'markdown was kept' });
  await expect(old).toHaveCount(1);
  await expect(old.locator('> .rb-cmt-body [data-act="quote"]')).toBeVisible();
  await expect(old.locator('> .rb-cmt-body li.rb-cmt-editor')).toHaveAttribute('hidden', '');
  await expect(old.locator('> .rb-cmt-body li.rb-cmt-own').last()).not.toHaveAttribute('hidden', '');
  await old.locator('> .rb-cmt-body [data-act="quote"]').click();
  await expect(old.locator('> .rb-comment-form.is-reply textarea')).toHaveValue(/^> From before markdown was kept\n/);
  await old.locator('> .rb-comment-form.is-reply .rb-comment-cancel').click();

  // the first post meets an expired token: the page fetches a new one and posts again
  let refused = false;
  await voter.route('**/comment/post', async (route) => {
    if (!refused) {
      refused = true;
      return route.fulfill({ status: 400, headers: { 'X-Comment-Error': 'expired', 'Content-Type': 'application/json' }, body: '{"message":"The page has expired."}' });
    }
    return route.continue();
  });
  const text = `Sent after the token expired ${runId}`;
  await voter.locator('.rb-comment-form.is-main textarea').fill(text);
  await Promise.all([
    voter.waitForResponse((r) => r.url().includes('/comment/state')),
    voter.locator('.rb-comment-form.is-main button[type=submit]').click(),
  ]);
  await expect(voter.locator('.rb-cmt-text').filter({ hasText: text })).toBeVisible();
  expect(refused).toBe(true);
});
