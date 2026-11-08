import fs from 'fs';
import path from 'path';
import { Page } from '@playwright/test';
import { test, expect, runId, waitForCardData, snap } from '../helpers/test';
import { loadUsers, storageFor } from '../helpers/users';
import { appConsole } from '../helpers/console';

// Archived decks (phase3/06; historic accounts, Ketura 2026-11-02): import the
// fixture batch; each author becomes a historic account and each deck waits in the
// historians' review queue; a historian verifies one in the deckbuilder; a user
// claims the author's account and an admin approves, merging it into theirs.

test.describe.configure({ mode: 'serial' });
test.use({ storageState: storageFor('voter') });

let decklistUrl = '';
// The fixture batch, made unique to this run so each run imports fresh decklists
const author = `FixtureAuthor${runId}`;
const batch = fs
  .readFileSync(path.join(__dirname, '..', '..', 'fixtures', 'seed-batch.json'), 'utf8')
  .replace(/"fixture-(\d)"/g, `"fixture-$1-${runId}"`)
  .replace(/FixtureAuthor/g, author);

test('a seed batch imports as archived decklists', async ({ page }) => {
  try {
    appConsole(['app:import:seed-decks', '-'], batch);
  } catch (e) {
    test.skip(true, 'could not run the importer through docker exec');
  }
  await page.goto(`/decklists?author=${author}`);
  const link = page.getByRole('link', { name: 'Fixture Elves and Moria' }).first();
  await expect(link).toBeVisible();
  await link.click();
  decklistUrl = page.url();
  // the archive's details sit on the reader's Details tab (Ketura, 2026-11-04)
  await page.locator('a[href="#tab-pane-details"]').click();
  await expect(page.locator('.decklist-source')).toContainText(author);
  await expect(page.locator('.decklist-source')).toContainText('2x Gandalfs Pipe');
  await expect(page.locator('.decklist-source')).toContainText('999_1');
  // the original sites are gone: only the Wayback copy is linked
  await expect(page.locator('.decklist-source').getByRole('link', { name: 'archived copy' })).toBeVisible();
  await expect(page.locator('.decklist-source').getByRole('link', { name: 'original post' })).toHaveCount(0);
  await expect(page.locator('.decklist-plays')).toContainText('Fixture Regionals');
  // brought in, not published: "Archived", and the thread's comments above the site's own
  await expect(page.locator('.rb-reader-meta')).toContainText('Archived');
  await expect(page.locator('.rb-reader-comments .rb-archive-comments')).toContainText('Imported Archive Comment');
  await expect(page.locator('.rb-archive-comments .comment-author')).toHaveText('Commenter');
  await expect(page.locator('.rb-archive-comments .comment-text')).toHaveText('Nice deck');
  // owned by the author's historic account, waiting for a historian
  await expect(page.locator('.rb-reader-author')).toContainText(author);
  await expect(page.locator('.rb-reader-author .rb-user-historic')).toBeVisible();
  // the note sits atop the Details tab (Ketura, 2026-11-08), and the commentary says who posted it
  await expect(page.locator('#tab-pane-details > .rb-review-note')).toContainText("hasn't been checked");
  await expect(page.locator('.rb-reader-posted')).toHaveText(`Posted by ${author}`);
  await snap(page, 'archived decklist');

  // listed by default, whole or not (Ketura, 2026-11-05), but not among the decks Gemp accepts
  await page.goto(`/decklists?author=${author}&legal=1`);
  await expect(page.getByRole('link', { name: 'Fixture Elves and Moria' })).toHaveCount(0);
});

/** The author account, promoted to admin (and so historian), logged in afresh */
async function adminPage(pageAs: (role: 'author') => Promise<Page>): Promise<Page> {
  const users = loadUsers();
  appConsole(['fos:user:promote', users.author.username, 'ROLE_ADMIN']);
  const admin = await pageAs('author');
  // the role is read at login
  await admin.goto('/logout');
  await admin.goto('/login');
  await admin.fill('#username', users.author.username);
  await admin.fill('#password', users.author.password);
  await Promise.all([admin.waitForURL((u) => !u.pathname.startsWith('/login')), admin.click('#_submit')]);
  return admin;
}

