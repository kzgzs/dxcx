import axios from 'axios'
import { ElMessage } from 'element-plus'
import type { ApiResponse } from './types'

export const TOKEN_KEY = 'token'
export const USERINFO_KEY = 'userinfo'
export const ROLE_KEY = 'role'

// 扩展 axios 请求配置：silentError 为 true 时不弹错误提示（用于静默探测接口）
declare module 'axios' {
  export interface AxiosRequestConfig {
    silentError?: boolean
  }
}

// 统一的 axios 实例：自动携带 token，统一处理业务 code 与 401
const request = axios.create({
  baseURL: '/',
  timeout: 15000,
})

// 跳转到登录页（避免与 router 循环依赖，直接用 location）
function redirectToLogin() {
  localStorage.removeItem(TOKEN_KEY)
  localStorage.removeItem(USERINFO_KEY)
  localStorage.removeItem(ROLE_KEY)
  // 登录已过期：寄存的超管控制台会话同样作废，避免恢复出失效 token
  localStorage.removeItem('super_back_token')
  localStorage.removeItem('super_back_userinfo')
  if (!location.hash.includes('/login')) {
    location.hash = '#/login'
  }
}

// 请求拦截：带上 Authorization: Bearer <token>
request.interceptors.request.use((config) => {
  const token = localStorage.getItem(TOKEN_KEY)
  if (token) {
    config.headers.Authorization = `Bearer ${token}`
  }
  return config
})

// 响应拦截：统一处理 {code, msg, data}
request.interceptors.response.use(
  (response) => {
    const res = response.data as ApiResponse
    // 兼容非标准响应（理论上不会出现）
    if (!res || typeof res.code !== 'number') {
      return response.data
    }
    if (res.code === 401) {
      ElMessage.error('登录已过期，请重新登录')
      redirectToLogin()
      return Promise.reject(new Error(res.msg || '未授权'))
    }
    if (res.code !== 0) {
      if (!response.config?.silentError) {
        ElMessage.error(res.msg || '请求失败')
      }
      return Promise.reject(new Error(res.msg || '请求失败'))
    }
    return res.data
  },
  (error) => {
    const status = error.response?.status
    const backendMsg = error.response?.data?.msg
    const reqUrl = String(error.config?.url || '')
    const isLoginReq = reqUrl.includes('/console/login') || reqUrl.includes('/admin/login')
    const silent = error.config?.silentError === true
    if (status === 401) {
      if (backendMsg && isLoginReq) {
        // 登录接口的 401 表示账号/密码错误，直接展示后端提示，不做跳转
        if (!silent) ElMessage.error(backendMsg)
      } else {
        // 其余 401 视为登录过期：优先展示后端 msg，无 msg 则提示登录过期并跳转登录页
        // 静默探测请求（silentError）不清会话不跳转，由调用方自行处理
        if (!silent) {
          ElMessage.error(backendMsg || '登录已过期，请重新登录')
          redirectToLogin()
        }
      }
    } else if (!silent) {
      ElMessage.error(backendMsg || error.message || '网络异常')
    }
    return Promise.reject(error)
  },
)

export default request
