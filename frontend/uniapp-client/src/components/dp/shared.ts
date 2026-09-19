/**
 * dp 设计器组件公共工具
 * - 数值/字符串安全解析（设计器 params 值多为字符串）
 * - 通用盒模型样式（margin_x/margin_y/padding_x/padding_y/bgcolor）
 *   口径对齐参考系统：设计值 *2.2 → rpx
 */

/** 宽松数值解析：非法/空一律回默认值 */
export function num(v: unknown, def = 0): number {
  const n = Number(v);
  return Number.isFinite(n) ? n : def;
}

/** 宽松字符串解析 */
export function str(v: unknown, def = ""): string {
  if (v === null || v === undefined) return def;
  const s = String(v).trim();
  return s === "" ? def : s;
}

/** 组件参数（设计器原始键值，类型宽松） */
export type DpParams = Record<string, unknown>;

/** 开关解析：'1'/1/true → 1，其余 → 0（设计器开关字段多为字符串 '0'/'1'） */
export function flag(v: unknown, def = 0): number {
  if (v === undefined || v === null || v === "") return def ? 1 : 0;
  if (v === true || v === 1 || v === "1") return 1;
  return 0;
}

/** 数值裁剪到 [min, max] */
export function clampNum(v: unknown, min: number, max: number, def = min): number {
  const n = Number(v);
  if (!Number.isFinite(n)) return def;
  return Math.min(max, Math.max(min, n));
}

/**
 * 通用盒模型样式：DpRenderer 包裹层统一应用
 * - margin: 上/左右取 margin_y/margin_x，底部不留（对齐参考系统）
 * - padding: padding_y/padding_x
 * - 背景色兼容 bgcolor（设计器口径）与 background（旧口径）
 */
export function boxStyle(params: DpParams): Record<string, string> {
  const p = params || {};
  const style: Record<string, string> = {};
  const my = num(p.margin_y);
  const mx = num(p.margin_x);
  const py = num(p.padding_y);
  const px = num(p.padding_x);
  style.margin = `${my * 2.2}rpx ${mx * 2.2}rpx 0`;
  style.padding = `${py * 2.2}rpx ${px * 2.2}rpx`;
  const bg = str(p.bgcolor) || str(p.background);
  if (bg) style.background = bg;
  return style;
}
