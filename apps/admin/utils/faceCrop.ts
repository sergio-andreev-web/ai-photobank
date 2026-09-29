import type { FaceBox } from '~/types/api'

/**
 * CSS that shows only the face of a picture (ADR-0015): the face box, padded by `pad` of its
 * size, is scaled to fill the element. Pure background positioning — no crop endpoint needed.
 */
export function faceCropStyle(url: string | null, box: FaceBox, pad = 0.35): Record<string, string> {
  const width = Math.min(1, box.width * (1 + 2 * pad))
  const height = Math.min(1, box.height * (1 + 2 * pad))
  const x = Math.min(Math.max(0, box.x - box.width * pad), 1 - width)
  const y = Math.min(Math.max(0, box.y - box.height * pad), 1 - height)
  // background-position p% aligns the p% point of the image with the p% point of the element.
  const position = (offset: number, size: number) => (size >= 1 ? 0 : (offset / (1 - size)) * 100)
  return {
    backgroundImage: url ? `url("${url}")` : 'none',
    backgroundSize: `${100 / width}% ${100 / height}%`,
    backgroundPosition: `${position(x, width)}% ${position(y, height)}%`,
    backgroundRepeat: 'no-repeat',
  }
}
