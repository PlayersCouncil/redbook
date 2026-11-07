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
  await page.click('form[action$="/deck/fileimport"] button[type=submit]');
  await expect(page).toHaveURL(/\/deck\/edit\//);
  await waitForCardData(page);
  deckUuid = page.url().match(/\/deck\/edit\/([0-9a-f-]{36})/)![1];

  // never published: a new decklist
  await page.goto(`/deck/publish/${deckUuid}`);
  await expect(page.locator('#previous_version')).toHaveValue('');
  await expect(page.locator('#version-line')).toHaveText('A deck of its own: version 1 of a new line.');
  v1 = await publish(page, `${lineName} one`);
  await expect(page.locator('.rb-reader-version')).toHaveText('v1');

  // published before: version 2 of it, which can be cleared to stand alone
  await page.goto(`/deck/publish/${deckUuid}`);
  await expect(page.locator('#previous_version')).toHaveValue(v1.id);
  await expect(page.locator('#version-line')).toHaveText('Publishing version 2 of:');
  // the version it follows, as the derived-from pick shows: name, author, date, version
  await expect(page.locator('#version-card .rb-derived-name')).toHaveText(`${lineName} one`);
  await expect(page.locator('#version-card .rb-derived-meta')).toContainText('v1');
  await page.selectOption('#previous_version', '');
  await expect(page.locator('#version-line')).toHaveText('A deck of its own: version 1 of a new line.');
  await page.selectOption('#previous_version', v1.id);
  await snap(page, 'publishing version 2');
  v2 = await publish(page, `${lineName} two`);

  // each version is its own decklist; the menu lists the line, and the older one points on
  await page.locator('#dropdownVersions').click();
  await expect(page.locator('#dropdownVersions')).toContainText('v2');
  await expect(page.locator('.rb-reader-versions .dropdown-menu a')).toHaveText([`v2 · ${lineName} two`, `v1 · ${lineName} one`]);
  await page.goto(v1.url);
  await expect(page.locator('.rb-newer-note')).toContainText('v2');
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
  await expect(voter.locator('.rb-reader-version')).toHaveText('v1');
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
  await expect(voter.locator('.rb-newer-note')).toContainText('v2');
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
  await expect(page.locator('#derived-chosen')).toContainText('v2');
  await expect(page.locator('#precedent')).toHaveValue(v2.id);
  await snap(page, 'derived-from picked');
  await page.click('.rb-form-actions button[form=decklist-form]:has-text("Save")');
  await expect(page.locator('#table-predecessor')).toContainText(`${lineName} two`);

  // clear it, then paste an address: picked straight away
  await page.goto(`/decklist/edit/${v1.id}`);
  await page.click('#derived-clear');
  await expect(page.locator('#precedent')).toHaveValue('');
  await page.fill('#derived-name', v2.url);
  await expect(page.locator('#derived-chosen')).toBeVisible();
  await expect(page.locator('#precedent')).toHaveValue(v2.id);
});
