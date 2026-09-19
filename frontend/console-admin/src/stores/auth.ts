import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { ElMessage } from 'element-plus'
import {
  adminLogin,
  getAdminProfile,
  getConsoleProfile,
  login as consoleLoginApi,
} from '../api/auth'
import { ROLE_KEY, TOKEN_KEY, USERINFO_KEY } from '../api/request'
import type { AdminProfile, LoginResult, Role, UserInfo } from '../api/types'

// 安全读取本地缓存的用户信息：遇到损坏或跨版本脏数据时回退为 null 并清除坏键，
// 避免 JSON.parse 在 store 初始化阶段抛错、连锁导致路由初始导航失败而整页白屏
function loadUserinfo(): UserInfo | null {
  const raw = localStorage.getItem(USERINFO_KEY)
  if (!raw) return null
  try {
    return JSON.parse(raw) as UserInfo
  } catch {
    localStorage.removeItem(USERINFO_KEY)
    return null
  }
}

export const useAuthStore = defineStore('auth', () => {
  const token = ref<string>(localStorage.getItem(TOKEN_KEY) || '')
  const userinfo = ref<UserInfo | null>(loadUserinfo())
  const role = ref<Role | ''>((localStorage.getItem(ROLE_KEY) as Role) || '')
  // 租户资料（含租户公司信息），仅 admin 角色有值
  const adminProfile = ref<AdminProfile | null>(null)

  // 超管回控制台暂存键：代登录进租户壳前寄存控制台会话，
  // 租户壳底部「控制台」入口点击时恢复（活动会话始终单一，后端 token 按角色隔离无法互通）
  const STASH_TOKEN_KEY = 'super_back_token'
  const STASH_USER_KEY = 'super_back_userinfo'

  // 各角色对应的唯一入口（专用英文路径，与 router 保持一致）
  const homePath = computed(() => (role.value === 'admin' ? '/Merchant/index' : '/Console/index'))

  // 写入会话（token 统一一个键，角色单独持久化）
  function setSession(data: LoginResult, r: Role) {
    token.value = data.token
    userinfo.value = data.userinfo
    role.value = r
    localStorage.setItem(TOKEN_KEY, data.token)
    localStorage.setItem(USERINFO_KEY, JSON.stringify(data.userinfo))
    localStorage.setItem(ROLE_KEY, r)
  }

  // 账密登录：先尝试超管登录，失败后静默尝试租户登录（携带图形验证码）
  async function login(
    username: string,
    password: string,
    captchaId = '',
    captcha = '',
  ): Promise<Role> {
    try {
      const data = await consoleLoginApi(
        { username, password, captcha_id: captchaId, captcha },
        true,
      )
      setSession(data, 'console')
      return 'console'
    } catch (e) {
      const msg = e instanceof Error ? e.message : ''
      // 图形验证码错误属于页面级闸门：直接提示并终止，不再静默尝试租户登录
      if (msg.includes('图形验证码')) {
        ElMessage.error(msg)
        throw e
      }
      // 其余错误（如账号或密码错误），继续尝试租户登录
    }
    const data = await adminLogin({ username, password })
    setSession(data, 'admin')
    return 'admin'
  }

  // token 直登（代登录通道）：先存 token，再拉 profile 判角色
  async function loginByToken(rawToken: string): Promise<Role> {
    // 换 token 前先清掉旧角色残留（尤其上一身份是 admin 时的租户资料）
    adminProfile.value = null
    token.value = rawToken
    localStorage.setItem(TOKEN_KEY, rawToken)
    try {
      // 先按租户身份探测
      const profile = await getAdminProfile(true)
      adminProfile.value = profile
      const info: UserInfo = {
        id: profile.id,
        username: profile.username,
        isadmin: profile.isadmin,
        aid: profile.aid,
      }
      setSession({ token: rawToken, userinfo: info }, 'admin')
      return 'admin'
    } catch {
      // 租户身份不成立，继续按超管身份探测
    }
    try {
      const profile = await getConsoleProfile(true)
      const info: UserInfo = {
        id: profile.id,
        username: profile.username,
        isadmin: profile.isadmin,
      }
      setSession({ token: rawToken, userinfo: info }, 'console')
      return 'console'
    } catch {
      logout()
      throw new Error('token 无效或已过期')
    }
  }

  // 拉取租户资料（AdminLayout 顶栏使用）
  async function fetchAdminProfile() {
    const profile = await getAdminProfile()
    adminProfile.value = profile
    return profile
  }

  // 代登录前寄存当前控制台会话（仅 console 角色；租户壳底部「控制台」入口凭此返回）
  function stashConsoleSession() {
    if (role.value !== 'console') return
    const t = localStorage.getItem(TOKEN_KEY)
    const u = localStorage.getItem(USERINFO_KEY)
    if (t) localStorage.setItem(STASH_TOKEN_KEY, t)
    if (u) localStorage.setItem(STASH_USER_KEY, u)
  }

  // 恢复寄存的控制台会话（成功返回 true；无寄存返回 false）
  function restoreConsoleSession(): boolean {
    const t = localStorage.getItem(STASH_TOKEN_KEY)
    if (!t) return false
    const u = localStorage.getItem(STASH_USER_KEY)
    localStorage.setItem(TOKEN_KEY, t)
    localStorage.setItem(ROLE_KEY, 'console')
    if (u) localStorage.setItem(USERINFO_KEY, u)
    else localStorage.removeItem(USERINFO_KEY)
    localStorage.removeItem(STASH_TOKEN_KEY)
    localStorage.removeItem(STASH_USER_KEY)
    token.value = t
    role.value = 'console'
    userinfo.value = (() => { try { return u ? (JSON.parse(u) as UserInfo) : null } catch { return null } })()
    adminProfile.value = null
    return true
  }

  // 退出登录（连同寄存的控制台会话一并清除，避免残留）
  // keepStash=true：代登录/返回客户端的身份切换场景，保留寄存会话供反向返回
  function logout(keepStash = false) {
    token.value = ''
    userinfo.value = null
    role.value = ''
    adminProfile.value = null
    localStorage.removeItem(TOKEN_KEY)
    localStorage.removeItem(USERINFO_KEY)
    localStorage.removeItem(ROLE_KEY)
    if (!keepStash) {
      localStorage.removeItem(STASH_TOKEN_KEY)
      localStorage.removeItem(STASH_USER_KEY)
    }
  }

  return {
    token,
    userinfo,
    role,
    adminProfile,
    homePath,
    login,
    loginByToken,
    fetchAdminProfile,
    stashConsoleSession,
    restoreConsoleSession,
    logout,
  }
})
