import { createHash } from 'node:crypto'
import { deflateSync } from 'node:zlib'

/**
 * Minimal PNG encoder (8-bit truecolor, no interlace) so the stack run can
 * make its own photos without extra dependencies.
 *
 * Why generated images: uploads are deduplicated by SHA-256, including
 * soft-deleted images (§27), so every run needs files the stack has never
 * seen. The mock AI derives caption, concepts and embedding from the decoded
 * pixels (services/ai/app/models/mock.py), so the nonce goes into the pixels,
 * not into metadata.
 */

export const PNG_SIGNATURE = Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a])

const CRC_TABLE = (() => {
  const table = new Uint32Array(256)
  for (let n = 0; n < 256; n++) {
    let c = n
    for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1
    table[n] = c >>> 0
  }
  return table
})()

/** CRC-32 (ISO-HDLC), as required for every PNG chunk. */
export function crc32(data: Uint8Array): number {
  let crc = 0xffffffff
  for (const byte of data) crc = CRC_TABLE[(crc ^ byte) & 0xff]! ^ (crc >>> 8)
  return (crc ^ 0xffffffff) >>> 0
}

function chunk(type: string, data: Buffer): Buffer {
  const length = Buffer.alloc(4)
  length.writeUInt32BE(data.length)
  const body = Buffer.concat([Buffer.from(type, 'latin1'), data])
  const crc = Buffer.alloc(4)
  crc.writeUInt32BE(crc32(body))
  return Buffer.concat([length, body, crc])
}

/** `rgb` holds width * height pixels, 3 bytes each, row by row. */
export function encodePng(width: number, height: number, rgb: Uint8Array): Buffer {
  if (!Number.isInteger(width) || !Number.isInteger(height) || width < 1 || height < 1) {
    throw new Error(`invalid PNG size ${width}x${height}`)
  }
  const stride = width * 3
  if (rgb.length !== stride * height) {
    throw new Error(`expected ${stride * height} RGB bytes, got ${rgb.length}`)
  }

  const header = Buffer.alloc(13)
  header.writeUInt32BE(width, 0)
  header.writeUInt32BE(height, 4)
  header[8] = 8 // bit depth
  header[9] = 2 // color type: truecolor
  header[10] = 0 // compression: deflate
  header[11] = 0 // filter method 0
  header[12] = 0 // no interlace

  // Each scanline starts with its filter type; 0 (None) keeps the encoder trivial.
  const scanlines = Buffer.alloc((stride + 1) * height)
  for (let y = 0; y < height; y++) {
    scanlines.set(rgb.subarray(y * stride, (y + 1) * stride), y * (stride + 1) + 1)
  }

  return Buffer.concat([
    PNG_SIGNATURE,
    chunk('IHDR', header),
    chunk('IDAT', deflateSync(scanlines)),
    chunk('IEND', Buffer.alloc(0)),
  ])
}

export interface TestImage {
  name: string
  buffer: Buffer
  sha256: string
}

/** Deterministic byte stream: SHA-256 in counter mode over `seed`. */
function byteStream(seed: string, length: number): Buffer {
  const blocks: Buffer[] = []
  for (let counter = 0; blocks.length * 32 < length; counter++) {
    blocks.push(createHash('sha256').update(`${seed}#${counter}`).digest())
  }
  return Buffer.concat(blocks).subarray(0, length)
}

const TILE = 8

/**
 * `count` distinct PNGs named `e2e-<nonce>-NN.png`: a grid of 8x8 tiles whose
 * colours come from SHA-256(nonce, index), so each file (and each decoded
 * pixel buffer) is unique per nonce, while the same nonce reproduces the same
 * files.
 */
export function makeTestImages(nonce: string, count: number, width = 96, height = 64): TestImage[] {
  if (!/^[a-z0-9]+$/.test(nonce)) throw new Error(`nonce must be [a-z0-9]+, got "${nonce}"`)
  const tilesX = Math.ceil(width / TILE)
  const tilesY = Math.ceil(height / TILE)
  const digits = Math.max(2, String(count).length)
  const images: TestImage[] = []

  for (let index = 1; index <= count; index++) {
    const palette = byteStream(`${nonce}:${index}`, tilesX * tilesY * 3)
    const rgb = Buffer.alloc(width * height * 3)
    for (let y = 0; y < height; y++) {
      for (let x = 0; x < width; x++) {
        const tile = (Math.floor(y / TILE) * tilesX + Math.floor(x / TILE)) * 3
        const offset = (y * width + x) * 3
        rgb[offset] = palette[tile]!
        rgb[offset + 1] = palette[tile + 1]!
        rgb[offset + 2] = palette[tile + 2]!
      }
    }
    const buffer = encodePng(width, height, rgb)
    images.push({
      name: `e2e-${nonce}-${String(index).padStart(digits, '0')}.png`,
      buffer,
      sha256: createHash('sha256').update(buffer).digest('hex'),
    })
  }

  if (new Set(images.map((image) => image.sha256)).size !== images.length) {
    throw new Error('generated test images are not unique')
  }
  return images
}

/** Lowercase alphanumeric, unique per run: the upload API rejects any file it has seen before. */
export function runNonce(): string {
  return `${Date.now().toString(36)}${Math.random().toString(36).slice(2, 8)}`.replace(/[^a-z0-9]/g, '')
}
