/**
 * 手机端后台（admin 子包）接口封装
 *
 * 契约（后端并行开发中，信封 code=0 视为成功）：
 *   POST /api/adminidx/login          {un,pwd} → data{token,admin{...}}
 *   GET  /api/adminidx/stats          → {today_money,today_order,pay,send,receive,refund,done}
 *   GET  /api/adminidx/orderlist      ?st=&page=
 *   GET  /api/adminidx/productlist    ?page=
 *   GET  /api/adminidx/memberlist     ?page=
 *   GET  /api/adminidx/finances
 *   POST /api/adminidx/productstatus  {id,status}
 *   POST /api/adminidx/setpwd         {oldpwd,newpwd}
 *
 * 独立于 C 端会员会话：token 存 storage 键 adminidx_token，请求头 token；
 * 401/404/500 一律 reject（由页面展示空态，不白屏、不跳会员登录页）。
 */
import { getApiBase, getTenantId } from "../config/tenant";

/** storage 键：管理员 token / 管理员信息 */
export const ADMIN_TOKEN_KEY = "adminidx_token";
export const ADMIN_INFO_KEY = "adminidx_admin";
/** 管理端登录页路径 */
export const ADMIN_LOGIN_PAGE = "/admin/index/login";

/** 后端统一响应信封 */
interface AdminResult<T = unknown> {
  code: number;
  msg: string;
  data: T;
}

export interface AdminInfo {
  id?: number;
  username?: string;
  nickname?: string;
  isadmin?: number;
  [key: string]: unknown;
}

export interface AdminStats {
  today_money?: number | string;
  today_order?: number | string;
  pay?: number;
  send?: number;
  receive?: number;
  refund?: number;
  done?: number;
  [key: string]: unknown;
}

/* ================= token / 登录态 ================= */

/** 读取管理员 token */
export function getAdminToken(): string {
  try {
    return (uni.getStorageSync(ADMIN_TOKEN_KEY) as string) || "";
  } catch {
    return "";
  }
}

/** 保存登录结果（token + 管理员信息） */
export function saveAdminSession(token: string, admin: AdminInfo | null) {
  uni.setStorageSync(ADMIN_TOKEN_KEY, token || "");
  uni.setStorageSync(ADMIN_INFO_KEY, admin || {});
}

/** 读取管理员信息 */
export function getAdminInfo(): AdminInfo {
  try {
    const v = uni.getStorageSync(ADMIN_INFO_KEY);
    return v && typeof v === "object" ? (v as AdminInfo) : {};
  } catch {
    return {};
  }
}

/** 清除管理端会话（切换账号/退出） */
export function clearAdminSession() {
  try {
    uni.removeStorageSync(ADMIN_TOKEN_KEY);
    uni.removeStorageSync(ADMIN_INFO_KEY);
  } catch {
    /* ignore */
  }
}

/** 是否已登录管理端 */
export function isAdminLoggedIn(): boolean {
  return getAdminToken() !== "";
}

/** 未登录时跳管理端登录页（页面 onLoad 调用） */
export function guardAdmin(): boolean {
  if (isAdminLoggedIn()) return true;
  uni.reLaunch({ url: ADMIN_LOGIN_PAGE });
  return false;
}

/* ================= 请求封装 ================= */

interface AdminRequestOptions {
  url: string;
  method?: "GET" | "POST";
  data?: Record<string, unknown>;
  /** 401 时是否自动跳登录页（列表加载类传 false 走空态） */
  redirectOn401?: boolean;
}

