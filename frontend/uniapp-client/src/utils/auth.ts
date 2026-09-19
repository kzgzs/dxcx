import { LOGIN_PAGE } from "../config";
import { request } from "./request";
import { useSessionStore, type MemberInfo } from "../stores/session";

/** wxlogin 返回结构（与 api/member.ts LoginResult 对齐） */
interface WxLoginResult {
  token: string;
  member?: MemberInfo;
  userinfo?: MemberInfo;
}

/** 静默登录单飞：并发 401 续期共用同一次 wx.login */
let wxLoginInflight: Promise<boolean> | null = null;

/**
 * 静默微信登录（不阻塞、不弹错）
 * - MP-WEIXIN：uni.login 取 code
 * - H5：无微信容器，生成 mock code（后端 WX.MOCK=1 时非空即过）
 * - 其它端：直接返回 false
 * 成功写入 session；失败静默返回 false，由调用方引导手动登录
 */
export function silentWxLogin(): Promise<boolean> {
  if (!wxLoginInflight) {
    wxLoginInflight = doSilentWxLogin().finally(() => {
      wxLoginInflight = null;
    });
  }
  return wxLoginInflight;
}

async function doSilentWxLogin(): Promise<boolean> {
  const code = await fetchLoginCode();
  if (!code) return false;
  try {
    const data = await request<WxLoginResult>({
      url: "/api/member/wxlogin",
      method: "POST",
      data: { code },
      silent: true,
    });
    if (!data || !data.token) return false;
    const session = useSessionStore();
    session.setSession(data.token, data.member || data.userinfo || {});
    return true;
  } catch {
    return false;
  }
}

/** 获取 wx.login code；拿不到返回空串 */
function fetchLoginCode(): Promise<string> {
  return new Promise((resolve) => {
    // #ifdef MP-WEIXIN
    uni.login({
      provider: "weixin",
      success: (res) => resolve(res.code || ""),
      fail: () => resolve(""),
    });
    return;
    // #endif
    // #ifdef H5
    // H5 无微信容器：用本机持久化的稳定 mock code（后端 WX.MOCK 下 openid=md5(code)），
    // 保证静默续期始终命中同一会员，不会每次生成新账号
    let h5code = "";
    try {
      h5code = String(uni.getStorageSync("dxcx_h5_wxcode") || "");
    } catch {
      h5code = "";
    }
    if (!h5code) {
      h5code = "h5mock_" + Date.now() + "_" + Math.random().toString(36).slice(2, 8);
      try {
        uni.setStorageSync("dxcx_h5_wxcode", h5code);
      } catch {
        /* ignore */
      }
    }
    resolve(h5code);
    return;
    // #endif
    // #ifndef MP-WEIXIN || H5
    resolve("");
    // #endif
  });
}

/* ================= 扫码点餐带参：scene / URL → tableId ================= */

const TABLE_ID_KEY = "dinein_table_id";

/**
 * 解析启动/唤起参数中的桌台 ID 并存 storage
 * - 小程序扫普通二维码：options.query.tableId（或 scene 为 URL 编码 query）
 * - 小程序场景值模式：scene 形如 "tableId=5"
 * - H5：URL ?tableId=5（支持 hash 前后两处 query）
 */
export function applyLaunchTableId(options?: Record<string, unknown>): void {
  const tableId = extractTableId(options);
  if (tableId > 0) {
    uni.setStorageSync(TABLE_ID_KEY, tableId);
  }
  // #ifdef H5
  const fromUrl = extractTableIdFromH5Url();
  if (fromUrl > 0) {
    uni.setStorageSync(TABLE_ID_KEY, fromUrl);
  }
  // #endif
}

function extractTableId(options?: Record<string, unknown>): number {
  if (!options) return 0;
  // 1) 普通链接二维码：query 已解析
  const query = options.query as Record<string, unknown> | undefined;
  if (query && query.tableId !== undefined) {
    const v = Number(query.tableId);
    if (v > 0) return v;
  }
  if (query && query.table_id !== undefined) {
    const v = Number(query.table_id);
    if (v > 0) return v;
  }
  // 2) scene 为 URL 编码的 query 串（如 tableId%3D5）或明文 tableId=5
  const scene = typeof options.scene === "string" ? options.scene : "";
  if (scene) {
    const decoded = decodeURIComponent(scene);
    const m = decoded.match(/tableId=(\d+)/i) || decoded.match(/table_id=(\d+)/i);
    if (m) {
      const v = Number(m[1]);
      if (v > 0) return v;
    }
  }
  // 3) 页面直传参数
  for (const key of ["tableId", "table_id"]) {
    if (options[key] !== undefined) {
      const v = Number(options[key]);
      if (v > 0) return v;
    }
  }
  return 0;
}

/** H5：从地址栏解析 tableId（兼容 ?tableId= 在 hash 前或后） */
export function extractTableIdFromH5Url(): number {
  // #ifdef H5
  try {
    const href = window.location.href;
    const m = href.match(/[?&]tableId=(\d+)/i) || href.match(/[?&]table_id=(\d+)/i);
    if (m) return Number(m[1]) || 0;
  } catch {
    /* ignore */
  }
  // #endif
  return 0;
}

/** 读取已存桌台 ID */
export function getStoredTableId(): number {
  return Number(uni.getStorageSync(TABLE_ID_KEY) || 0);
}

/** 跳登录页（带来源回跳） */
export function gotoLogin(redirect?: string) {
  const url = redirect ? LOGIN_PAGE + "?redirect=" + encodeURIComponent(redirect) : LOGIN_PAGE;
  uni.navigateTo({ url });
}
