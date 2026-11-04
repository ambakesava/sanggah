export const API_BASE = (import.meta.env.VITE_API_BASE_URL || '').replace(/\/$/, '')

export class ApiError extends Error {
  status: number
  body: unknown
  constructor(message: string, status: number, body: unknown) {
    super(message)
    this.status = status
    this.body = body
  }
}

export async function api<T>(path: string, init: RequestInit = {}, token?: string | null): Promise<T> {
  const headers = new Headers(init.headers)
  headers.set('Accept', 'application/json')
  if (!(init.body instanceof FormData) && !headers.has('Content-Type') && init.body) {
    headers.set('Content-Type', 'application/json')
  }
  if (token) headers.set('Authorization', `Bearer ${token}`)
  const response = await fetch(`${API_BASE}${path}`, { ...init, headers })
  const text = await response.text()
  const data = text ? JSON.parse(text) as unknown : {}
  if (!response.ok) {
    const body = data as { error?: { message?: string } }
    throw new ApiError(body.error?.message || 'Request failed', response.status, data)
  }
  return data as T
}

export async function compressImage(file: File): Promise<Blob> {
  const bitmap = await createImageBitmap(file)
  const scale = Math.min(1, 1024 / Math.max(bitmap.width, bitmap.height))
  const width = Math.max(1, Math.round(bitmap.width * scale))
  const height = Math.max(1, Math.round(bitmap.height * scale))
  const canvas = document.createElement('canvas')
  canvas.width = width
  canvas.height = height
  const context = canvas.getContext('2d')
  if (!context) throw new Error('canvas')
  context.fillStyle = '#fff'
  context.fillRect(0, 0, width, height)
  context.drawImage(bitmap, 0, 0, width, height)
  bitmap.close()
  const blob = await new Promise<Blob | null>((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.8))
  if (!blob) throw new Error('compress')
  return blob
}

export type Citation = { n: number; source_id: number; title: string; publisher?: string | null; url?: string | null }
export type Sections = { meaning: string; etiquette: string; dress_code: string; prohibitions: string }
export type Explanation = {
  status: string
  sections: Sections | null
  answer: string | null
  citations: Citation[]
  disclaimer: string
  source_mode: string
}
export type Ceremony = {
  slug: string
  name: string
  type?: string
  date_start: string
  date_end?: string | null
  summary?: string | null
  tips?: string | null
  road_note?: string | null
  road_note_label?: string
  has_road_note?: boolean
  sources?: { title: string; url?: string | null; publisher?: string | null }[]
}
