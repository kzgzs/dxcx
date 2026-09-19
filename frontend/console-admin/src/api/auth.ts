import request from './request'
import type { AdminProfile, ConsoleProfile, LoginResult } from './types'

// 超管登录（silent 为 true 时不弹错误提示；需携带图形验证码）
export function login(
  data: {
    username: string
    password: string
    captcha_id: string
    captcha: string
  },
  silent = false,
) {
  return request.post<unknown, LoginResult>('/console/login', data, {
    silentError: silent,
  })
}

// 租户（admin）登录
export function adminLogin(data: { username: string; password: string }) {
  return request.post<unknown, LoginResult>('/admin/login', data)
}

// 超管资料
export function getConsoleProfile(silent = false) {
  return request.get<unknown, ConsoleProfile>('/console/index/profile', {
    silentError: silent,
  })
}

// 租户资料（含租户信息）
export function getAdminProfile(silent = false) {
  return request.get<unknown, AdminProfile>('/admin/index/profile', {
    silentError: silent,
  })
}

// 超管修改登录密码（当页提交，不刷新不跳转）
export function editConsolePassword(data: {
  old_password: string
  new_password: string
}) {
  return request.post<unknown, null>('/console/index/editPassword', data)
}

// 租户（admin）修改登录密码（当页提交，不刷新不跳转）
export function editAdminPassword(data: {
  old_password: string
  new_password: string
}) {
  return request.post<unknown, null>('/admin/index/editPassword', data)
}
