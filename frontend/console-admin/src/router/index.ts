import { createRouter, createWebHashHistory } from 'vue-router'
import type { RouteRecordRaw } from 'vue-router'
import { ROLE_KEY, TOKEN_KEY } from '../api/request'
import type { Role } from '../api/types'

/**
 * 单入口架构（对齐参考系统）：控制台、客户端各只有一个固定地址
 * 地址结构仿参考系统 ?s=/WebSystem/index 与 ?s=/Backstage/index，换用专用英文词：
 * - /login           登录页（含 ?token= 代登录直登通道）
 * - /Console/index   超管壳（views/Layout.vue），菜单切换只动壳内组件，URL 不变
 * - /Merchant/index  租户壳（views/admin/AdminLayout.vue），同上
 * - /designer/:type  独立设计页（新标签进入，不走壳内菜单切换）
 * 二级页面不再走路由，由壳层菜单配置中心 + 动态组件渲染
 * 旧路径 / 与 /admin 保留为重定向，旧书签/代登录链接不失效
 */

// 各角色对应的唯一入口（未登录回 /login 由全局守卫处理）
function homeOf(role: Role | ''): string {
  return role === 'admin' ? '/Merchant/index' : '/Console/index'
}

// 旧路径重定向：按当前角色跳到对应新路径，未登录回 /login
function legacyRedirect(): string {
  const role = (localStorage.getItem(ROLE_KEY) as Role) || ''
  return role ? homeOf(role) : '/login'
}

const routes: RouteRecordRaw[] = [
  {
    path: '/login',
    name: 'Login',
    component: () => import('../views/Login.vue'),
    meta: { public: true, title: '登录' },
  },
  // 官网会员注册页（与系统设置 reg_open/sms_verify 联动）
  {
    path: '/register',
    name: 'Register',
    component: () => import('../views/Register.vue'),
    meta: { public: true, title: '会员注册' },
  },
  // 排队叫号大屏（独立公开页，无需登录；投放店内显示屏，?aid= 指定租户）
  {
    path: '/queue/screen',
    name: 'QueueScreen',
    component: () => import('../views/admin/RestaurantQueueScreen.vue'),
    meta: { public: true, title: '排队叫号' },
  },
  // ============ 超管侧：唯一入口 ============
  {
    path: '/Console/index',
    name: 'Console',
    component: () => import('../views/Layout.vue'),
    meta: { role: 'console', title: '超管控制台' },
  },
  // ============ 租户侧：唯一入口 ============
  {
    path: '/Merchant/index',
    name: 'Admin',
    component: () => import('../views/admin/AdminLayout.vue'),
    meta: { role: 'admin', title: '租户后台' },
  },
  // 页面设计器编辑器（列表页「编辑/新建」新开标签进入，需先于 /designer/:type 注册）
  {
    path: '/designer/editor',
    name: 'DesignerEditor',
    component: () => import('../views/admin/DesignerEditor.vue'),
    meta: { role: 'admin', title: '页面设计器' },
  },
  // 独立设计页：设计列表「编辑」新开标签进入，仅 admin 角色可访问（需 token）
  {
    path: '/designer/:type',
    name: 'Designer',
    component: () => import('../views/admin/DesignerPage.vue'),
    meta: { role: 'admin', title: '设计器' },
  },
  // 收银台操作页（对齐点大：菜单不显示，收银设置页「收银台地址」链接经此独立路由打开）
  {
    path: '/cashierdesk',
    name: 'CashierDesk',
    component: () => import('../views/admin/CashierDesk.vue'),
    meta: { role: 'admin', title: '收银台' },
  },
  // ============ 旧路径兼容：重定向到角色对应新入口 ============
  { path: '/', redirect: legacyRedirect },
  { path: '/admin', redirect: legacyRedirect },
  // 旧多级路由/未知路径：按角色回到各自唯一入口
  {
    path: '/:pathMatch(.*)*',
    redirect: legacyRedirect,
  },
]

const router = createRouter({
  history: createWebHashHistory(),
  routes,
})

// 全局路由守卫：登录页免鉴权，其余路由校验 token；按角色放行，越权重定向
router.beforeEach((to) => {
  // 官网注册按钮统一指向 /#/login?mode=reg，这里重定向到注册页
  if (to.path === '/login' && to.query.mode === 'reg') {
    return { path: '/register' }
  }

  const token = localStorage.getItem(TOKEN_KEY)
  const role = (localStorage.getItem(ROLE_KEY) as Role) || ''

  if (!to.meta.public && !token) {
    return { path: '/login', query: { redirect: to.fullPath } }
  }

  if (to.path === '/login') {
    // URL 携带 token 的直登通道，交给登录页组件处理
    if (to.query.token) return
    if (token) return { path: homeOf(role) }
  }

  // 角色越权：访问非本角色路由时重定向回自己的唯一入口
  const needRole = to.meta.role as Role | undefined
  if (needRole && role && role !== needRole) {
    return { path: homeOf(role) }
  }
})

router.afterEach((to) => {
  const role = (localStorage.getItem(ROLE_KEY) as Role) || ''
  const suffix = role === 'admin' ? '鼎极多端·租户后台' : '鼎极多端·超管控制台'
  const title = to.meta.title as string | undefined
  document.title = title && to.meta.public ? `${title} - 鼎极多端` : suffix
})

export default router
