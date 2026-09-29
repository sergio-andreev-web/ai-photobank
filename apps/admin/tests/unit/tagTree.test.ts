import { describe, it, expect } from 'vitest'
import type { Tag } from '~/types/api'
import {
  ancestorIds,
  buildTagPaths,
  buildTagTree,
  compareTags,
  descendantIds,
  expandableIds,
  filterTagTree,
} from '~/utils/tagTree'

function tag(id: number, name: string, parent_id: number | null = null, extra: Partial<Tag> = {}): Tag {
  return {
    id,
    parent_id,
    name,
    slug: `tag-${id}`,
    normalized_name: name.toLowerCase(),
    type: 'object',
    status: 'active',
    images_count: 0,
    aliases: [],
    created_at: '2026-09-25T12:00:00Z',
    updated_at: '2026-09-25T12:00:00Z',
    ...extra,
  }
}

const names = (nodes: { name: string }[]) => nodes.map(node => node.name)

describe('buildTagTree', () => {
  it('nests tags by parent_id and sorts every level by name', () => {
    const tree = buildTagTree([
      tag(3, 'Самолёт', 1),
      tag(1, 'Транспорт'),
      tag(2, 'Автомобиль', 1),
      tag(4, 'Легковой', 2),
      tag(5, 'Каска'),
    ])

    expect(names(tree)).toEqual(['Каска', 'Транспорт'])
    const transport = tree[1]!
    expect(names(transport.children)).toEqual(['Автомобиль', 'Самолёт'])
    expect(names(transport.children[0]!.children)).toEqual(['Легковой'])
  })

  it('keeps the API fields on every node, including images_count', () => {
    const [root] = buildTagTree([tag(1, 'Каска', null, { images_count: 12 })])
    expect(root).toMatchObject({ id: 1, images_count: 12, children: [] })
  })

  it('shows a tag whose parent is not in the list as a root instead of dropping it', () => {
    const tree = buildTagTree([tag(2, 'Автомобиль', 99), tag(1, 'Каска')])
    expect(names(tree)).toEqual(['Автомобиль', 'Каска'])
  })

  it('breaks parent cycles and self-parenting so no tag disappears', () => {
    const self = buildTagTree([tag(1, 'A', 1)])
    expect(names(self)).toEqual(['A'])

    const loop = buildTagTree([tag(1, 'A', 2), tag(2, 'B', 3), tag(3, 'C', 1)])
    const seen: string[] = []
    const walk = (nodes: typeof loop) => nodes.forEach((node) => { seen.push(node.name); walk(node.children) })
    walk(loop)
    expect(seen.sort()).toEqual(['A', 'B', 'C'])
    expect(loop).toHaveLength(1)
  })

  it('does not mutate its input', () => {
    const input = [tag(1, 'Транспорт'), tag(2, 'Автомобиль', 1)]
    const copy = structuredClone(input)
    buildTagTree(input)
    expect(input).toEqual(copy)
  })
})

describe('compareTags', () => {
  it('orders by name, then by id', () => {
    expect([tag(2, 'b'), tag(3, 'a'), tag(1, 'a')].sort(compareTags).map(t => t.id)).toEqual([1, 3, 2])
  })

  it('compares numbers naturally', () => {
    expect([tag(1, 'Тег 10'), tag(2, 'Тег 2')].sort(compareTags).map(t => t.name)).toEqual(['Тег 2', 'Тег 10'])
  })
})

describe('filterTagTree', () => {
  const tree = buildTagTree([
    tag(1, 'Старый транспорт', null, { status: 'archived' }),
    tag(2, 'Автомобиль', 1),
    tag(3, 'Архив без детей', null, { status: 'archived' }),
    tag(4, 'Каска'),
  ])

  it('drops archived tags without visible descendants', () => {
    const active = filterTagTree(tree, node => node.status === 'active')
    expect(names(active)).toEqual(['Каска', 'Старый транспорт'])
  })

  it('keeps an archived parent as long as one of its descendants is kept', () => {
    const active = filterTagTree(tree, node => node.status === 'active')
    const parent = active.find(node => node.name === 'Старый транспорт')!
    expect(names(parent.children)).toEqual(['Автомобиль'])
  })

  it('returns everything when nothing is filtered out', () => {
    expect(names(filterTagTree(tree, () => true))).toEqual(names(tree))
  })
})

describe('hierarchy lookups', () => {
  const tags = [tag(1, 'Транспорт'), tag(2, 'Автомобиль', 1), tag(3, 'Легковой', 2), tag(4, 'Каска')]

  it('collects every descendant but not the tag itself', () => {
    expect([...descendantIds(tags, 1)].sort()).toEqual([2, 3])
    expect([...descendantIds(tags, 3)]).toEqual([])
    expect([...descendantIds(tags, 4)]).toEqual([])
  })

  it('terminates on cycles', () => {
    const loop = [tag(1, 'A', 2), tag(2, 'B', 1)]
    expect([...descendantIds(loop, 1)]).toEqual([2])
    expect(ancestorIds(loop, 1)).toEqual([2])
  })

  it('lists ancestors nearest first', () => {
    expect(ancestorIds(tags, 3)).toEqual([2, 1])
    expect(ancestorIds(tags, 1)).toEqual([])
    expect(ancestorIds(tags, 404)).toEqual([])
  })

  it('lists the nodes that have children', () => {
    expect(expandableIds(buildTagTree(tags)).sort()).toEqual([1, 2])
  })

  it('builds "parent › child" paths for pickers', () => {
    const paths = buildTagPaths(tags)
    expect(paths.get(3)).toBe('Транспорт › Автомобиль › Легковой')
    expect(paths.get(4)).toBe('Каска')
  })
})
