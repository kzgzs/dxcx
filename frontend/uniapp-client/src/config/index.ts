/**
 * 环境配置（统一出口）
 *
 * - 租户与 API 网关已改为运行时解析，见 ./tenant（ext.json → siteinfo → 默认）
 * - dev（H5）：未配置 VITE_API_BASE 时 API 前缀为空串，走 vite devServer 的 /api 代理到 http://127.0.0.1:8080
 * - prod 小程序：优先使用发布链 ext.json 注入的 baseurl
 * - prod H5：同域子路径部署（前缀空串）或 .env 配置 VITE_API_BASE
 */
export { getTenantId, getApiBase, getTenantSource, DEFAULT_TENANT_ID } from "./tenant";
export type { ExtConfig, TenantSource } from "./tenant";

/** 登录页路径 */
export const LOGIN_PAGE = "/pages/login/login";
