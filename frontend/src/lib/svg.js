/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only.
 * Attribution requests are in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
 */

import { useT } from '../composables/useT.js'

const { t } = useT()

export const SVG_PNG_SIZE = 135

export function isSvgFile(file) {
  return file.type === 'image/svg+xml' || /\.svg$/i.test(file.name || '')
}

function svgFileToPng(file, size) {
  return new Promise((resolve, reject) => {
    const url = URL.createObjectURL(file)
    const img = new Image()
    img.onload = () => {
      const w = img.naturalWidth || img.width || size
      const h = img.naturalHeight || img.height || size
      const scale = Math.min(size / w, size / h)
      const dw = Math.max(1, Math.round(w * scale))
      const dh = Math.max(1, Math.round(h * scale))
      const canvas = document.createElement('canvas')
      canvas.width = size
      canvas.height = size
      // 保住宽高比居中画，空出来的部分留透明即可（PNG 有 alpha 通道）
      canvas.getContext('2d').drawImage(img, Math.round((size - dw) / 2), Math.round((size - dh) / 2), dw, dh)
      URL.revokeObjectURL(url)
      try {
        canvas.toBlob((blob) => {
          if (blob) resolve(blob)
          else reject(new Error(t('SVG conversion failed')))
        }, 'image/png')
      } catch (err) {
        reject(new Error(t('SVG conversion failed')))
      }
    }
    img.onerror = () => {
      URL.revokeObjectURL(url)
      reject(new Error(t('Can\'t read this SVG')))
    }
    img.src = url
  })
}

export async function svgToPngFile(file, size = SVG_PNG_SIZE) {
  const blob = await svgFileToPng(file, size)
  return new File([blob], (file.name || 'image.svg').replace(/\.svg$/i, '') + '.png', { type: 'image/png' })
}