test('a historian verifies it from the review queue', async ({ pageAs }) => {
  const admin = await adminPage(pageAs);
  await admin.goto(`/historian/review?author=${author}`);
  const todo = admin.locator('.rb-queue-col').first();
  await expect(todo.locator('.rb-queue-row', { hasText: 'Fixture Elves and Moria' })).toBeVisible();
  await expect(todo.locator('.rb-queue-row', { hasText: 'Fixture Elves and Moria' }).locator('.rb-flag-unresolved')).toBeVisible();
  await snap(admin, 'review queue');

  // the deck opens in the deckbuilder, in a new tab
  const [builder] = await Promise.all([
    admin.context().waitForEvent('page'),
    todo.getByRole('link', { name: 'Fixture Elves and Moria' }).click(),
  ]);
  await builder.waitForLoadState();
  await waitForCardData(builder);
  // not the author's deck: no gate in the way (Ketura, 2026-11-08)
  await expect(builder.locator('#published-gate')).toHaveCount(0);

  // a plain Save carries over to the archive decklist, and the history says who and what
  // (Ketura, 2026-11-08)
  await builder.locator('a[href="#tab-pane-infos"]').click();
  await builder.fill('#starting-bid', '3');
  await builder.click('#btn-save');
  await expect(builder.locator('#btn-save')).toBeDisabled();
  await builder.reload();
  await waitForCardData(builder);
  await builder.click('#tab-header-history');
  const edit = builder.locator('.rb-history-edit').first();
  await expect(edit).toContainText('Edited by');
  await expect(edit).toContainText('bid');
  await expect(edit).toContainText('Archive decklist updated');
  const reader = await admin.context().newPage();
  await reader.goto(decklistUrl);
  await expect(reader.locator('.rb-reader-bid')).toContainText('3');
  await reader.close();

  // the review and the historical fields are on the Archive info tab
  await builder.click('#tab-header-archive');
  await expect(builder.locator('#review-status')).toContainText('Not yet checked');
  await expect(builder.locator('#review-flags')).toContainText('lines resolved by a loose rule');
  await expect(builder.locator('.rb-review-original')).toContainText('2x Gandalfs Pipe');
  await expect(builder.locator('.rb-review-guessed')).toContainText('Legolas Greenleaf');
  await expect(builder.locator('[data-historic=author]')).toHaveValue(author);
  await snap(builder, 'historian deckbuilder');
  // an unmatched line's candidate goes into the deck with one click
  const pipe = builder.locator('[data-archive-add="1_74"]');
  await pipe.click();
  await expect(pipe).toHaveClass(/is-added/);
  await expect(builder.locator('#deck')).toContainText('Gandalf’s Pipe');
  await builder.click('#btn-save-verify');
  await expect(builder.locator('#review-status')).toContainText('Verified by');

  await admin.reload();
  const done = admin.locator('.rb-queue-col').nth(1);
  await expect(done.locator('.rb-queue-row', { hasText: 'Fixture Elves and Moria' })).toContainText('Verified by');

  // the published deck says so, and keeps its historic author
  await admin.goto(decklistUrl);
  await expect(admin.locator('.rb-review-note')).toContainText('Checked against the original by');
  await expect(admin.locator('.rb-reader-author')).toContainText(author);
});

test('a user claims the historic account and an admin approves', async ({ page, pageAs }) => {
  await page.goto(decklistUrl);
  await page.getByRole('link', { name: 'Claim This Account' }).click();
  await expect(page.locator('h1')).toContainText(author);
  await page.fill('#message', `I was ${author}`);
  await page.locator('form button[type=submit].btn-primary').click();
  await expect(page.locator('body')).toContainText('Your request has been sent');

  const users = loadUsers();
  const admin = await adminPage(pageAs);
  await admin.goto('/admin/claims');
  const row = admin.locator('tr', { hasText: users.voter.username }).filter({ hasText: author });
  await snap(admin, 'claims queue');
  await row.getByRole('button', { name: 'Approve' }).click();

  // the decks are the user's now, and the historic name is an alias on their profile
  await page.goto(decklistUrl);
  await expect(page.locator('.rb-reader-author')).toContainText(users.voter.username);
  await expect(page.locator('.rb-reader-author .rb-user-historic')).toHaveCount(0);
  await snap(page, 'claimed decklist');
  await page.locator('.rb-reader-author a.username').click();
  await expect(page.locator('.jumbotron')).toContainText(author);
});
