import { post } from "../utils/request";
import type { MemberInfo } from "../stores/session";

/** 登录成功返回结构（member 为契约字段，userinfo 为当前后端实际字段，双兼容） */
export interface LoginResult {
  token: string;
  member?: MemberInfo;
  userinfo?: MemberInfo;
}

/**
 * 手机号+密码登录
 * POST /api/member/login（后端读取 phone，mobile 一并携带以兼容契约差异）
 */
export function memberLogin(phone: string, password: string) {
  return post<LoginResult>("/api/member/login", {
    phone,
    mobile: phone,
    password,
  });
}

/**
 * 微信 code 静默登录（MP-WEIXIN 条件编译场景）
 * POST /api/member/wxlogin
 */
export function memberWxLogin(code: string) {
  return post<LoginResult>("/api/member/wxlogin", { code });
}
