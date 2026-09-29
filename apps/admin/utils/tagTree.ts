import type { Tag } from '~/types/api'

/** A tag with its children resolved; built client-side from the flat `GET /admin/tags` list (§55). */
export type TagNode = Tag & { children: TagNode[] }

const collator = new Intl.Collator(undefined, { numeric: true, sensitivity: 'base' })

/** Name order (the API default `sort=name`), with the id as a stable tiebreaker. */
export function compareTags(a: Pick<Tag, 'id' | 'name'>, b: Pick<Tag, 'id' | 'name'>): number {
  return collator.compare(a.name, b.name) || a.id - b.id
}

/**
 * Turns the flat catalog into a forest by `parent_id`. The API refuses cycles
 * (`TAG_PARENT_INVALID`), but the UI must never lose tags because of bad data:
 * a tag whose parent is missing from the list, or whose parent link would close
 * a cycle, is shown as a root instead of disappearing.
 */
export function buildTagTree(tags: readonly Tag[]): TagNode[] {
  const nodes = new Map<number, TagNode>(tags.map(tag => [tag.id, { ...tag, children: [] }]))
  const attachedTo = new Map<number, number>()
  const roots: TagNode[] = []

  for (const node of nodes.values()) {
    const parent = node.parent_id === null ? undefined : nodes.get(node.parent_id)
    if (parent && !wouldCloseCycle(attachedTo, node.id, parent.id)) {
      attachedTo.set(node.id, parent.id)
      parent.children.push(node)
    } else {
      roots.push(node)
    }
  }

  return sortTree(roots)
}

/** True when `parentId` already sits below `childId` (or is it) in the tree built so far. */
function wouldCloseCycle(attachedTo: Map<number, number>, childId: number, parentId: number): boolean {
  let current: number | undefined = parentId
  while (current !== undefined) {
    if (current === childId) return true
    current = attachedTo.get(current)
  }
  return false
}

function sortTree(nodes: TagNode[]): TagNode[] {
  nodes.sort(compareTags)
  for (const node of nodes) sortTree(node.children)
  return nodes
}

/**
 * Keeps a node when it passes `keep` or when any descendant does, so a kept
 * child never loses its place under a parent that was filtered out.
 */
export function filterTagTree(nodes: readonly TagNode[], keep: (tag: TagNode) => boolean): TagNode[] {
  const result: TagNode[] = []
  for (const node of nodes) {
    const children = filterTagTree(node.children, keep)
    if (keep(node) || children.length > 0) result.push({ ...node, children })
  }
  return result
}

/** Ids of every tag below `id` (children, grandchildren, ...); `id` itself is not included. */
export function descendantIds(tags: readonly Tag[], id: number): Set<number> {
  const childrenOf = new Map<number, number[]>()
  for (const tag of tags) {
    if (tag.parent_id === null) continue
    const siblings = childrenOf.get(tag.parent_id)
    if (siblings) siblings.push(tag.id)
    else childrenOf.set(tag.parent_id, [tag.id])
  }

  const found = new Set<number>()
  const queue = [...(childrenOf.get(id) ?? [])]
  for (let i = 0; i < queue.length; i++) {
    const next = queue[i] as number
    if (found.has(next) || next === id) continue
    found.add(next)
    queue.push(...(childrenOf.get(next) ?? []))
  }
  return found
}

/** Ids of the parents of `id`, nearest first (parent, grandparent, ...). */
export function ancestorIds(tags: readonly Tag[], id: number): number[] {
  const byId = new Map(tags.map(tag => [tag.id, tag]))
  const chain: number[] = []
  const seen = new Set<number>([id])
  let parentId = byId.get(id)?.parent_id ?? null
  while (parentId !== null && !seen.has(parentId) && byId.has(parentId)) {
    chain.push(parentId)
    seen.add(parentId)
    parentId = byId.get(parentId)?.parent_id ?? null
  }
  return chain
}

/** Ids of every node that has children — what "Expand all" needs. */
export function expandableIds(nodes: readonly TagNode[]): number[] {
  const ids: number[] = []
  for (const node of nodes) {
    if (node.children.length > 0) ids.push(node.id)
    ids.push(...expandableIds(node.children))
  }
  return ids
}

/** "Транспорт › Автомобиль" for every tag — disambiguates equal names in pickers. */
export function buildTagPaths(tags: readonly Tag[]): Map<number, string> {
  const byId = new Map(tags.map(tag => [tag.id, tag]))
  const paths = new Map<number, string>()
  for (const tag of tags) {
    const names: string[] = []
    const seen = new Set<number>()
    let current: Tag | undefined = tag
    while (current && !seen.has(current.id)) {
      names.unshift(current.name)
      seen.add(current.id)
      current = current.parent_id === null ? undefined : byId.get(current.parent_id)
    }
    paths.set(tag.id, names.join(' › '))
  }
  return paths
}
