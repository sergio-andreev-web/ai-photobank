/**
 * Gallery multi-select: a click toggles one image, Shift+click applies the clicked image's new
 * state to the whole range from the last clicked image (the anchor), in page order.
 */
export function toggleSelection(
  selected: ReadonlySet<number>,
  pageIds: readonly number[],
  id: number,
  options: { shift?: boolean; anchor?: number | null } = {},
): Set<number> {
  const next = new Set(selected)
  const select = !selected.has(id)

  const from = options.shift && options.anchor != null ? pageIds.indexOf(options.anchor) : -1
  const to = pageIds.indexOf(id)
  const range = from >= 0 && to >= 0 ? pageIds.slice(Math.min(from, to), Math.max(from, to) + 1) : [id]

  for (const rangeId of range) {
    if (select) next.add(rangeId)
    else next.delete(rangeId)
  }
  return next
}

/** Drops ids that are no longer on the page (after a reload or a filter change). */
export function keepVisible(selected: ReadonlySet<number>, pageIds: readonly number[]): Set<number> {
  const visible = new Set(pageIds)
  return new Set([...selected].filter(id => visible.has(id)))
}

/** State of the "select all on the page" checkbox. */
export function pageSelectionState(selected: ReadonlySet<number>, pageIds: readonly number[]): boolean | 'indeterminate' {
  const count = pageIds.filter(id => selected.has(id)).length
  if (count === 0) return false
  return count === pageIds.length ? true : 'indeterminate'
}
