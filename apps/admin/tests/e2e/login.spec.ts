import { test, expect } from '@playwright/test'
import { apiError, gotoReady, mockAuth } from './helpers'

test.describe('login', () => {
  test('successful login redirects to dashboard', async ({ page }) => {
    await mockAuth(page)
    await page.route('**/api/admin/dashboard', (route) =>
      route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          images_count: 10,
          tags_count: 5,
          pending_candidates_count: 2,
          processing_images_count: 1,
          failed_images_count: 0,
        }),
      })
    )

    await gotoReady(page, '/login')
    await page.getByPlaceholder('admin@example.com').fill('admin@example.com')
    await page.getByPlaceholder('••••••••').fill('secret-password')
    await page.getByRole('button', { name: 'Sign in' }).click()

    await page.waitForURL('**/dashboard')
    await expect(page.getByRole('heading', { name: 'Dashboard' })).toBeVisible()
  })

  test('invalid credentials show the contract error message and keep the user on login', async ({ page }) => {
    await page.route('**/sanctum/csrf-cookie', (route) => route.fulfill({ status: 204 }))
    await page.route('**/api/auth/login', (route) =>
      apiError(route, 422, 'INVALID_CREDENTIALS', 'Invalid email or password')
    )

    await gotoReady(page, '/login')
    await page.getByPlaceholder('admin@example.com').fill('admin@example.com')
    await page.getByPlaceholder('••••••••').fill('wrong-password')
    await page.getByRole('button', { name: 'Sign in' }).click()

    await expect(page.getByText('Invalid email or password')).toBeVisible()
    await expect(page).toHaveURL(/\/login$/)
  })
})
