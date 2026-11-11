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
  // the deck's page is the decklist reader (Ketura, 2026-11-08): its problems on Details
  await expect(page.locator('h1')).toContainText(deckName);
  await page.locator('a[href="#tab-pane-details"]').click();
  await expect(page.locator('.deck-validation')).toContainText('below minimum number of cards');
  await snap(page, 'saved deck');

  await page.click('.rb-reader-buttons .deck-delete');
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
  await page.click('#new-deck-submit');
  await expect(page).toHaveURL(/\/deck\/edit\//);
  await waitForCardData(page);
  await expect(page.locator('#deck-validation')).toContainText('Deck is valid for Fellowship Block');
  await page.goto(await page.evaluate(() => (window as any).app.deckbuilder_page.view_url));
  await expect(page.locator('.rb-reader-meta .rb-draw-total')).toHaveText('Draw Deck (60)');
  await expect(page.locator('.rb-reader-buttons .deck-edit')).toBeVisible();
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

  // the view buttons sit above the deck's scrolling area, with the draw deck's size; "Ring-bearer
  // and Sites" heads the deck and collapses its top part away entirely (Ketura, 2026-11-08)
  await page.locator('#deck-toolbar [data-view=thumb]').click();
  await expect(page.locator('#deck-toolbar .rb-draw-total')).toHaveText('Draw Deck (3)');
  await expect(page.locator('#deck .rb-deck-head')).toHaveText('Ring-bearer and Sites');
  await page.locator('#deck .rb-deck-head .rb-section-toggle').click();
  await expect(page.locator('#deck .rb-deck-top')).toHaveCount(0);
  await expect(page.locator('#deck .rb-deck-head')).toHaveClass(/is-collapsed/);
  await snap(page, 'collapsed deck top');
  await page.locator('#deck .rb-deck-head .rb-section-toggle').click();
  await expect(page.locator('#deck .rb-deck-top')).toBeVisible();
  // Free Peoples collapses away entirely, and back
  await page.locator('#deck .rb-side-free_peoples .rb-section-toggle').click();
  await expect(page.locator('#deck .rb-side-free_peoples .rb-tile')).toHaveCount(0);
  await page.locator('#deck .rb-side-free_peoples .rb-section-toggle').click();
  await expect(page.locator('#deck .rb-side-free_peoples .rb-tile').first()).toBeVisible();
  await page.locator('#deck-toolbar [data-view=list]').click();

  // the Ring-bearer comes straight out, and its + takes the X's place
  await page.click('[data-pool-view=list]');
  await page.fill('#filter-text', 'frodo');
  await page.locator('.rb-pool-row', { hasText: 'Frodo, Son of Drogo' }).first().locator('[data-rb]').click();
  await expect(page.locator('#deck .rb-slot-ring_bearer')).toContainText('Frodo');
  await expect(page.locator('#deck .rb-slot-ring_bearer .rb-plus')).toHaveCount(0);
  await page.locator('#deck .rb-slot-ring_bearer .rb-x-now').click();
  await expect(page.locator('#deck .rb-slot-ring_bearer')).not.toContainText('Frodo');
  await expect(page.locator('#deck .rb-slot-ring_bearer .rb-slot-empty .rb-plus')).toBeVisible();

  // Quick Search on a desktop: every group at once, one click to choose
  await page.fill('#filter-text', '');
  await page.locator('.rb-more-toggle').click();
  await page.locator('.rb-quick-btn').click();
  await expect(page.locator('.rb-quick-panel .rb-quick-group')).toHaveCount(14);
  await page.locator('.rb-quick-panel .rb-quick-item', { hasText: 'Draw cards' }).click();
  await expect(page.locator('.rb-quick-value')).toHaveText('Draw cards');
  await expect(page.locator('.rb-showing .rb-chip[data-remove=quick]')).toBeVisible();
  await page.locator('.rb-showing .rb-chip[data-remove=quick]').click();
  await expect(page.locator('.rb-quick-value')).toHaveText('Any');

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

test('deck notes, stats and history: starting fellowship, tags, annotations, test draw', async ({ page }) => {
  const deckName = `E2E notes ${runId}`;
  await page.goto('/deck/new');
  await page.selectOption('#target_format', 'fotr_block');
  await page.fill('#name', deckName);
  await page.locator('form[action$="/deck/build"] button[type=submit]').click();
  await expect(page).toHaveURL(/\/deck\/edit\//);
  await waitForCardData(page);

  await pick(page, 'legolas', 'Legolas, Greenleaf', '[data-add]');
  await pick(page, 'gimli', 'Gimli, Dwarf of Erebor', '[data-add]');
  await pick(page, 'goblin runner', 'Goblin Runner', '[data-add]');
  // Legolas starts; the Notes tab lists him with his cost
  await page.locator('#deck [data-start="1_50"]').click();

  await page.click('a[href="#tab-pane-infos"]');
  await expect(page.locator('#starting-groups .rb-starting-list').first()).toContainText('Legolas');
  // + then a companion in the deck starts it too; the other cards are greyed out and
  // can't be picked; x on a pill takes it off again (Ketura, 2026-11-07)
  const line = (code: string) => page.locator(`#deck .rb-line[data-code="${code}"], #deck .rb-tile[data-code="${code}"]`).first();
  await page.locator('#starting-groups .rb-start-pick').first().click();
  await expect(line('1_178')).toHaveClass(/rb-pick-off/);
  await line('1_178').click();
  await expect(page.locator('#starting-groups .rb-start-pick').first()).toHaveAttribute('aria-pressed', 'true');
  await line('1_12').click();
  await expect(page.locator('#starting-groups .rb-starting-group').first().locator('.rb-starting-pill')).toHaveCount(2);
  await expect(page.locator('#deck .rb-pick-off')).toHaveCount(0);
  await page.locator('#starting-groups [data-unstart="1_12"]').click();
  await expect(page.locator('#starting-groups .rb-starting-group').first().locator('.rb-starting-pill')).toHaveCount(1);
  // several starting fellowships, each named (Ketura, 2026-11-11): the first is "Starting
  // Fellowship" until named; a new row is numbered, and is where + and S go once clicked
  const groups = page.locator('#starting-groups .rb-starting-group');
  await expect(groups).toHaveCount(1);
  await expect(groups.first().locator('.rb-starting-name')).toHaveAttribute('placeholder', 'Starting Fellowship');
  await expect(groups.first().locator('.rb-starting-drop')).toBeHidden();
  await page.click('#starting-add');
  await expect(groups).toHaveCount(2);
  await expect(groups.nth(1).locator('.rb-starting-name')).toHaveAttribute('placeholder', 'Starting Fellowship 2');
  await expect(groups.nth(1)).toHaveClass(/is-active/);
  await page.keyboard.type('Going second');
  // S in the deck list goes to the active row; Legolas (the first's) sorts before Gimli (the second's)
  await page.locator('#deck [data-start="1_12"]').click();
  await expect(groups.nth(1).locator('.rb-starting-pill')).toHaveText(/Gimli/);
  // (the list sorts by culture first, so the rank is checked rather than the order)
  expect(await page.evaluate(() => (window as any).app.deck.starting_rank('1_50'))).toBe(0);
  expect(await page.evaluate(() => (window as any).app.deck.starting_rank('1_12'))).toBe(2);
  expect(await page.evaluate(() => (window as any).app.deck.starting_rank('1_178'))).toBeGreaterThan(2);
  // the rows reorder by their handles, and a row's companions by dragging them, in the order
  // they're played (Ketura, 2026-11-11)
  const dragTo = async (from: any, to: any, below = false) => {
    const a = (await from.boundingBox())!, b = (await to.boundingBox())!;
    await page.mouse.move(a.x + a.width / 2, a.y + a.height / 2);
    await page.mouse.down();
    await page.mouse.move(a.x + a.width / 2 + 8, a.y + a.height / 2 + 2, { steps: 2 });
    await page.mouse.move(b.x + (below ? b.width - 3 : 3), b.y + (below ? b.height - 3 : 3), { steps: 6 });
    await page.mouse.up();
  };
  await dragTo(groups.nth(1).locator('.rb-starting-handle'), groups.nth(0), false);
  await expect(groups.nth(0).locator('.rb-starting-name')).toHaveValue('Going second');
  await expect(groups.nth(0)).toHaveClass(/is-active/);
  await page.locator('#deck [data-start="1_50"]').click();
  const pills = groups.nth(0).locator('.rb-starting-pill');
  await expect(pills).toHaveText([/Gimli/, /Legolas/]);
  await dragTo(pills.nth(1), pills.nth(0), false);
  await expect(pills).toHaveText([/Legolas/, /Gimli/]);
  expect(await page.evaluate(() => (window as any).app.deck.get_starting_groups()[0].codes)).toEqual(['1_50', '1_12']);
  // back as it was for what follows: Going second second, with Gimli only
  await page.locator('#starting-groups [data-unstart="1_50"]').first().click();
  await dragTo(groups.nth(0).locator('.rb-starting-handle'), groups.nth(1), true);
  await expect(groups.nth(1).locator('.rb-starting-name')).toHaveValue('Going second');
  await expect(groups.nth(1).locator('.rb-starting-pill')).toHaveText([/Gimli/]);
  // a long bid fits (Ketura, 2026-11-11)
  await page.fill('#starting-bid', '1 if going first, otherwise 4 if they bid high');
  await page.click('#btn-save');
  await expect(page.locator('#save-status')).toContainText('Saved as version');
  await page.reload();
  await waitForCardData(page);
  await page.click('a[href="#tab-pane-infos"]');
  await expect(groups).toHaveCount(2);
  await expect(groups.nth(1).locator('.rb-starting-name')).toHaveValue('Going second');
  await expect(groups.nth(1).locator('.rb-starting-pill')).toHaveText(/Gimli/);
  await expect(page.locator('#starting-bid')).toHaveValue('1 if going first, otherwise 4 if they bid high');
  // − takes the row away
  await groups.nth(1).locator('.rb-starting-drop').click();
  await expect(groups).toHaveCount(1);
  await expect(groups.first().locator('.rb-starting-pill')).toHaveText(/Legolas/);
  await page.fill('#starting-bid', '2-3');
  await page.fill('#starting-notes', 'Mulligan without **Gimli**');
  // the markdown editor's Preview (Ketura, 2026-11-07), shared with the decklist form
  const start = page.locator('.rb-notes-start');
  await start.locator('button[data-handler="bootstrap-markdown-cmdPreview"]').click();
  await expect(start.locator('.md-preview strong')).toHaveText('Gimli');
  await start.locator('button[data-handler="bootstrap-markdown-cmdPreview"]').click();
  // [[card]] links in the preview, the same forms the server resolves
  await page.fill('#description', 'Like [[HTTWC]], [[3R38]] and [[3_38.1]]; `[[3_38]]` stays code.');
  const desc = page.locator('.rb-notes-desc');
  // Preview is blue while it's on (Ketura, 2026-11-07)
  const preview = desc.locator('button[data-handler="bootstrap-markdown-cmdPreview"]');
  await preview.click();
  await expect(preview).toHaveClass(/active/);
  await expect(desc.locator('.md-preview a.rb-cardlink[data-code="3_38"]')).toHaveCount(2);
  await expect(desc.locator('.md-preview a.rb-cardlink[href$="/card/53_38#v1"]')).toHaveCount(1);
  await expect(desc.locator('.md-preview code')).toHaveText('[[3_38]]');
  await preview.click();
  await expect(preview).not.toHaveClass(/active/);
  // room to scroll the last line up to the top
  expect(await page.locator('#description').evaluate((el) => parseFloat(getComputedStyle(el).paddingBottom))).toBeGreaterThan(40);
  // the card button types a #, which opens the card list straight away, the deck's cards first
  await desc.locator('button[data-handler="bootstrap-markdown-cmdCard"]').click();
  await expect(page.locator('#description')).toHaveValue(/ #$/);
  await expect(page.locator('body > ul.dropdown-menu li.textcomplete-item:visible').first()).toBeVisible();
  await page.keyboard.press('Escape');
  // the symbol button: cultures, blocks and twilight; a pick goes in as text that shows as
  // the icon (Ketura, 2026-11-07)
  await desc.locator('button[data-handler="bootstrap-markdown-cmdSymbol"]').click();
  await expect(desc.locator('.rb-sym-menu [data-insert]')).toHaveCount(30);
  await desc.locator('.rb-sym-menu [data-insert="[Moria]"]').click();
  await expect(page.locator('#description')).toHaveValue(/\[Moria\]$/);
  await preview.click();
  await expect(desc.locator('.md-preview .rb-sym[data-tip="Moria"]')).toHaveCount(1);
  await preview.click();
  // fullscreen: a box headed with the field and the deck, closed by Done (Ketura, 2026-11-07)
  await desc.locator('.md-control-fullscreen').click();
  await expect(desc.locator('.md-editor')).toHaveClass(/md-fullscreen-mode/);
  await expect(desc.locator('.rb-md-title')).toHaveText(/^Editing Notes for E2E/);
  await desc.locator('.rb-md-done button').click();
  await expect(desc.locator('.md-editor')).not.toHaveClass(/md-fullscreen-mode/);
  await page.fill('#tag-entry', 'elves');
  await page.keyboard.press('Enter');
  await expect(page.locator('#tag-input .rb-tag-chip')).toContainText('elves');
  // + Pick from deck, then a card in the deck: annotated, and the deck is untouched
  await page.click('#annot-pick');
  await page.locator('#deck .rb-line[data-code="1_178"], #deck .rb-tile[data-code="1_178"]').first().click();
  await expect(page.locator('#annot-list .rb-annot[data-code="1_178"]')).toBeVisible();
  expect(await page.evaluate(() => (window as any).app.deck.get_content()['1_178'])).toBe(1);
  await page.locator('#annot-list [data-unannotate="1_178"]').click();
  // a note on a card that isn't in the deck; one letter is enough to search, names
  // beginning with it come first, the match in bold (Ketura, 2026-11-07)
  await page.fill('#annot-search', 'g');
  await expect(page.locator('.rb-annot-suggest [data-annotate] b').first()).toHaveText('G');
  await page.fill('#annot-search', 'the one ring, the ruling');
  await page.locator('.rb-annot-suggest [data-annotate]').first().click();
  await page.locator('#annot-list textarea').fill('Cut for the Isildur\'s Bane ring');
  // its own fullscreen, as the editors have (Ketura, 2026-11-07)
  await page.locator('#annot-list [data-annot-full]').click();
  await expect(page.locator('#annot-list .rb-annot')).toHaveClass(/is-full/);
  await expect(page.locator('#annot-list .rb-annot-title')).toHaveText(/^Editing Notes on The One Ring/);
  await page.locator('#annot-list [data-annot-done]').click();
  await expect(page.locator('#annot-list .rb-annot')).not.toHaveClass(/is-full/);
  // not in the deck: an asterisk after the name
  await expect(page.locator('#annot-list .rb-annot-out')).toBeVisible();

  await page.click('#btn-save');
  await expect(page.locator('#save-status')).toContainText('Saved as version');
  await page.reload();
  await waitForCardData(page);
  await page.click('a[href="#tab-pane-infos"]');
  await expect(page.locator('#starting-bid')).toHaveValue('2-3');
  await expect(page.locator('#starting-notes')).toHaveValue('Mulligan without **Gimli**');
  await expect(page.locator('#tag-input .rb-tag-chip')).toContainText('elves');
  await expect(page.locator('#annot-list textarea')).toHaveValue('Cut for the Isildur\'s Bane ring');

  // the test draw leaves the starting fellowship out of the draw deck
  await page.click('#tab-header-stats');
  await page.locator('[data-draw=opening]').click();
  await expect(page.locator('#draw-hand .rb-draw-card')).toHaveCount(2);
  await expect(page.locator('#draw-hand .rb-draw-group.is-fp .rb-draw-card')).toHaveCount(1);
  await expect(page.locator('#draw-hand .rb-draw-group.is-shadow .rb-draw-card')).toHaveCount(1);
  await expect(page.locator('#stats-chart-runout svg')).toBeVisible();
  // the cultures as a pie, halves for the sides (Ketura, 2026-11-08)
  await expect(page.locator('#stats-chart-cultures .highcharts-series-group > .highcharts-series')).toHaveCount(2);
  // a twilight row lights up under the pointer, with its bars' counts
  const twilight = () => page.evaluate(() => {
    const chart = (window as any).jQuery('#stats-chart-twilight').highcharts();
    const box = chart.container.getBoundingClientRect();
    return {x: box.left + chart.plotLeft + chart.plotWidth / 2, y: box.top + chart.plotTop + 8, bands: chart.xAxis[0].plotLinesAndBands.length};
  });
  const at = await twilight();
  await page.mouse.move(at.x, at.y);
  await expect.poll(async () => (await twilight()).bands).toBe(1);
  await page.mouse.move(2, 2);
  await expect.poll(async () => (await twilight()).bands).toBe(0);

  await page.click('#tab-header-history');
  // three saves: the starting fellowships' (Ketura, 2026-11-11) came between the others
  await expect(page.locator('#tab-pane-history .rb-history-saved')).toHaveCount(3);
  await expect(page.locator('#tab-pane-history .rb-history-saved').filter({ hasText: 'Goblin Runner' })).toHaveCount(1);

  await page.click('#btn-more');
  await page.locator('#menu-more [data-action=delete]').click();
  await page.locator('#delete-deck-form [type=submit]').click();
  await expect(page).toHaveURL(/\/decks/);
});

test('any version of a card: the Ring-bearer, a site; not a card with one version', async ({ page }) => {
  const [, gemp] = fixture('fellowship-gemp.txt').trim().split('\n');
  await page.goto('/deck/import');
  await page.selectOption('#target_format', 'fotr_block');
  await page.fill('#content', `E2E any ${runId}\n${gemp}`);
  await page.click('#new-deck-submit');
  await expect(page).toHaveURL(/\/deck\/edit\//);
  await waitForCardData(page);
  if (await page.locator('.rb-opt-view[data-view=list][aria-pressed=false]').count()) {
    await page.click('.rb-opt-view[data-view=list]');
  }

  // Any Version, then the Ring-bearer: "Frodo (any)", kept as rb:any: (Ketura, 2026-11-08)
  await page.click('.rb-any-pick');
  await page.locator('.rb-slot-ring_bearer .rb-line').click();
  await expect(page.locator('.rb-slot-ring_bearer .rb-name')).toHaveText('•Frodo (any)');
  expect(await page.evaluate(() => Object.keys((window as any).app.deck.get_content()))).toContain('rb:any:1_290');
  // a site: "Any site 1"
  await page.click('.rb-any-pick');
  await page.locator('.rb-site-list .rb-line', { hasText: 'Bag End' }).click();
  await expect(page.locator('.rb-site-list')).toContainText('Any site 1');
  // a card with no other version in the format can't be
  let message = '';
  page.once('dialog', (dialog) => { message = dialog.message(); dialog.accept(); });
  await page.click('.rb-any-pick');
  await page.locator('#deck .rb-line', { hasText: 'Greed' }).click();
  await expect.poll(() => message).toContain('No other version of Greed is legal in Fellowship Block');
  // Gemp gets the stand-ins, and the deck stays valid
  await expect(page.locator('#deck-validation')).toContainText('Deck is valid for Fellowship Block');

  await page.click('#btn-save');
  await expect(page.locator('#save-status')).toContainText('Saved as version');
  await page.reload();
  await waitForCardData(page);
  await expect(page.locator('.rb-slot-ring_bearer .rb-name')).toHaveText('•Frodo (any)');
  // thumbnails: a generic card split between two versions
  await page.click('.rb-opt-view[data-view=thumb]');
  await expect(page.locator('.rb-slot-ring_bearer .rb-tile.is-generic .rb-tile-half img')).toHaveCount(2);
  const halves = await page.locator('.rb-slot-ring_bearer .rb-tile-half img').evaluateAll((imgs) => imgs.map((i) => (i as HTMLImageElement).src));
  expect(halves[0]).not.toBe(halves[1]);
  await page.click('.rb-opt-view[data-view=list]');

  // the text export and the Gemp string
  const list = await page.evaluate(() => (window as any).app.deck.get_list());
  expect(list).toContain('Ring-bearer: Frodo (any)');
  expect(list).toContain('1x Any site 1');
  const exported = await page.evaluate(() => (window as any).app.deck.get_export('gemp'));
  expect(exported.split('|')[0]).toBe('1_290');

  // picking a generic card makes it that card again
  await page.click('.rb-any-pick');
  await page.locator('.rb-slot-ring_bearer .rb-line').click();
  await expect(page.locator('.rb-slot-ring_bearer .rb-name')).toContainText('Frodo, Son of Drogo');
});

test('the deck\'s thumbnail: picked on the Notes tab from the corner, kept, and shown in My Decks', async ({ page }) => {
  const deckName = `E2E thumb ${runId}`;
  await page.goto('/deck/new');
  await page.selectOption('#target_format', 'fotr_block');
  await page.fill('#name', deckName);
  await page.locator('form[action$="/deck/build"] button[type=submit]').click();
  await expect(page).toHaveURL(/\/deck\/edit\//);
  await waitForCardData(page);
  await pick(page, 'gimli', 'Gimli, Dwarf of Erebor', '[data-add]');
  // the corner shows the cultures until a card is picked (Ketura, 2026-11-09)
  await expect(page.locator('#deck-thumb .rb-thumb-cultures')).toBeVisible();
  // it opens the Notes tab at the picker, whose cards follow the deck
  await page.click('#deck-thumb');
  await expect(page.locator('#tab-pane-infos')).toBeVisible();
  await expect(page.locator('#thumb-select')).toBeFocused();
  await page.click('#thumb-select');
  await page.locator('#thumb-options .rb-thumb-option', { hasText: 'Gimli, Dwarf of Erebor' }).click();
  await expect(page.locator('#deck-thumb img')).toHaveAttribute('src', /LOTR01012/);
  await expect(page.locator('#btn-save')).toBeEnabled();
  await page.click('#btn-save');
  await expect(page.locator('#btn-save')).toBeDisabled();
  await page.reload();
  await waitForCardData(page);
  await expect(page.locator('#thumbnail')).toHaveValue('1_12');
  await expect(page.locator('#deck-thumb img')).toHaveAttribute('src', /LOTR01012/);
  // My Decks shows it
  await page.goto(`/decks?name=${encodeURIComponent(deckName)}`);
  await expect(page.locator('.rb-my-decks-table .rb-thumb img').first()).toHaveAttribute('src', /LOTR01012/);
});

test('New Deck: Fellowship Block first, imports on the same page, the button says which', async ({ page }) => {
  // Import Deck leads to New Deck (Ketura, 2026-11-08)
  await page.goto('/deck/import');
  await expect(page).toHaveURL(/\/deck\/new#import$/);
  await expect(page.locator('#target_format')).toHaveValue('fotr_block');
  await expect(page.locator('.rb-new-private')).toContainText('Decks on Red Book are private by default.');
  const button = page.locator('#new-deck-submit');
  await expect(button).toHaveText('Create New Deck');
  await page.fill('#content', '1x Gimli, Dwarf of Erebor');
  await expect(button).toHaveText('Import Deck');
  // one source at a time
  await page.fill('#redbook', '12345');
  await button.click();
  await expect(page.locator('.alert')).toContainText('Fill in one way to import the deck, not several.');
  // another Red Book deck, by address: a clone, named as given
  const [, gemp] = fixture('fellowship-gemp.txt').trim().split('\n');
  await page.goto('/deck/new');
  await page.fill('#content', `E2E source ${runId}\n${gemp}`);
  await button.click();
  await expect(page).toHaveURL(/\/deck\/edit\//);
  const source = page.url();
  await page.goto('/deck/new');
  await page.fill('#name', `E2E from Red Book ${runId}`);
  await page.fill('#redbook', source);
  await expect(button).toHaveText('Import Deck');
  await button.click();
  await expect(page).toHaveURL(/\/deck\/edit\//);
  expect(page.url()).not.toBe(source);
  await waitForCardData(page);
  await expect(page.locator('#deck-name')).toHaveValue(`E2E from Red Book ${runId}`);
  await expect(page.locator('.rb-draw-total')).toContainText('60');
  // Gemp: only Gemp's own addresses
  await page.goto('/deck/new');
  await page.fill('#gemp_url', 'https://example.com/deck');
  await button.click();
  await expect(page.locator('.alert')).toContainText("Couldn't import from Gemp: not a Gemp address.");

  // Gemp's shared-deck page (the stub serves one recorded from play.lotrtcgpc.net): its name,
  // its notes, and each card as the printing its image shows, the PC errata included
  await page.goto('/deck/new');
  await page.fill('#gemp_url', 'http://gemp-stub:8080/share/deck?id=a2V0dXJhfEdob3N0cyduJ0dvYmxpbnM%3D');
  await expect(button).toHaveText('Import Deck');
  await button.click();
  await expect(page).toHaveURL(/\/deck\/edit\//);
  await waitForCardData(page);
  await expect(page.locator('#deck-name')).toHaveValue("Ghosts'n'Goblins");
  await expect(page.locator('.rb-draw-total')).toContainText('76');
  await expect(page.locator('#deck [data-code="57_359"]').first()).toBeVisible();
  await expect(page.locator('#deck [data-code="102_76"]').first()).toBeVisible();
  // and a library deck's page, named as given
  await page.goto('/deck/new');
  await page.fill('#name', `E2E Gemp library ${runId}`);
  await page.fill('#gemp_url', 'http://gemp-stub:8080/gemp-lotr-server/deck/libraryHtml?deckName=01%20FOTR%20Aragorn%20Starter%20Deck');
  await button.click();
  await expect(page).toHaveURL(/\/deck\/edit\//);
  await waitForCardData(page);
  await expect(page.locator('#deck-name')).toHaveValue(`E2E Gemp library ${runId}`);
  await expect(page.locator('.rb-draw-total')).toContainText('60');
});
