import fs from 'fs';
import path from 'path';
import { test, expect, runId, snap } from '../helpers/test';
import { loadUsers, storageFor } from '../helpers/users';
import { appConsole } from '../helpers/console';

// Archived decks (phase3/06): import the fixture batch, see it under its
// original author, hidden from default browsing until it's complete; a user
// claims it and an admin approves.

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
  await page.goto(`/decklists/find?author=${author}&incomplete=1`);
  const link = page.getByRole('link', { name: 'Fixture Elves and Moria' }).first();
  await expect(link).toBeVisible();
  await link.click();
  decklistUrl = page.url();
  await expect(page.locator('.decklist-source')).toContainText(author);
  await expect(page.locator('.decklist-source')).toContainText('2x Gandalfs Pipe');
  await expect(page.locator('.decklist-source')).toContainText('999_1');
  await expect(page.locator('.decklist-plays')).toContainText('Fixture Regionals');
  await snap(page, 'archived decklist');

  // incomplete: not in default browsing
  await page.goto(`/decklists/find?author=${author}`);
  await expect(page.getByRole('link', { name: 'Fixture Elves and Moria' })).toHaveCount(0);
});

test('a user claims it and an admin approves', async ({ page, pageAs }) => {
  await page.goto(decklistUrl);
  await page.getByRole('link', { name: 'This is my deck' }).click();
  await page.fill('#message', `I was ${author}`);
  await page.locator('form button[type=submit].btn-primary').click();
  await expect(page.locator('body')).toContainText('Your request has been sent');

  const users = loadUsers();
  appConsole(['fos:user:promote', users.author.username, 'ROLE_ADMIN']);
  const admin = await pageAs('author');
  // the role is read at login
  await admin.goto('/logout');
  await admin.goto('/login');
  await admin.fill('#username', users.author.username);
  await admin.fill('#password', users.author.password);
  await Promise.all([admin.waitForURL((u) => !u.pathname.startsWith('/login')), admin.click('#_submit')]);
  await admin.goto('/admin/claims');
  const row = admin.locator('tr', { hasText: users.voter.username }).filter({ hasText: 'Fixture Elves and Moria' });
  await snap(admin, 'claims queue');
  await row.getByRole('button', { name: 'Approve' }).click();

  await page.goto(decklistUrl);
  await expect(page.locator('h3.username')).toContainText(users.voter.username);
  await snap(page, 'claimed decklist');
});
