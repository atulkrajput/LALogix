import { test, expect } from '@playwright/test';

test.describe('Blog Navigation', () => {
  test('blog listing page loads with posts', async ({ page }) => {
    await page.goto('/insights');
    await expect(page).toHaveTitle(/Insights/i);

    // Should have at least one blog post card
    const postLinks = page.locator('a[href^="/insights/"]');
    await expect(postLinks.first()).toBeVisible();
  });

  test('navigate from blog listing to blog post', async ({ page }) => {
    await page.goto('/insights');

    // Click the first blog post link
    const firstPost = page.locator('a[href^="/insights/"]').first();
    await firstPost.click();

    // Should be on a blog post page
    await expect(page).toHaveURL(/\/insights\/.+/);

    // Blog post should have a title (h1)
    await expect(page.locator('h1')).toBeVisible();
  });
});
