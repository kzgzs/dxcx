/**
 * 运行时租户解析（取代原写死 TENANT_ID=1）
 *
 * 租户 ID 解析优先级（高 → 低）：
 *   ⓪ H5 环境 URL 参数 ?aid= 覆盖（仅开发调试用）
 *   ① uni.getExtConfigSync() 取 ext.json 的 aid
 *      —— 小程序经第三方平台模板发布时，由发布链
 *         app/common/service/OpenCodeService::buildExtJson 注入 {aid, baseurl}
 *   ② siteinfo 配置文件兜底（src/config/siteinfo.ts）
 *   ③ 默认 DEFAULT_TENANT_ID
 *
 * API 网关地址解析优先级（高 → 低）：
 *   ① ext.baseurl（生产小程序，发布链注入，https 域名）
 *   ② import.meta.env.VITE_API_BASE（.env 配置，生产可指定网关）
 *   ③ siteinfo.siteroot 兜底
 *   ④ 空串：dev 走 vite /api 代理，prod 走同域子路径部署
 *
 * 后端 TenantMiddleware 解析优先级：X-Tenant-Id 头 > aid 参数；
 * AuthMiddleware 登录后 JWT 内 aid 覆盖头部，故登录后以 token 为准。
 */
import siteinfo from "./siteinfo";

/** ext.json 结构（发布链 buildExtJson 的 ext 节点） */
export interface ExtConfig {
  aid?: number | string;
  baseurl?: string;
  [key: string]: unknown;
}

/** 兜底默认租户 ID */
export const DEFAULT_TENANT_ID = 1;

/** 租户来源（调试可见）：url / ext / siteinfo / default */
export type TenantSource = "url" | "ext" | "siteinfo" | "default";

interface TenantResult {
  tenantId: number;
  apiBase: string;
  source: TenantSource;
}

let cached: TenantResult | null = null;

/** 读取 ext.json（仅小程序端有效；H5/不支持的平台返回 {}） */
function readExtConfig(): ExtConfig {
  try {
    if (typeof uni !== "undefined" && typeof uni.getExtConfigSync === "function") {
      const ext = uni.getExtConfigSync() as ExtConfig | undefined | null;
      return ext && typeof ext === "object" ? ext : {};
    }
  } catch {
    // 平台不支持或异常，走兜底
  }
  return {};
}

/** H5 环境从 URL 解析 ?aid= 参数（开发调试覆盖），非 H5 返回 null */
function parseUrlAid(): number | null {
  // #ifdef H5
  try {
    const search = window.location.search || "";
    const m = search.match(/[?&]aid=(\d+)/);
    if (m) {
      const v = parseInt(m[1], 10);
      if (v > 0) return v;
    }
  } catch {
    // ignore
  }
  // #endif
  return null;
}

function toPositiveInt(v: unknown): number {
  const n = Number(v);
  return Number.isFinite(n) && n > 0 ? Math.floor(n) : 0;
}

/** 规范化 baseurl：去掉末尾斜杠 */
function normalizeBase(base: string): string {
  return base.replace(/\/+$/, "");
}

/** 执行一次完整解析（内部带缓存） */
function resolve(): TenantResult {
  if (cached) return cached;

  const ext = readExtConfig();

  // —— 租户 ID：① ext ② siteinfo ③ 默认 ——
  let tenantId = toPositiveInt(ext.aid);
  let source: TenantSource = tenantId > 0 ? "ext" : "default";
  if (!(tenantId > 0) && toPositiveInt(siteinfo.uniacid) > 0) {
    tenantId = toPositiveInt(siteinfo.uniacid);
    source = "siteinfo";
  }
  if (!(tenantId > 0)) {
    tenantId = DEFAULT_TENANT_ID;
    source = "default";
  }
  // —— ⓪ H5 URL 参数覆盖（最高优先级，调试用）——
  const urlAid = parseUrlAid();
  if (urlAid && urlAid > 0) {
    tenantId = urlAid;
    source = "url";
  }

  // —— API 网关地址：① ext.baseurl ② env ③ siteinfo ④ 空串 ——
  let apiBase = "";
  if (typeof ext.baseurl === "string" && ext.baseurl.trim() !== "") {
    apiBase = normalizeBase(ext.baseurl);
  } else if (typeof import.meta.env.VITE_API_BASE === "string" && import.meta.env.VITE_API_BASE.trim() !== "") {
    apiBase = normalizeBase(import.meta.env.VITE_API_BASE);
  } else if (siteinfo.siteroot && siteinfo.siteroot.trim() !== "") {
    apiBase = normalizeBase(siteinfo.siteroot);
  }

  cached = { tenantId, apiBase, source };
  return cached;
}

/** 当前租户 ID（请求头 X-Tenant-Id 取值来源） */
export function getTenantId(): number {
  return resolve().tenantId;
}

/** 当前 API 网关前缀（空串 = 走 dev 代理 / 同域部署） */
export function getApiBase(): string {
  return resolve().apiBase;
}

/** 租户解析来源（调试用） */
export function getTenantSource(): TenantSource {
  return resolve().source;
}
