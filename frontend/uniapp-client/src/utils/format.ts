/** 展示层格式化工具 */

/** 秒级时间戳 → 'YYYY-MM-DD HH:mm'（0/空返回空串） */
export function fmtTime(ts: number | string | null | undefined): string {
  const n = Number(ts);
  if (!n || n <= 0) return "";
  const d = new Date(n * 1000);
  const pad = (x: number) => (x < 10 ? "0" + x : "" + x);
  return (
    d.getFullYear() +
    "-" +
    pad(d.getMonth() + 1) +
    "-" +
    pad(d.getDate()) +
    " " +
    pad(d.getHours()) +
    ":" +
    pad(d.getMinutes())
  );
}

/** 金额统一两位小数 */
export function price2(v: number | string | null | undefined): string {
  const n = Number(v);
  return (isNaN(n) ? 0 : n).toFixed(2);
}

/** 图片地址转绝对路径（后端返回 /uploads/... 相对路径时补 origin） */
export function imgUrl(path: string | null | undefined): string {
  const p = (path || "").trim();
  if (!p) return "";
  if (/^https?:\/\//.test(p) || p.startsWith("data:")) return p;
  // #ifdef H5
  return p.startsWith("/") ? p : "/" + p;
  // #endif
  // #ifndef H5
  return "http://127.0.0.1:8080" + (p.startsWith("/") ? p : "/" + p);
  // #endif
}
