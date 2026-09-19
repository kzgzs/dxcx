import { LOGIN_PAGE } from "../config";
import { getApiBase, getTenantId } from "../config/tenant";
import { useSessionStore } from "../stores/session";

/** 后端统一响应信封 */
export interface ApiResult<T = unknown> {
  code: number;
  msg: string;
  data: T;
}

interface RequestOptions {
  url: string;
  method?: "GET" | "POST" | "PUT" | "DELETE";
  data?: Record<string, unknown>;
  header?: Record<string, string>;
  /** 不弹 toast（调用方自行处理错误） */
  silent?: boolean;
  /** 内部标记：401 静默续期后已重试过一次，防止死循环 */
  _renewed?: boolean;
}

/** 防止 401 并发时重复跳转 */
let redirecting = false;

function toast(message: string) {
  uni.showToast({ title: message, icon: "none", duration: 2000 });
}

function redirectToLogin() {
  if (redirecting) return;
  redirecting = true;
  uni.reLaunch({
    url: LOGIN_PAGE,
    complete: () => {
      setTimeout(() => {
        redirecting = false;
      }, 500);
    },
  });
}

/**
 * 401 统一处理：先尝试一次静默续期（wx.login → member/wxlogin），
 * 成功则用新 token 重放原请求；失败再清会话跳登录页
 */
async function handleUnauthorized<T>(
  options: RequestOptions,
  resolve: (value: T) => void,
  reject: (reason: unknown) => void
): Promise<void> {
  const session = useSessionStore();
  session.clearSession();

  if (!options._renewed) {
    try {
      const { silentWxLogin } = await import("./auth");
      const ok = await silentWxLogin();
      if (ok) {
        request<T>({ ...options, _renewed: true }).then(resolve, reject);
        return;
      }
    } catch {
      /* 续期失败走登出引导 */
    }
  }

  if (!options.silent) toast("登录已过期，请重新登录");
  redirectToLogin();
  reject(new Error("unauthorized"));
}

/**
 * 统一请求封装
 * - baseURL 走运行时解析 getApiBase()（ext.baseurl > VITE_API_BASE > siteinfo；
 *   均为空时 dev 走 vite proxy /api → 127.0.0.1:8080，prod 走同域子路径）
 * - 自动携带 Authorization: Bearer {token} 与 X-Tenant-Id（getTenantId() 运行时解析）
 * - 响应信封 {code,msg,data}：code!==0 弹 toast 并 reject
 * - 401/未登录：清会话并跳登录页
 */
export function request<T>(options: RequestOptions): Promise<T> {
  const session = useSessionStore();
  const header: Record<string, string> = {
    "X-Tenant-Id": String(getTenantId()),
    ...(options.header || {}),
  };
  if (session.token) {
    header.Authorization = "Bearer " + session.token;
  }

  return new Promise<T>((resolve, reject) => {
    uni.request({
      url: getApiBase() + options.url,
      method: options.method || "GET",
      data: options.data,
      header,
      timeout: 15000,
      success: (res) => {
        // HTTP 401：token 失效
        if (res.statusCode === 401) {
          void handleUnauthorized<T>(options, resolve, reject);
          return;
        }
        const body = res.data as ApiResult<T>;
        if (!body || typeof body !== "object" || typeof body.code !== "number") {
          if (!options.silent) toast("响应格式异常");
          reject(new Error("invalid response"));
          return;
        }
        // 业务 401：未登录/token 无效
        if (body.code === 401) {
          void handleUnauthorized<T>(options, resolve, reject);
          return;
        }
        if (body.code !== 0) {
          if (!options.silent) toast(body.msg || "请求失败");
          reject(body);
          return;
        }
        resolve(body.data);
      },
      fail: (err) => {
        if (!options.silent) toast("网络异常，请稍后重试");
        reject(err);
      },
    });
  });
}

export function get<T>(url: string, data?: Record<string, unknown>): Promise<T> {
  return request<T>({ url, method: "GET", data });
}

export function post<T>(url: string, data?: Record<string, unknown>): Promise<T> {
  return request<T>({ url, method: "POST", data });
}

/**
 * 文件上传封装（POST multipart，字段 file）
 * - 同样携带 Authorization 与 X-Tenant-Id
 * - 响应信封 {code,msg,data}，code!==0 弹 toast 并 reject
 */
export function uploadFile<T>(url: string, filePath: string): Promise<T> {
  const session = useSessionStore();
  const header: Record<string, string> = { "X-Tenant-Id": String(getTenantId()) };
  if (session.token) {
    header.Authorization = "Bearer " + session.token;
  }

  return new Promise<T>((resolve, reject) => {
    uni.uploadFile({
      url: getApiBase() + url,
      filePath,
      name: "file",
      header,
      success: (res) => {
        let body: ApiResult<T> | null = null;
        try {
          body = JSON.parse(res.data as string) as ApiResult<T>;
        } catch {
          body = null;
        }
        if (!body || typeof body.code !== "number") {
          toast("上传失败，请重试");
          reject(new Error("invalid response"));
          return;
        }
        if (body.code === 401) {
          void handleUnauthorized<T>({ url, method: "POST" }, resolve, reject);
          return;
        }
        if (body.code !== 0) {
          toast(body.msg || "上传失败");
          reject(body);
          return;
        }
        resolve(body.data);
      },
      fail: () => {
        toast("网络异常，请稍后重试");
        reject(new Error("network error"));
      },
    });
  });
}
