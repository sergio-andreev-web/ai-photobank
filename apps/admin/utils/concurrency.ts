/**
 * Runs `worker` over `items` with at most `limit` concurrent executions.
 * Each item's result/error is reported through `onSettle` as soon as it finishes,
 * independent of the order other items complete in.
 */
export async function runWithConcurrency<T>(
  items: T[],
  limit: number,
  worker: (item: T, index: number) => Promise<void>
): Promise<void> {
  let cursor = 0

  async function runNext(): Promise<void> {
    const index = cursor++
    if (index >= items.length) return
    await worker(items[index]!, index)
    await runNext()
  }

  const workers = Array.from({ length: Math.max(1, Math.min(limit, items.length)) }, () => runNext())
  await Promise.all(workers)
}
