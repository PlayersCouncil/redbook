import { test, expect, login, snap } from '../helpers/test';
import { waitForMail, findLink } from '../helpers/mailpit';
import { loadUsers } from '../helpers/users';

// Logging in and out, and the password reset email. Uses the author account
// registered by auth.setup.ts, from a fresh (logged-out) browser.

test('log in and log out', async ({ page }) => {
  const { author } = loadUsers();
  await login(page, author.username, author.password);
  // Pages that need a login now open; decks are drafts at /drafts/decks (Ketura, 2026-11-11),
  // and the old address leads there
  const res = await page.goto('/decks');
  expect(res?.status()).toBe(200);
  await expect(page).toHaveURL(/\/drafts\/decks$/);
  await expect(page).toHaveTitle(/My Deck Drafts/);
  await snap(page, 'logged in: my deck drafts');

  // logged in: the account as its username, My Drafts and New Draft; the account menu: My
  // Account, (Admin), Logout
  const user = page.locator('header.rb-topbar .rb-nav-user > li:visible > a');
  await expect(user).toHaveCount(4);
  for (const [i, title] of ['Notifications (Not built yet)', 'Account', 'My Drafts', 'New Draft'].entries()) {
    await expect(user.nth(i)).toHaveAttribute('title', title);
  }
  await expect(page.locator('#login > a .rb-account-name')).toHaveText(author.username);
  await page.locator('#nav-drafts').click();
  await expect(page.locator('#nav-drafts + .dropdown-menu li')).toHaveText(['My Deck Drafts', 'My Library Drafts', 'My Event Drafts', 'My Post Drafts']);
  await expect(page.locator('#nav-drafts + .dropdown-menu li.disabled')).toHaveCount(2);
  await page.locator('#nav-new').click();
  await expect(page.locator('#nav-new + .dropdown-menu li')).toHaveText(['New Deck', 'Import Deck', 'New Library', 'New Event', 'New Post']);
  await Promise.all([page.waitForURL(/\/library\/new/), page.locator('#nav-new + .dropdown-menu a', { hasText: 'New Library' }).click()]);
  await page.locator('#login > a').click();
  await expect(page.locator('#login .dropdown-menu a').first()).toHaveText('My Account');
  await Promise.all([page.waitForURL((u) => !u.pathname.startsWith('/library')), page.locator('#login .dropdown-menu a', { hasText: 'Logout' }).click()]);
  await page.goto('/drafts/decks');
  await expect(page).toHaveURL(/\/login/);
  // logged out: a Login or Register button
  await page.goto('/decklists');
  await expect(page.locator('#login .rb-login-btn')).toHaveText('Login or Register');
  await expect(page.locator('#login .rb-login-btn')).toHaveAttribute('href', /\/login$/);
});

test('a wrong password is refused', async ({ page }) => {
  const { author } = loadUsers();
  await page.goto('/login');
  await page.fill('#username', author.username);
  await page.fill('#password', 'not-the-password');
  await page.click('#_submit');
  await expect(page).toHaveURL(/\/login/);
  await expect(page.locator('.alert-danger, .alert-error')).toBeVisible();
  await snap(page, 'wrong password refused');
});

test('password reset sends an email with a working link', async ({ page }) => {
  const { voter } = loadUsers();
  await page.goto('/resetting/request');
  await page.fill('#username', voter.username);
  await page.click('form[action*="resetting/send-email"] [type=submit]');
  await expect(page).toHaveURL(/\/resetting\/check-email/);

  const mail = await waitForMail(voter.email, /reset/i);
  const resetUrl = findLink(mail.text, '/resetting/reset/');
  await page.goto(resetUrl);
  await expect(page.locator('#fos_user_resetting_form_plainPassword_first')).toBeVisible();
  await snap(page, 'reset link opens the new-password form');
});
