// 时间格式化（Unix 秒时间戳 → yyyy-MM-dd HH:mm）
export function formatTime(value: number | string | null | undefined) {
  if (value === null || value === undefined || value === '' || Number(value) === 0) return '-'
  const ts = Number(value)
  if (!Number.isFinite(ts)) return '-'
  const d = new Date(ts * 1000)
  if (isNaN(d.getTime())) return '-'
  const pad = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}`
}

// 日期格式化（Unix 秒时间戳 → yyyy-MM-dd）
export function formatDate(value: number | string | null | undefined) {
  if (value === null || value === undefined || value === '' || Number(value) === 0) return '-'
  const ts = Number(value)
  if (!Number.isFinite(ts)) return '-'
  const d = new Date(ts * 1000)
  if (isNaN(d.getTime())) return '-'
  const pad = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`
}
