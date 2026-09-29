import { createHash } from 'node:crypto'
import { inflateSync } from 'node:zlib'
import { expect, test } from '@playwright/test'
import { PNG_SIGNATURE, crc32, encodePng, makeTestImages, runNonce } from '../lib/png'

/** Runs without the stack (`npm run test:unit`): checks the generated uploads are valid, distinct PNGs. */

interface Chunk {
  type: string
  data: Buffer
  crc: number
}

function readChunks(png: Buffer): Chunk[] {
  expect(png.subarray(0, 8).equals(PNG_SIGNATURE)).toBe(true)
  const chunks: Chunk[] = []
  let offset = 8
  while (offset < png.length) {
    const length = png.readUInt32BE(offset)
    const type = png.subarray(offset + 4, offset + 8).toString('latin1')
    const data = png.subarray(offset + 8, offset + 8 + length)
    const crc = png.readUInt32BE(offset + 8 + length)
    expect(crc, `${type} CRC`).toBe(crc32(png.subarray(offset + 4, offset + 8 + length)))
    chunks.push({ type, data, crc })
    offset += 12 + length
  }
  expect(offset).toBe(png.length)
  return chunks
}

test('crc32 matches the standard check value', () => {
  expect(crc32(Buffer.from('123456789', 'ascii'))).toBe(0xcbf43926)
  expect(crc32(Buffer.alloc(0))).toBe(0)
})

test('encodePng writes IHDR, one IDAT and IEND around filter-0 scanlines', () => {
  const width = 3
  const height = 2
  const rgb = Uint8Array.from({ length: width * height * 3 }, (_, i) => (i * 37) % 256)
  const chunks = readChunks(encodePng(width, height, rgb))

  expect(chunks.map((chunk) => chunk.type)).toEqual(['IHDR', 'IDAT', 'IEND'])
  const header = chunks[0]!.data
  expect(header.readUInt32BE(0)).toBe(width)
  expect(header.readUInt32BE(4)).toBe(height)
  expect([...header.subarray(8)]).toEqual([8, 2, 0, 0, 0])

  const scanlines = inflateSync(chunks[1]!.data)
  expect(scanlines.length).toBe((width * 3 + 1) * height)
  for (let y = 0; y < height; y++) {
    const row = scanlines.subarray(y * (width * 3 + 1), (y + 1) * (width * 3 + 1))
    expect(row[0], 'filter type').toBe(0)
    expect([...row.subarray(1)]).toEqual([...rgb.subarray(y * width * 3, (y + 1) * width * 3)])
  }
  expect(chunks[2]!.data.length).toBe(0)
})

test('encodePng rejects a pixel buffer of the wrong size', () => {
  expect(() => encodePng(2, 2, new Uint8Array(11))).toThrow(/expected 12 RGB bytes/)
  expect(() => encodePng(0, 2, new Uint8Array(0))).toThrow(/invalid PNG size/)
})

test('makeTestImages gives 20 distinct, reproducible, named uploads per nonce', () => {
  const images = makeTestImages('fixednonce', 20)
  expect(images).toHaveLength(20)
  expect(images[0]!.name).toBe('e2e-fixednonce-01.png')
  expect(images[19]!.name).toBe('e2e-fixednonce-20.png')
  expect(new Set(images.map((image) => image.name)).size).toBe(20)
  expect(new Set(images.map((image) => image.sha256)).size).toBe(20)

  for (const image of images) {
    expect(image.sha256).toBe(createHash('sha256').update(image.buffer).digest('hex'))
    const chunks = readChunks(image.buffer)
    expect(chunks[0]!.data.readUInt32BE(0)).toBe(96)
    expect(chunks[0]!.data.readUInt32BE(4)).toBe(64)
  }

  // Pixels differ too, not only the bytes: the mock AI hashes decoded pixels (mock.py).
  const pixels = images.map((image) => createHash('sha256').update(inflateSync(readChunks(image.buffer)[1]!.data)).digest('hex'))
  expect(new Set(pixels).size).toBe(20)

  const again = makeTestImages('fixednonce', 20)
  expect(again.map((image) => image.sha256)).toEqual(images.map((image) => image.sha256))
  const other = makeTestImages('othernonce', 20)
  expect(other.filter((image) => images.some((mine) => mine.sha256 === image.sha256))).toEqual([])
})

test('runNonce is lowercase alphanumeric and differs between calls', () => {
  const first = runNonce()
  const second = runNonce()
  expect(first).toMatch(/^[a-z0-9]{8,}$/)
  expect(second).not.toBe(first)
})
