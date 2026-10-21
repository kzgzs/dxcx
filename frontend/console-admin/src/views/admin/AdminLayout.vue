<script setup lang="ts">
import { computed, onMounted, provide, reactive, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import type { FormInstance, FormRules } from 'element-plus'
import axios from 'axios'
import { useAuthStore } from '../../stores/auth'
import { editAdminPassword } from '../../api/auth'
// import { formatDate } from '../../utils/time' // unused variable
import { tenantMenus } from '../../menu/tenantMenus'
import type { MenuNode } from '../../menu/menuTypes'
import {
  firstLeafIdOf,
  indexLeaves,
  visibleOf,
} from '../../menu/menuTypes'
import { getAdminMenu } from '../../api/admin'
import {
  getShortcutMenu,
  saveShortcutMenu,
  type ShortcutMenuItem,
} from '../../api/system'
import AdminIcon from './AdminIcon.vue'
import AdminDashboard from './AdminDashboard.vue'
import AdminUnderConstruction from './AdminUnderConstruction.vue'
import AdminHelp from './AdminHelp.vue'
import AdminNotice from './AdminNotice.vue'
import FrontendLinks from '../../components/FrontendLinks.vue'
import MobileAdminModal from '../../components/MobileAdminModal.vue'

const router = useRouter()
const authStore = useAuthStore()

// ===== 通知中心红点计数（实时同步未读数） =====
const unreadNoticeCount = ref(0)
const quickMenuVisible = ref(false)
// 前端链接大弹窗（任务263：查看模式）
const frontLinksVisible = ref(false)
// 手机端管理小弹窗（任务267：对照参考系统顶栏“手机端”入口）
const mobileAdminVisible = ref(false)

/**
 * 单入口壳层：菜单配置中心驱动（10 大主菜单二三级递归）
 * - 点击菜单只切换壳内动态组件，绝不改动 URL（地址栏固定为 /#/Merchant/index）
 * - 默认首屏为欢迎面板 AdminDashboard（哨兵 __dashboard，刷新自然回首屏）
 * - 激活状态只存内存
 *
 * 参考系统式分列交互（对照 ?s=/Backstage/index 的 .wb-subnav）：
 * - 第一列：主菜单一行一项（图标 + 文字，可折叠为纯图标窄列）
 * - 第二列：点击主菜单后紧挨右侧出现，列出其二级菜单
 * - 二级分组若有三级子项，点击分组头直接在该行正下方内嵌展开（非第三列）
 * - 切换主菜单时第二列内容随之切换；再次点击当前主菜单可收起第二列
 */
const DASHBOARD_ID = '__dashboard'
const NOTIFICATION_PAGE_ID = '__notification'
const HELP_PAGE_ID = '__help'
const leafMap = indexLeaves(tenantMenus)
const activeId = ref(DASHBOARD_ID)

const activeNode = computed<MenuNode | undefined>(() => leafMap.get(activeId.value))
const activeComponent = computed(() => {
  if (activeId.value === DASHBOARD_ID) return AdminDashboard
  if (activeId.value === NOTIFICATION_PAGE_ID) return AdminNotice
  if (activeId.value === HELP_PAGE_ID) return AdminHelp
  return activeNode.value?.component || AdminUnderConstruction
})

// 主菜单列折叠（80px 图标 + 文字 / 56px 纯图标）
const collapsed = ref(false)

const tenant = computed(() => authStore.adminProfile?.tenant || null)
const company = computed(
  // 优先取 tenant.company，其次为 username，默认"租户"
  () => tenant.value?.company || authStore.adminProfile?.username || '租户',
)
// const expired = computed(() => tenant.value?.expired === 1) // unused variable
// 超管代登录标记（profile.super=1）：仅此身份显示底部「控制台」回跳入口
const isSuper = computed(() => authStore.adminProfile?.super === 1)

// 一级菜单图标（对齐参考系统首屏）
const TOP_ICON: Record<string, string> = {
  shop: 'shop',
  business: 'merchant',
  member: 'member',
  finance: 'finance',
  yingxiao: 'marketing',
  component: 'extend',
  restaurant: 'restaurant',
  jiemian: 'design',
  pingtai: 'platform',
  system: 'setting',
}

// ===== 分列状态 =====
/**
 * 菜单权限过滤（批次2-C）：
 * GET /admin/menu 按当前账号 auth_data 返回 { mode, keys }；
 * mode=keys 时本地按 key 集合裁剪 tenantMenus（命中的节点整棵保留，
 * 未命中但有授权子孙的分组保留壳），mode=all 全量展示
 */
function filterMenuByKeys(nodes: MenuNode[], allowed: Set<string>): MenuNode[] {
  const result: MenuNode[] = []
  for (const n of nodes) {
    if (allowed.has(n.key)) {
      result.push(n)
      continue
    }
    if (n.child && n.child.length) {
      const kids = filterMenuByKeys(n.child, allowed)
      if (kids.length) result.push({ ...n, child: kids })
    }
  }
  return result
}

const menuTree = ref<MenuNode[]>(tenantMenus)

async function fetchMenuScope() {
  try {
    const data = await getAdminMenu(true)
    if (data && data.mode === 'keys' && !(data.keys || []).includes('*')) {
      menuTree.value = filterMenuByKeys(tenantMenus, new Set(data.keys || []))
    }
  } catch {
    // 静默失败：保留全量菜单（后端接口仍会拦截越权操作）
  }
}

const topMenus = computed(() => visibleOf(menuTree.value))
/** 当前选中的主菜单 key（首屏为空 → 无高亮、无第二列） */
const selectedTopKey = ref('')
/** 第二列是否可见（点击主菜单出现，折叠按钮/再点主菜单隐藏） */
const subVisible = ref(false)
/** 第二列内处于展开态的分组 idPath 集合（手风琴：同层只开一个） */
const openGroups = ref(new Set<string>())

const selectedTop = computed<MenuNode | undefined>(() =>
  topMenus.value.find((t) => t.key === selectedTopKey.value),
)

/** 第二列扁平化行（展开态决定分组子项是否入列，支持任意层级） */
interface SubRow {
  idPath: string
  node: MenuNode
  level: number
  isGroup: boolean
}
const subRows = computed<SubRow[]>(() => {
  const top = selectedTop.value
  if (!top) return []
  const rows: SubRow[] = []
  const walk = (list: MenuNode[], prefix: string, level: number) => {
    for (const n of visibleOf(list)) {
      const idPath = `${prefix}/${n.key}`
      const kids = visibleOf(n.child)
      rows.push({ idPath, node: n, level, isGroup: kids.length > 0 })
      if (kids.length && openGroups.value.has(idPath)) {
        walk(n.child!, idPath, level + 1)
      }
    }
  }
  walk(top.child || [], top.key, 1)
  return rows
})

// 激活叶子变化时同步主菜单高亮（首屏清空）
watch(activeId, (id) => {
  selectedTopKey.value = id === DASHBOARD_ID ? '' : id.split('/')[0]
})

/** 打开通知中心页 */
function openNoticePage() {
  activeId.value = NOTIFICATION_PAGE_ID
  fetchUnreadCount()
}

/** 打开帮助中心页 */
function openHelpPage() {
  activeId.value = HELP_PAGE_ID
}

/** 关闭铃铛/帮助哨兵页：切回首页（任务273：修复 X 点击无反应） */
function closeOverlayPage() {
  activeId.value = DASHBOARD_ID
}

/** 展开 idPath 沿途的所有分组（切换主菜单 / 点叶子时保证激活项可见） */
function openAncestors(idPath: string) {
  const parts = idPath.split('/')
  const next = new Set(openGroups.value)
  for (let i = 1; i < parts.length; i++) next.add(parts.slice(0, i).join('/'))
  openGroups.value = next
}

/** 主菜单点击：右侧出第二列 + 直达该分组第一个叶子（对齐参考系统行为） */
function selectTop(item: MenuNode) {
  // 再点当前主菜单 → 收起/展开第二列（页面不切换）
  if (selectedTopKey.value === item.key && subVisible.value && !collapsed.value) {
    subVisible.value = false
    return
  }
  selectedTopKey.value = item.key
  const hasKids = visibleOf(item.child).length > 0
  subVisible.value = hasKids
  openGroups.value = new Set()
  const id = firstLeafIdOf(item)
  if (leafMap.has(id)) {
    activeId.value = id
    openAncestors(id)
  }
}

/** 二级列叶子点击：只切内部组件，不碰 URL */
function pickLeaf(idPath: string) {
  activeId.value = idPath
  openAncestors(idPath)
}

/**
 * 供子页面（如首页面板）按叶子 key 跳转菜单
 * 子组件通过 inject('adminNavigateByKey') 调用，按 key 反查 idPath 并激活
 * 返回是否找到目标菜单（未找到时调用方可提示，如租户侧无附件设置菜单）
 */
function navigateByLeafKey(key: string): boolean {
  for (const idPath of leafMap.keys()) {
    if (idPath === key || idPath.endsWith(`/${key}`)) {
      pickLeaf(idPath)
      return true
    }
  }
  return false
}
provide('adminNavigateByKey', navigateByLeafKey)
// 暴露当前激活叶子 idPath 给内嵌组件（如 CycleOrder 区分订单管理/配送管理模式）
provide('adminActiveId', activeId)

/** 二级列分组点击：手风琴式内嵌展开（收起兄弟分组） */
function toggleGroup(idPath: string) {
  const next = new Set(openGroups.value)
  if (next.has(idPath)) {
    next.delete(idPath)
  } else {
    const parentPrefix = idPath.slice(0, idPath.lastIndexOf('/'))
    for (const k of [...next]) {
      if (k === parentPrefix || k.startsWith(`${parentPrefix}/`)) next.delete(k)
    }
    next.add(idPath)
  }
  openGroups.value = next
}

function isGroupOnPath(row: SubRow): boolean {
  return activeId.value.startsWith(`${row.idPath}/`)
}

function goDashboard() {
  activeId.value = DASHBOARD_ID
}

/**
 * 底部「控制台」：跨壳跳回超管控制台（路由 /#/Console/index），仅 super 可见
 * 后端 token 按角色隔离（admin token 调不通 console 接口），必须先同步恢复
 * 代登录前寄存的控制台会话（token/role 写入 localStorage），再 push：
 * 路由守卫按 localStorage 的 role 做角色隔离，若先 push 后恢复会被守卫
 * 按 role=admin 弹回 /Merchant/index（当前页），push 静默失败表现为点击无反应
 */
function goSuperConsole() {
  const restored = authStore.restoreConsoleSession()
  if (restored) {
    // 会话已切回 console，守卫放行；catch 吞掉重复导航等异常避免未捕获报错
    router.push('/Console/index').catch(() => {})
    return
  }
  // 无寄存会话（已被清理或过期作废）：无法恢复超管身份，
  // 直跳会被守卫弹回当前页（点击无反应），提示并回登录页重新登录
  ElMessage.warning('控制台会话已失效，请重新登录')
  authStore.logout()
  router.push('/login').catch(() => {})
}

// 标题同步（菜单切换不走路由，这里自行维护）
function syncTitle() {
  const name = activeNode.value?.name || ''
  document.title = name ? `${name} - 鼎极多端·租户后台` : '鼎极多端·租户后台'
}
watch(activeId, syncTitle, { immediate: true })

// ===== 快捷菜单（顶栏星标收藏 + 抽屉快捷入口，批次2-C） =====
const shortcutMenus = ref<ShortcutMenuItem[]>([])

async function fetchShortcutMenus() {
  try {
    const data = await getShortcutMenu()
    shortcutMenus.value = data?.menus || []
  } catch {
    // 静默失败：快捷入口为空不影响主流程
  }
}

/** 当前激活的菜单叶子 key（哨兵页/首屏返回空，不显示星标） */
const currentLeafKey = computed(() => {
  if (
    activeId.value === DASHBOARD_ID ||
    activeId.value === NOTIFICATION_PAGE_ID ||
    activeId.value === HELP_PAGE_ID
  ) {
    return ''
  }
  return activeId.value.split('/').pop() || ''
})

const currentStarred = computed(() =>
  shortcutMenus.value.some((m) => m.key === currentLeafKey.value),
)

/** 星标收藏/取消收藏当前页面 */
async function toggleStar() {
  const key = currentLeafKey.value
  if (!key) return
  const name = activeNode.value?.name || key
  let next: ShortcutMenuItem[]
  let added: boolean
  if (shortcutMenus.value.some((m) => m.key === key)) {
    next = shortcutMenus.value.filter((m) => m.key !== key)
    added = false
  } else {
    next = [...shortcutMenus.value, { name, key }]
    added = true
  }
  try {
    await saveShortcutMenu(next)
    shortcutMenus.value = next
    ElMessage.success(added ? '已加入收藏快捷菜单' : '已取消收藏')
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  }
}

/** 打开快捷菜单抽屉（每次打开拉最新） */
function openQuickMenu() {
  quickMenuVisible.value = true
  fetchShortcutMenus()
}

/** 点击快捷入口：按叶子 key 切壳内组件 */
function gotoShortcut(item: ShortcutMenuItem) {
  quickMenuVisible.value = false
  navigateByLeafKey(item.key)
}

/** 抽屉内移除某快捷入口 */
async function removeShortcut(item: ShortcutMenuItem) {
  const next = shortcutMenus.value.filter((m) => m.key !== item.key)
  try {
    await saveShortcutMenu(next)
    shortcutMenus.value = next
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  }
}

/** 抽屉内跳转快捷菜单设置页 */
function gotoShortcutSet() {
  quickMenuVisible.value = false
  navigateByLeafKey('shortcut-menu-index')
}

function handleLogout() {
  ElMessageBox.confirm('确定要退出登录吗？', '提示', {
    confirmButtonText: '退出',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(() => {
      authStore.logout()
      ElMessage.success('已退出登录')
      router.push('/login')
    })
    .catch(() => {})
}

// 全屏切换
function toggleFullScreen() {
  if (!document.fullscreenElement) {
    document.documentElement.requestFullscreen().catch(() => {})
  } else {
    if (document.exitFullscreen) {
      document.exitFullscreen().catch(() => {})
    }
  }
}

// 刷新主区域内容（不整页 reload）
const refreshKey = ref(0)
function handleRefresh() {
  refreshKey.value++
}

// 用户名下拉命令处理（修改密码 / 退出登录）
function handleUserCommand(command: string) {
  if (command === 'logout') {
    handleLogout()
  } else if (command === 'editPassword') {
    openPwdDialog()
  }
}

// ===== 修改密码弹窗（当页提交，原地提示，不刷新不跳转） =====
const pwdVisible = ref(false)
const pwdSaving = ref(false)
const pwdFormRef = ref<FormInstance>()
const pwdForm = reactive({
  old_password: '',
  new_password: '',
  confirm_password: '',
})
function validateConfirm(_rule: unknown, value: string, callback: (e?: Error) => void) {
  if (value !== pwdForm.new_password) {
    callback(new Error('两次输入的密码不一致'))
  } else {
    callback()
  }
}
const pwdRules = reactive<FormRules>({
  old_password: [{ required: true, message: '请输入原密码', trigger: 'blur' }],
  new_password: [
    { required: true, message: '请输入新密码', trigger: 'blur' },
    { min: 6, max: 32, message: '密码长度需为 6-32 位', trigger: 'blur' },
  ],
  confirm_password: [
    { required: true, message: '请再次输入新密码', trigger: 'blur' },
    { validator: validateConfirm, trigger: 'blur' },
  ],
})
function openPwdDialog() {
  pwdForm.old_password = ''
  pwdForm.new_password = ''
  pwdForm.confirm_password = ''
  pwdVisible.value = true
}
async function submitPwd() {
  if (!pwdFormRef.value) return
  await pwdFormRef.value.validate(async (valid) => {
    if (!valid) return
    pwdSaving.value = true
    try {
      await editAdminPassword({
        old_password: pwdForm.old_password,
        new_password: pwdForm.new_password,
      })
      ElMessage.success('密码修改成功')
      pwdVisible.value = false
    } catch {
      // 错误提示已由 axios 拦截器统一弹出
    } finally {
      pwdSaving.value = false
    }
  })
}

// ===== 配色方案（三点下拉内切换主题，CSS 变量驱动，localStorage 持久化） =====
const THEMES = [
  { key: 'default', label: '默认蓝紫', primary: 'var(--primary)' },
  { key: 'green', label: '清新绿', primary: '#2fa97c' },
  { key: 'orange', label: '活力橙', primary: '#ef8f3b' },
  { key: 'purple', label: '优雅紫', primary: '#9a6bd8' },
] as const
const theme = ref<string>(localStorage.getItem('admin-theme') || 'default')
function setTheme(key: string) {
  theme.value = key
  localStorage.setItem('admin-theme', key)
  const label = THEMES.find((t) => t.key === key)?.label || key
  ElMessage.success(`已切换到 ${label}`)
}

onMounted(async () => {
  // @ts-ignore
  await authStore.fetchAdminProfile().catch(() => {})
  // 菜单权限过滤 + 快捷菜单加载（批次2-C）
  fetchMenuScope()
  fetchShortcutMenus()
  // 启动未读数定时刷新
  try {
    const token = localStorage.getItem('token')
    if (token) {
      const API_BASE = import.meta.env.VITE_API_BASE_URL || ''
      const res = await axios.get(`${API_BASE}/admin/notice/mine`, {
        headers: { Authorization: `Bearer ${token}` },
      })
      if (res.data.code === 0 && res.data.data?.unread_count !== undefined) {
        unreadNoticeCount.value = res.data.data.unread_count || 0
      }
      setInterval(async () => {
        const r = await axios.get(`${API_BASE}/admin/notice/mine`, {
          headers: { Authorization: `Bearer ${token}` },
        })
        if (r.data.code === 0 && r.data.data?.unread_count !== undefined) {
          unreadNoticeCount.value = r.data.data.unread_count || 0
        }
      }, 30000)
    }
  } catch (e) {
    console.error('启动未读数定时器失败:', e)
  }
})

// 获取未读数
async function fetchUnreadCount() {
  try {
    const token = localStorage.getItem('token')
    if (!token) return
    
    const API_BASE = import.meta.env.VITE_API_BASE_URL || ''
    const res = await axios.get(`${API_BASE}/admin/notice/mine`, {
      headers: {
        Authorization: `Bearer ${token}`,
      },
    })
    if (res.data.code === 0 && res.data.data?.unread_count !== undefined) {
      unreadNoticeCount.value = res.data.data.unread_count || 0
    }
  } catch (error) {
    console.error('获取未读数失败:', error)
  }
}



</script>

<template>
  <div class="admin-layout" :class="`theme-${theme}`">
    <!-- 第一列：主菜单（一行一项，可折叠为纯图标窄列） -->
    <aside class="menu-col menu-col--top" :class="{ 'is-narrow': collapsed }">
      <div class="admin-logo" title="回到首页" @click="goDashboard">
        <span v-if="collapsed" class="admin-logo-mark">鼎</span>
        <span v-else class="admin-logo-text">鼎极多端</span>
      </div>
      <div class="top-list">
        <template v-for="item in topMenus" :key="item.key">
          <el-tooltip
            v-if="collapsed"
            :content="item.name"
            placement="right"
            :show-after="200"
          >
            <div
              class="top-item"
              :class="{ 'is-active': selectedTopKey === item.key }"
              @click="selectTop(item)"
            >
              <AdminIcon :name="TOP_ICON[item.key] || 'extend'" class="top-icon" />
            </div>
          </el-tooltip>
          <div
            v-else
            class="top-item"
            :class="{ 'is-active': selectedTopKey === item.key }"
            @click="selectTop(item)"
          >
            <AdminIcon :name="TOP_ICON[item.key] || 'extend'" class="top-icon" />
            <span class="top-text">{{ item.name }}</span>
          </div>
        </template>
      </div>
      <!-- 底部控制台入口（仅超管代登录可见，点击回超管壳） -->
      <div v-if="isSuper" class="bottom-list">
        <el-tooltip
          v-if="collapsed"
          content="控制台"
          placement="right"
          :show-after="200"
        >
          <div class="top-item bottom-item" @click="goSuperConsole">
            <AdminIcon name="home" class="top-icon" />
          </div>
        </el-tooltip>
        <div v-else class="top-item bottom-item" @click="goSuperConsole">
          <AdminIcon name="home" class="top-icon" />
          <span class="top-text">控制台</span>
        </div>
      </div>
    </aside>

    <!-- 第二列：二级菜单面板（点击主菜单紧挨出现，可整体隐藏） -->
    <aside class="menu-col menu-col--sub" :class="{ 'is-hidden': !subVisible || !selectedTop }">
      <div class="sub-inner">
        <div class="sub-head">
          <span class="sub-title">{{ selectedTop?.name }}</span>
          <button class="sub-fold" title="收起二级菜单" @click="subVisible = false">
            <AdminIcon name="fold-on" />
          </button>
        </div>
        <div class="sub-list">
          <template v-for="row in subRows" :key="row.idPath">
            <!-- 分组行：点击在其正下方内嵌展开三级项 -->
            <div
              v-if="row.isGroup"
              class="sub-item sub-group"
              :class="{
                'is-open': openGroups.has(row.idPath),
                'on-path': isGroupOnPath(row),
              }"
              :style="{ paddingLeft: 16 + (row.level - 1) * 14 + 'px' }"
              @click="toggleGroup(row.idPath)"
            >
              <span class="sub-name">{{ row.node.name }}</span>
              <AdminIcon name="chevron-down" class="group-arrow" />
            </div>
            <!-- 叶子行：切换壳内组件 -->
            <div
              v-else
              class="sub-item"
              :class="{ 'is-active': activeId === row.idPath }"
              :style="{ paddingLeft: 16 + (row.level - 1) * 14 + 'px' }"
              @click="pickLeaf(row.idPath)"
            >
              <span class="sub-name">{{ row.node.name }}</span>
            </div>
          </template>
        </div>
      </div>
    </aside>

    <!-- 快捷菜单抽屉（收藏的菜单入口，批次2-C） -->
    <el-drawer
      v-model="quickMenuVisible"
      title="快捷菜单"
      direction="rtl"
      size="300px"
      :before-close="(done: () => void) => done()"
    >
      <div class="quick-menu-items">
        <template v-if="shortcutMenus.length">
          <div
            v-for="item in shortcutMenus"
            :key="item.key"
            class="menu-item"
            @click="gotoShortcut(item)"
          >
            <AdminIcon name="star-on" class="quick-star" />
            <span class="quick-name">{{ item.name }}</span>
            <span
              class="quick-remove"
              title="移除"
              @click.stop="removeShortcut(item)"
            >&times;</span>
          </div>
        </template>
        <el-empty
          v-else
          description="暂无收藏，可点页面标题旁星标收藏"
          :image-size="60"
        />
        <div class="quick-set-link" @click="gotoShortcutSet">快捷菜单设置</div>
      </div>
    </el-drawer>
    <div class="admin-body">
      <header class="admin-header">
        <div class="admin-header-left">
          <button
            class="collapse-btn"
            :title="collapsed ? '展开主菜单' : '收起主菜单'"
            @click="collapsed = !collapsed"
          >
            <AdminIcon :name="collapsed ? 'fold-off' : 'fold-on'" />
          </button>
          <!-- 全屏按钮 -->
          <button class="full-screen-btn" title="全屏" @click="toggleFullScreen">
            <AdminIcon name="expand" />
          </button>
          <!-- 刷新按钮 -->
          <button class="refresh-btn" title="刷新" @click="handleRefresh">
            <AdminIcon name="refresh" />
          </button>
          <!-- 前端链接按钮（任务263：打开查看链接大弹窗） -->
          <button class="action-btn" title="前端链接" @click="frontLinksVisible = true">
            <AdminIcon name="link" />
            <span class="btn-text">前端链接</span>
          </button>
          <!-- 手机端按钮（任务267：打开手机端管理小弹窗） -->
          <button class="action-btn" title="手机端" @click="mobileAdminVisible = true">
            <AdminIcon name="mobile" />
            <span class="btn-text">手机端</span>
          </button>
          <div class="admin-header-title">
            {{ activeNode?.name || (activeId === DASHBOARD_ID ? '首页' : '') }}
          </div>
          <!-- 星标收藏当前页面到快捷菜单（批次2-C） -->
          <button
            v-if="currentLeafKey"
            class="star-btn"
            :class="{ 'is-starred': currentStarred }"
            :title="currentStarred ? '取消收藏' : '收藏到快捷菜单'"
            @click="toggleStar"
          >
            <AdminIcon :name="currentStarred ? 'star-on' : 'star'" />
          </button>
        </div>
        <div class="admin-header-right">
          <!-- 快捷菜单入口 -->
          <button class="quick-menu-btn" title="快捷菜单" @click="openQuickMenu">
            <AdminIcon name="quick-menu" />
            <span class="btn-text">快捷菜单</span>
          </button>
          <!-- 控制台入口（仅超管可见） -->
          <template v-if="isSuper">
            <button class="console-btn" title="控制台" @click="goSuperConsole">
              <AdminIcon name="home" />
              <span class="btn-text">控制台</span>
            </button>
          </template>
          <!-- 通知中心（带红点） -->
          <button class="notification-btn" title="通知中心" @click="openNoticePage">
            <AdminIcon name="bell" />
            <span v-if="unreadNoticeCount > 0" class="red-dot">{{ unreadNoticeCount }}</span>
          </button>
          <!-- 帮助中心 -->
          <button class="help-btn" title="帮助中心" @click="openHelpPage">
            <AdminIcon name="help" />
          </button>
          <!-- 用户名独立下拉（修改密码 / 退出登录） -->
          <el-dropdown trigger="click" @command="handleUserCommand">
            <div class="user-profile" :title="company">
              <span class="company-name">{{ company }}</span>
              <AdminIcon name="chevron-down" class="user-arrow" />
            </div>
            <template #dropdown>
              <el-dropdown-menu>
                <el-dropdown-item command="editPassword">修改密码</el-dropdown-item>
                <el-dropdown-item divided command="logout" style="color: #f56c6c;">退出登录</el-dropdown-item>
              </el-dropdown-menu>
            </template>
          </el-dropdown>
          <!-- 三点更多按钮（最右侧）：仅配色方案选择 -->
          <el-dropdown trigger="click">
            <button class="more-btn" title="更多">
              <AdminIcon name="more-dots" />
            </button>
            <template #dropdown>
              <el-dropdown-menu>
                <el-dropdown-item disabled>配色方案</el-dropdown-item>
                <div class="theme-picker">
                  <button
                    v-for="t in THEMES"
                    :key="t.key"
                    class="theme-dot"
                    :class="{ 'is-active': theme === t.key }"
                    :style="{ backgroundColor: t.primary }"
                    :title="t.label"
                    @click="setTheme(t.key)"
                  />
                </div>
              </el-dropdown-menu>
            </template>
          </el-dropdown>
        </div>
      </header>

      <main class="admin-main">
        <KeepAlive :max="10">
          <component :is="activeComponent" :key="refreshKey + '-' + activeId" @close="closeOverlayPage" />
        </KeepAlive>
      </main>
    </div>

    <!-- 修改密码弹窗：当页提交，原地 ElMessage 提示，不刷新不跳转 -->
    <el-dialog
      v-model="pwdVisible"
      title="修改密码"
      width="440px"
      :close-on-click-modal="false"
    >
      <el-form
        ref="pwdFormRef"
        :model="pwdForm"
        :rules="pwdRules"
        label-width="90px"
        @keyup.enter="submitPwd"
      >
        <el-form-item label="原密码" prop="old_password">
          <el-input
            v-model="pwdForm.old_password"
            type="password"
            show-password
            autocomplete="current-password"
            placeholder="请输入原密码"
          />
        </el-form-item>
        <el-form-item label="新密码" prop="new_password">
          <el-input
            v-model="pwdForm.new_password"
            type="password"
            show-password
            autocomplete="new-password"
            placeholder="6-32 位新密码"
          />
        </el-form-item>
        <el-form-item label="确认密码" prop="confirm_password">
          <el-input
            v-model="pwdForm.confirm_password"
            type="password"
            show-password
            autocomplete="new-password"
            placeholder="请再次输入新密码"
          />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="pwdVisible = false">取消</el-button>
        <el-button type="primary" :loading="pwdSaving" @click="submitPwd">保存</el-button>
      </template>
    </el-dialog>

    <!-- 前端链接大弹窗（任务263：顶栏入口，查看模式） -->
    <FrontendLinks v-model:visible="frontLinksVisible" mode="view" />

    <!-- 手机端管理小弹窗（任务267：顶栏入口） -->
    <MobileAdminModal v-model:visible="mobileAdminVisible" />
  </div>
</template>

<style scoped>
.admin-layout {
  height: 100%;
  display: flex;
  overflow: hidden;
  /* 主题色 CSS 变量（三点菜单切换 theme-* 类名驱动） */
  --primary: var(--primary);
  --primary-rgb: 123, 125, 229;
}

.admin-layout.theme-green {
  --primary: #2fa97c;
  --primary-rgb: 47, 169, 124;
}

.admin-layout.theme-orange {
  --primary: #ef8f3b;
  --primary-rgb: 239, 143, 59;
}

.admin-layout.theme-purple {
  --primary: #9a6bd8;
  --primary-rgb: 154, 107, 216;
}

/* ===== 通用列 ===== */
.menu-col {
  background-color: #1e2128;
  border-right: 1px solid rgba(255, 255, 255, 0.06);
  flex-shrink: 0;
  overflow: hidden;
  transition: width 0.2s;
}

/* ===== 第一列：主菜单 ===== */
/* 80px = 图标 18 + 间距 6 + 最长 3 字（底部控制台入口）×14px + 居中两侧余量 */
.menu-col--top {
  width: 80px;
  display: flex;
  flex-direction: column;
}

.menu-col--top.is-narrow {
  width: 56px;
}

.admin-logo {
  height: 50px;
  display: flex;
  align-items: center;
  justify-content: center;
  border-bottom: 1px solid rgba(255, 255, 255, 0.06);
  flex-shrink: 0;
  cursor: pointer;
  overflow: hidden;
  white-space: nowrap;
}

.admin-logo-text {
  color: #e4e6eb;
  font-size: 14px;
  font-weight: 600;
  letter-spacing: 1px;
  white-space: nowrap;
}

.admin-logo-text.is-hidden {
  display: none;
}

.admin-logo-mark {
  width: 34px;
  height: 34px;
  border-radius: 7px;
  display: flex;
  align-items: center;
  justify-content: center;
  background: rgb(var(--primary-rgb));
  color: #fff;
  font-size: 18px;
  font-weight: 700;
}

.top-list {
  flex: 1;
  overflow-y: auto;
  overflow-x: hidden;
  padding: 6px 0;
}

/* 一行一个主菜单：图标 + 文字垂直居中、不換行不重叠 */
.top-item {
  height: 50px;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  padding: 0;
  color: #8b909a;
  cursor: pointer;
  white-space: nowrap;
  position: relative;
  box-sizing: border-box;
}

.menu-col--top.is-narrow .top-item {
  justify-content: center;
  padding: 0;
}

.top-icon {
  font-size: 18px;
  flex-shrink: 0;
}

.top-text {
  font-size: 13px;
  letter-spacing: 1px;
  line-height: 1;
}

.top-item:hover {
  color: var(--primary);
  background-color: rgba(var(--primary-rgb), 0.12);
}

.top-item.is-active {
  color: var(--primary);
  background-color: rgba(var(--primary-rgb), 0.18);
}

.top-item.is-active::before {
  content: '';
  position: absolute;
  left: 0;
  top: 10px;
  bottom: 10px;
  width: 3px;
  border-radius: 0 2px 2px 0;
  background-color: var(--primary);
}

/* 底部固定区：控制台入口（仅超管渲染） */
.bottom-list {
  flex-shrink: 0;
  border-top: 1px solid rgba(255, 255, 255, 0.06);
  padding: 4px 0;
}

.bottom-item {
  height: 44px;
}

/* ===== 第二列：二级菜单面板 ===== */
.menu-col--sub {
  width: 120px;
}

.menu-col--sub.is-hidden {
  width: 0;
  border-right: none;
}

.sub-inner {
  width: 120px;
  height: 100%;
  display: flex;
  flex-direction: column;
}

.sub-head {
  height: 50px;
  flex-shrink: 0;
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0 6px 0 12px;
  border-bottom: 1px solid rgba(255, 255, 255, 0.06);
  box-sizing: border-box;
}

.sub-title {
  font-size: 14px;
  font-weight: 600;
  color: #e4e6eb;
  white-space: nowrap;
  overflow: hidden;
}

.sub-fold {
  width: 26px;
  height: 26px;
  display: flex;
  align-items: center;
  justify-content: center;
  border: none;
  background: transparent;
  border-radius: 6px;
  color: #6b7078;
  font-size: 15px;
  cursor: pointer;
  flex-shrink: 0;
}

.sub-fold:hover {
  color: var(--primary);
  background-color: rgba(var(--primary-rgb), 0.08);
}

.sub-list {
  flex: 1;
  overflow-y: auto;
  overflow-x: hidden;
  padding: 6px 0;
}

.sub-item {
  height: 42px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding-right: 10px;
  color: #a0a4b0;
  font-size: 13px;
  cursor: pointer;
  white-space: nowrap;
  box-sizing: border-box;
}

.sub-name {
  overflow: hidden;
  text-overflow: ellipsis;
}

.sub-item:hover {
  color: var(--primary);
  background-color: rgba(var(--primary-rgb), 0.12);
}

.sub-item.is-active {
  color: var(--primary);
  font-weight: 600;
  background-color: rgba(var(--primary-rgb), 0.18);
}

/* 分组头（含三级）：弱底色区分，箭头指示开合 */
.sub-group {
  font-weight: 600;
  color: #c8cdd4;
  background-color: rgba(255, 255, 255, 0.04);
}

.sub-group.is-open,
.sub-group.on-path {
  color: var(--primary);
}

.group-arrow {
  font-size: 14px;
  flex-shrink: 0;
  transition: transform 0.2s;
}

.sub-group.is-open .group-arrow {
  transform: rotate(180deg);
}

/* ===== 右侧主体 ===== */
.admin-body {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
}

.admin-header {
  height: 50px;
  flex-shrink: 0;
  background-color: #1e2128;
  border-bottom: 1px solid rgba(255, 255, 255, 0.06);
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0 16px;
  box-sizing: border-box;
}

.admin-header-left {
  display: flex;
  align-items: center;
  gap: 12px;
}

.collapse-btn {
  width: 32px;
  height: 32px;
  display: flex;
  align-items: center;
  justify-content: center;
  border: none;
  background: transparent;
  border-radius: 6px;
  color: #a0a4b0;
  font-size: 18px;
  cursor: pointer;
}

.collapse-btn:hover {
  color: var(--primary);
  background-color: rgba(var(--primary-rgb), 0.08);
}

/* ===== 顶栏工具按钮通用样式 ===== */
.full-screen-btn,
.refresh-btn {
  width: 32px;
  height: 32px;
  display: flex;
  align-items: center;
  justify-content: center;
  border: none;
  background: transparent;
  border-radius: 6px;
  color: #a0a4b0;
  /* 与超管壳顶栏图标同尺寸（16px），两端视觉一致 */
  font-size: 16px;
  cursor: pointer;
  flex-shrink: 0;
}

.full-screen-btn:hover,
.refresh-btn:hover {
  color: var(--primary);
  background-color: rgba(var(--primary-rgb), 0.08);
}

/* 功能按钮（前端链接、手机端） */
.action-btn,
.quick-menu-btn,
.console-btn,
.notification-btn,
.help-btn {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 6px 12px;
  border: none;
  background: transparent;
  border-radius: 6px;
  color: #a0a4b0;
  font-size: 13px;
  cursor: pointer;
  white-space: nowrap;
  transition: all 0.2s;
  position: relative;
}

.action-btn:hover,
.quick-menu-btn:hover,
.console-btn:hover,
.notification-btn:hover,
.help-btn:hover {
  color: var(--primary);
  background-color: rgba(var(--primary-rgb), 0.08);
}

.btn-text {
  font-size: 13px;
  letter-spacing: 1px;
}

/* 通知中心红点占位 */
.red-dot {
  position: absolute;
  top: -4px;
  right: -4px;
  min-width: 16px;
  height: 16px;
  border-radius: 8px;
  background-color: #f56c6c;
  color: #fff;
  font-size: 10px;
  line-height: 16px;
  text-align: center;
  padding: 0 4px;
  box-shadow: 0 2px 4px rgba(245, 108, 108, 0.4);
}

/* 用户名（独立下拉触发器，与右侧三点同一行不换行） */
.user-profile {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 6px 8px;
  white-space: nowrap;
  max-width: 160px;
  overflow: hidden;
  border-radius: 6px;
  cursor: pointer;
  transition: all 0.2s;
}

.user-profile:hover {
  background-color: rgba(var(--primary-rgb), 0.08);
}

.user-profile:hover .company-name {
  color: var(--primary);
}

.user-arrow {
  font-size: 14px;
  color: #6b7078;
  flex-shrink: 0;
}

.company-name {
  color: #e4e6eb;
  font-weight: 600;
  font-size: 14px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

/* 三点更多按钮（顶栏最右侧） */
.more-btn {
  width: 32px;
  height: 32px;
  display: flex;
  align-items: center;
  justify-content: center;
  border: none;
  background: transparent;
  border-radius: 6px;
  color: #a0a4b0;
  font-size: 18px;
  cursor: pointer;
  flex-shrink: 0;
  transition: all 0.2s;
}

.more-btn:hover {
  color: var(--primary);
  background-color: rgba(var(--primary-rgb), 0.08);
}

/* 三点下拉内配色方案色块选择器 */
.theme-picker {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 8px 20px 10px;
}

.theme-dot {
  width: 18px;
  height: 18px;
  border-radius: 50%;
  border: 2px solid transparent;
  cursor: pointer;
  padding: 0;
  transition: transform 0.2s;
}

.theme-dot:hover {
  transform: scale(1.15);
}

.theme-dot.is-active {
  outline: 2px solid #ffffff;
  outline-offset: -2px;
  box-shadow: 0 0 0 1px rgba(0, 0, 0, 0.15);
}

.admin-header-title {
  font-size: 15px;
  font-weight: 600;
  color: #e4e6eb;
}

.admin-header-right {
  display: flex;
  align-items: center;
  gap: 14px;
  flex-flow: row nowrap;
  white-space: nowrap;
}

.admin-company {
  color: #e4e6eb;
  font-weight: 600;
}

.admin-expire {
  color: #6b7078;
  font-size: 13px;
}

.admin-expire--danger {
  color: #f56c6c;
  font-weight: 700;
}

.admin-main {
  flex: 1;
  min-height: 0;
  background-color: #f5f7fa;
  /* 主区域四边边距：白卡片离四周约 3px（缩小到当前值的 1/4） */
  padding: 3px;
  overflow: auto;
}

/* ===== 快捷菜单抽屉样式 ===== */
.quick-menu-items {
  display: flex;
  flex-direction: column;
  gap: 12px;
  padding: 8px;
}

.menu-item {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 12px;
  border-radius: 8px;
  cursor: pointer;
  transition: all 0.2s;
  color: #a0a4b0;
  font-size: 14px;
  white-space: nowrap;
}

.menu-item:hover {
  background-color: rgba(var(--primary-rgb), 0.08);
  color: var(--primary);
}

.quick-star {
  color: #f7ba2a;
  flex-shrink: 0;
}

.quick-name {
  flex: 1;
  overflow: hidden;
  text-overflow: ellipsis;
}

.quick-remove {
  display: none;
  color: #6b7078;
  font-size: 16px;
  padding: 0 4px;
  flex-shrink: 0;
}

.menu-item:hover .quick-remove {
  display: inline;
}

.quick-remove:hover {
  color: #f56c6c;
}

.quick-set-link {
  margin-top: 8px;
  padding: 8px 12px;
  color: var(--primary);
  font-size: 13px;
  cursor: pointer;
  text-align: center;
  border-top: 1px dashed #ebeef5;
}

/* 标题旁星标收藏按钮 */
.star-btn {
  width: 30px;
  height: 30px;
  display: flex;
  align-items: center;
  justify-content: center;
  border: none;
  background: transparent;
  border-radius: 6px;
  color: #c0c4cc;
  font-size: 16px;
  cursor: pointer;
  flex-shrink: 0;
}

.star-btn:hover {
  color: #f7ba2a;
  background-color: rgba(247, 186, 42, 0.1);
}

.star-btn.is-starred {
  color: #f7ba2a;
}
</style>
