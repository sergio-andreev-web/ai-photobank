import { test, expect } from '@playwright/test'
import { apiError, gotoReady, json, loginAsAdmin } from './helpers'

const PENDING_CANDIDATE = {
  id: 5,
  display_name: 'drilling platform',
  normalized_name: 'drilling platform',
  type: 'object',
  status: 'pending',
  occurrences_count: 37,
  closest_tag: { id: 87, name: 'Буровая', type: 'object', status: 'active' },
  similarity: 0.71,
  sample_images: [{ id: 152, thumbnail_url: '/api/media/images/152/thumbnail', confidence: 0.88 }],
  created_at: '2026-09-20T10:00:00Z',
  updated_at: '2026-09-25T13:51:12Z',
  resolved_at: null,
  resolved_by: null,
  resolved_tag_id: null,
}

test.describe('tag candidate moderation', () => {
  test('approving a candidate creates a tag and updates the queue', async ({ page }) => {
    await loginAsAdmin(page)

    let resolved = false
    await page.route('**/api/admin/tag-candidates?*', (route) =>
      json(route, 200, {
        data: resolved ? [] : [PENDING_CANDIDATE],
        meta: { page: 1, per_page: 20, total: resolved ? 0 : 1, last_page: 1 },
      })
    )
    await page.route('**/api/admin/tag-candidates/5/approve', (route) => {
      resolved = true
      return json(route, 200, {
        candidate: { ...PENDING_CANDIDATE, status: 'created', resolved_tag_id: 200, resolved_at: '2026-09-25T14:00:00Z' },
        tag: { id: 200, name: 'drilling platform', type: 'object', status: 'active' },
        attached_images_count: 37,
        alias_created: false,
      })
    })

    await gotoReady(page, '/candidates')
    await expect(page.getByText('drilling platform')).toBeVisible()

    await page.getByRole('button', { name: 'Create Tag' }).click()
    await expect(page.getByText('No candidates found for this filter.')).toBeVisible()
  })

  test('rejecting an already-resolved candidate surfaces the contract error', async ({ page }) => {
    await loginAsAdmin(page)

    await page.route('**/api/admin/tag-candidates?*', (route) =>
      json(route, 200, {
        data: [PENDING_CANDIDATE],
        meta: { page: 1, per_page: 20, total: 1, last_page: 1 },
      })
    )
    await page.route('**/api/admin/tag-candidates/5/reject', (route) =>
      apiError(route, 409, 'TAG_CANDIDATE_ALREADY_RESOLVED', 'Candidate is already resolved', { status: 'merged' })
    )

    await gotoReady(page, '/candidates')
    await page.getByRole('button', { name: 'Reject' }).click()

    await expect(page.getByText('Candidate is already resolved')).toBeVisible()
    // The candidate stays in the list since the action failed.
    await expect(page.getByText('drilling platform')).toBeVisible()
  })
})