/** 管理端请求：token 头 + code=0 信封；404/500/网络错误一律 reject */
export function adminRequest<T>(options: AdminRequestOptions): Promise<T> {
  const header: Record<string, string> = {
    "X-Tenant-Id": String(getTenantId()),
  };
  const token = getAdminToken();
  if (token) header.token = token;

  return new Promise<T>((resolve, reject) => {
    uni.request({
      url: getApiBase() + options.url,
      method: options.method || "GET",
      data: options.data,
      header,
      timeout: 15000,
      success: (res) => {
        // HTTP 层异常（404/500 等）：reject，页面展示空态
        if (res.statusCode < 200 || res.statusCode >= 300) {
          if (res.statusCode === 401 && options.redirectOn401) {
            clearAdminSession();
            uni.reLaunch({ url: ADMIN_LOGIN_PAGE });
          }
          reject(new Error("http " + res.statusCode));
          return;
        }
        const body = res.data as AdminResult<T>;
        if (!body || typeof body !== "object" || typeof body.code !== "number") {
          reject(new Error("invalid response"));
          return;
        }
        if (body.code === 401) {
          clearAdminSession();
          uni.reLaunch({ url: ADMIN_LOGIN_PAGE });
          reject(new Error("unauthorized"));
          return;
        }
        if (body.code !== 0) {
          reject(body);
          return;
        }
        resolve(body.data);
      },
      fail: (err) => reject(err),
    });
  });
}

/* ================= 接口函数 ================= */

/** 登录 POST /api/adminidx/login */
export function adminLogin(un: string, pwd: string) {
  return adminRequest<{ token: string; admin: AdminInfo }>({
    url: "/api/adminidx/login",
    method: "POST",
    data: { un, pwd },
  });
}

/** 首页统计 GET /api/adminidx/stats */
export function fetchAdminStats() {
  return adminRequest<AdminStats>({ url: "/api/adminidx/stats" });
}

/** 订单列表 GET /api/adminidx/orderlist（st='' 全部，4=退款售后） */
export function fetchAdminOrderList(st: number | "", page: number) {
  return adminRequest<unknown>({
    url: "/api/adminidx/orderlist",
    data: { st, page },
  });
}

/** 商品列表 GET /api/adminidx/productlist */
export function fetchAdminProductList(page: number) {
  return adminRequest<unknown>({
    url: "/api/adminidx/productlist",
    data: { page },
  });
}

/** 会员列表 GET /api/adminidx/memberlist */
export function fetchAdminMemberList(page: number) {
  return adminRequest<unknown>({
    url: "/api/adminidx/memberlist",
    data: { page },
  });
}

/** 财务概览 GET /api/adminidx/finances */
export function fetchAdminFinances() {
  return adminRequest<unknown>({ url: "/api/adminidx/finances" });
}

/** 商品上下架 POST /api/adminidx/productstatus */
export function setAdminProductStatus(id: number, status: number) {
  return adminRequest<unknown>({
    url: "/api/adminidx/productstatus",
    method: "POST",
    data: { id, status },
  });
}

/** 修改密码 POST /api/adminidx/setpwd */
export function setAdminPwd(oldpwd: string, newpwd: string) {
  return adminRequest<unknown>({
    url: "/api/adminidx/setpwd",
    method: "POST",
    data: { oldpwd, newpwd },
  });
}

/* ================= 通用容错解析 ================= */

/**
 * 后端并行开发中，列表返回结构可能为 {list,total} / {data,total} / 数组，
 * 统一归一化为 {list,total}，任何异常返回空列表（页面不白屏）
 */
export function normalizePage<T = Record<string, unknown>>(
  res: unknown
): { list: T[]; total: number } {
  if (Array.isArray(res)) {
    return { list: res as T[], total: (res as unknown[]).length };
  }
  if (res && typeof res === "object") {
    const obj = res as Record<string, unknown>;
    const raw = obj.list ?? obj.data ?? obj.items ?? obj.rows;
    if (Array.isArray(raw)) {
      const total = Number(obj.total ?? obj.count ?? 0);
      return { list: raw as T[], total: Number.isFinite(total) ? total : raw.length };
    }
  }
  return { list: [], total: 0 };
}

/** 安全取对象字段字符串 */
export function fieldText(item: Record<string, unknown>, keys: string[], fallback = "--"): string {
  for (const k of keys) {
    const v = item[k];
    if (v !== undefined && v !== null && v !== "") return String(v);
  }
  return fallback;
}

/** 安全取数字字段 */
export function fieldNum(item: Record<string, unknown>, keys: string[], fallback = 0): number {
  for (const k of keys) {
    const n = Number(item[k]);
    if (Number.isFinite(n)) return n;
  }
  return fallback;
}
