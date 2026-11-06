import { computed, ref } from 'vue'
import { useT } from './useT.js'

const { t } = useT()

const MAX_FILES = 5
const MAX_BYTES = 1024 * 1024
const TARGET_BYTES = Math.floor(MAX_BYTES * 0.94)
const ALLOWED = ['image/png', 'image/jpeg', 'image/webp']
const MAX_DIMENSION = 2400

function formatSize(bytes) {
  if (bytes >= 1024 * 1024) return (bytes / 1024 / 1024).toFixed(1) + ' MB'
  if (bytes >= 1024) return Math.round(bytes / 1024) + ' KB'
  return bytes + ' B'
}

function alertUser(title, message) {
  if (window.Nomen && typeof window.Nomen.alert === 'function') {
    window.Nomen.alert(title, message)
  }
}

function loadImage(blob) {
  return new Promise((resolve, reject) => {
    const url = URL.createObjectURL(blob)
    const img = new Image()
    img.onload = () => resolve({ img, url })
    img.onerror = () => {
      URL.revokeObjectURL(url)
      reject(new Error(t('这张图片解不开，换个格式再试')))
    }
    img.src = url
  })
}

function pickEncodingType() {
  try {
    const probe = document.createElement('canvas')
    probe.width = 1
    probe.height = 1
    return probe.toDataURL('image/webp').indexOf('data:image/webp') === 0 ? 'image/webp' : 'image/jpeg'
  } catch (err) {
    return 'image/jpeg'
  }
}

function encodeAt(img, scale, quality, type) {
  return new Promise((resolve, reject) => {
    const w = Math.max(1, Math.round(img.naturalWidth * scale))
    const h = Math.max(1, Math.round(img.naturalHeight * scale))
    const canvas = document.createElement('canvas')
    canvas.width = w
    canvas.height = h

    const ctx = canvas.getContext('2d')
    ctx.fillStyle = '#ffffff'
    ctx.fillRect(0, 0, w, h)
    ctx.drawImage(img, 0, 0, w, h)

    canvas.toBlob((blob) => {
      if (blob) resolve(blob)
      else reject(new Error(t('浏览器压缩失败')))
    }, type, quality)
  })
}

async function compressImage(file) {
  const type = pickEncodingType()
  const handle = await loadImage(file)
  const img = handle.img

  let scale = Math.min(1, MAX_DIMENSION / Math.max(img.naturalWidth, img.naturalHeight))
  const qualities = [0.9, 0.82, 0.74, 0.66, 0.58]
  let best = null

  try {
    for (let round = 0; round < 5 && scale > 0.12; round++) {
      for (const quality of qualities) {
        const blob = await encodeAt(img, scale, quality, type)
        if (!best || blob.size < best.size) best = blob
        if (blob.size <= TARGET_BYTES) return blob
      }
      scale *= 0.72
    }
  } finally {
    URL.revokeObjectURL(handle.url)
  }

  return best || file
}

export function useRecoveryUploads(uploadUrl) {
  const items = ref([])
  const status = ref('')
  const statusKind = ref('')
  const uploading = ref(false)
  let seq = 0

  const hasErrors = computed(() => items.value.some((item) => item.state === 'error'))
  const totalSize = computed(() => items.value.reduce((sum, item) => sum + (item.size || 0), 0))
  const compressedCount = computed(() => items.value.filter((item) => item.compressed).length)

  function setStatus(text, kind = '') {
    status.value = text || ''
    statusKind.value = kind
  }

  function updateSummary() {
    if (!items.value.length) {
      setStatus('')
      return
    }
    setStatus(
      t('已选 {:n} / {:max} 张，共 {:size}{:comp}', { n: items.value.length, max: MAX_FILES, size: formatSize(totalSize.value), comp: compressedCount.value ? t('（其中 {:n} 张已自动压缩）', { n: compressedCount.value }) : '' }),
    )
  }

  function removeItem(id) {
    const index = items.value.findIndex((item) => item.id === id)
    if (index === -1) return
    if (items.value[index].url) URL.revokeObjectURL(items.value[index].url)
    items.value.splice(index, 1)
    updateSummary()
  }

  async function addFiles(fileList) {
    const incoming = Array.from(fileList || [])
    let usable = incoming.filter((file) => file && ALLOWED.includes(file.type))

    const skippedType = incoming.length - usable.length
    if (skippedType > 0) {
      alertUser(t('格式不支持'), t('只接受 PNG / JPG / WebP 图片，已忽略 {:n} 个文件。', { n: skippedType }))
    }
    if (!usable.length) return

    const room = MAX_FILES - items.value.length
    if (room <= 0) {
      alertUser(t('张数已达上限'), t('最多只能上传 {:n} 张证明材料。', { n: MAX_FILES }))
      return
    }

    const overflow = usable.length - room
    if (overflow > 0) {
      usable = usable.slice(0, room)
      alertUser(t('超出张数上限'), t('最多 {:max} 张，已忽略多出的 {:n} 个文件。', { max: MAX_FILES, n: overflow }))
    }

    for (const file of usable) {
      const item = {
        id: ++seq,
        name: file.name || ('paste-' + seq + '.png'),
        blob: file,
        size: file.size,
        state: 'ready',
        compressed: false,
        url: '',
        token: '',
      }
      items.value.push(item)
      updateSummary()

      if (file.size > MAX_BYTES) {
        setStatus(t('第 {:n} 张超过 1 MB，正在压缩…', { n: items.value.length }))
        try {
          const compressed = await compressImage(file)
          item.blob = compressed
          item.size = compressed.size
          item.compressed = true
          if (compressed.size > MAX_BYTES) {
            item.state = 'error'
            setStatus(t('有一张压缩后仍超过 1 MB，请先删掉换一张'), 'error')
          }
        } catch (err) {
          item.state = 'error'
          setStatus(err.message, 'error')
        }
      }

      item.url = URL.createObjectURL(item.blob)
      updateSummary()
    }

    if (!hasErrors.value) updateSummary()
  }

  async function uploadOne(item, csrf) {
    const data = new FormData()
    data.append('_csrf', csrf)
    data.append('file', item.blob, item.name)

    const response = await fetch(uploadUrl, {
      method: 'POST',
      body: data,
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
    })

    let body = null
    try {
      body = await response.json()
    } catch (err) {
      body = null
    }

    if (body && body.success && body.token) return body.token
    if (body && body.message) throw new Error(body.message)
    throw new Error(t('服务器返回了 {:code}，图片没能上传', { code: response.status }))
  }

  async function uploadAll(csrf) {
    uploading.value = true
    const remaining = items.value.filter((item) => item.state !== 'done')

    try {
      for (let i = 0; i < remaining.length; i++) {
        const item = remaining[i]
        item.state = 'uploading'
        setStatus(t('正在上传第 {:a} / {:b} 张证明材料…', { a: i + 1, b: remaining.length }))
        item.token = await uploadOne(item, csrf)
        item.state = 'done'
        updateSummary()
      }
      return items.value.map((item) => item.token)
    } finally {
      uploading.value = false
    }
  }

  return {
    items,
    status,
    statusKind,
    uploading,
    hasErrors,
    addFiles,
    removeItem,
    uploadAll,
    setStatus,
    formatSize,
    MAX_FILES,
  }
}
