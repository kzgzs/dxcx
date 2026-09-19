<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import type { FormInstance, FormRules } from 'element-plus'
import { useAuthStore } from '../stores/auth'
import { consoleMenus } from '../menu/consoleMenus'
import type { MenuNode } from '../menu/menuTypes'
import { indexLeaves } from '../menu/menuTypes'
import MenuTreeNode from '../components/MenuTreeNode.vue'
import ConsoleIcon from '../components/ConsoleIcon.vue'
import ConsoleUnderConstruction from './ConsoleUnderConstruction.vue'
import { editConsolePassword } from '../api/auth'
import { proxyLogin } from '../api/tenant'
import { getSystemVersion } from '../api/system'
import type { SystemVersion } from '../api/system'

const router = useRouter()
const authStore = useAuthStore()

/**
 * 单入口壳层：菜单配置中心驱动
 * - 点击菜单只切换壳内动态组件，绝不改动 URL（地址栏固定为 /#/Console/index）
 * - 默认首屏为用户列表 Tenant（直接激活 menu key，刷新/进入自然回首屏）
 * - 左侧不再显示「控制台」标题，点击菜单项直接切换内部组件；菜单列表从用户列表开始
 * - 右侧顶栏不再显示「控制台」文字（保留工具栏按钮）
 * - 激活状态只存内存
 */
const leafMap = indexLeaves(consoleMenus)
const activeId = ref('web-user-index')

const activeNode = computed<MenuNode | undefined>(() => leafMap.get(activeId.value))
const activeComponent = computed(() => {
  return activeNode.value?.component || ConsoleUnderConstruction
})
const adminName = computed(() => authStore.userinfo?.username || 'admin')

// el-menu select：index 即 idPath，只切内部组件，不碰 URL
function handleSelect(index: string) {
  if (leafMap.has(index)) activeId.value = index
}



// 标题同步（菜单切换不走路由，这里自行维护）
function syncTitle() {
  const name = activeNode.value?.name || ''
  document.title = name ? `${name} - 鼎极多端·超管控制台` : '鼎极多端·超管控制台'
}
watch(activeId, syncTitle, { immediate: true })

// ===== 顶栏：左侧菜单收缩/展开 =====
const collapsed = ref(false)
function toggleCollapse() {
  collapsed.value = !collapsed.value
}
// 窄列首字磁贴点击：与展开态 el-menu 同一套 id，行为一致
function pickCollapsed(key: string) {
  if (leafMap.has(key)) activeId.value = key
}

// ===== 顶栏：全屏切换 =====
const isFullscreen = ref(false)
function syncFullscreen() {
  isFullscreen.value = !!document.fullscreenElement
}
async function toggleFullscreen() {
  try {
    if (document.fullscreenElement) {
      await document.exitFullscreen()
    } else {
      await document.documentElement.requestFullscreen()
    }
  } catch {
    ElMessage.warning('当前浏览器不支持全屏')
  }
}

// ===== 顶栏：刷新主区域（不整页刷新，仅重挂载当前激活页面组件） =====
const refreshTick = ref(0)
const spinning = ref(false)
// 组件 key = 菜单id:刷新序号，序号自增即强制重挂载，页面 setup/fetchList 重新执行
const componentKey = computed(() => `${activeId.value}:${refreshTick.value}`)
function refreshMain() {
  refreshTick.value += 1
  spinning.value = true
  window.setTimeout(() => {
    spinning.value = false
  }, 600)
}

// ===== 顶栏：配色方案（默认/亮色/暗色，作用于 CSS 变量） =====
type Theme = 'default' | 'light' | 'dark'
const THEME_KEY = 'console_theme'
const themeOptions: { value: Theme; label: string }[] = [
  { value: 'default', label: '默认' },
  { value: 'light', label: '亮色' },
  { value: 'dark', label: '暗色' },
]
const theme = ref<Theme>('default')
function applyTheme(t: Theme) {
  theme.value = t
  // 暗色同时挂 html.dark，让 Element Plus 暗色变量生效（弹层/下拉随 body，必须挂根节点）
  document.documentElement.classList.toggle('dark', t === 'dark')
  localStorage.setItem(THEME_KEY, t)
}
function selectTheme(command: Theme) {
  applyTheme(command)
  ElMessage.success(`已切换为${themeOptions.find((t) => t.value === command)?.label}配色`)
}

// ===== 顶栏：返回客户端（走代登录 super 通道切换身份进入租户壳 /#/Merchant/index） =====
// 与 Tenant.vue 代登录同款逻辑：拿 super token → 清除超管会话 → 跳 /#/login?token= 直登通道（按角色落到租户新路径）
async function goClient() {
  try {
    const data = await proxyLogin(0)
    // 寄存控制台会话（供租户壳底部「控制台」入口返回），再清除当前会话，避免双身份并存
    authStore.stashConsoleSession()
    authStore.logout(true)
    location.href =
      `${location.origin}${location.pathname}${location.search}` +
      `#/login?token=${encodeURIComponent(data.token)}`
  } catch {
    // 请求失败不清会话；错误提示已由 axios 拦截器统一弹出
  }
}

// ===== 顶栏：修改密码弹窗（当页提交，原地提示，不刷新不跳转） =====
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
      await editConsolePassword({
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

// ===== 顶栏：退出登录 =====
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

// ===== 顶栏：版本信息（读 GET /console/system/version，点击弹窗展示） =====
const versionInfo = ref<SystemVersion | null>(null)
const versionVisible = ref(false)
const versionText = computed(() =>
  versionInfo.value ? `v${versionInfo.value.version}` : 'v1.0.0',
)
function openVersionDialog() {
  versionVisible.value = true
  if (!versionInfo.value) fetchVersion()
}
async function fetchVersion() {
  try {
    versionInfo.value = await getSystemVersion()
  } catch {
    // 拉取失败保持占位版本号，不弹错误
  }
}

onMounted(() => {
  const saved = localStorage.getItem(THEME_KEY)
  if (saved === 'light' || saved === 'dark' || saved === 'default') {
    applyTheme(saved)
  }
  fetchVersion()
  document.addEventListener('fullscreenchange', syncFullscreen)
})

onBeforeUnmount(() => {
  document.removeEventListener('fullscreenchange', syncFullscreen)
})
</script>

<template>
  <el-container class="layout console-layout" :data-theme="theme">
    <!-- 展开态 116px = 左 padding 18 + 最长项 6 字×13px + 右侧余量，收缩窄列 64px 不变 -->
    <el-aside :width="collapsed ? '64px' : '116px'" class="layout-aside">
      <!-- 左侧顶部：控制台总汇标题（仅展开态显示） -->
      <div v-if="!collapsed" class="console-title">控制台</div>

      <!-- 展开态：平铺 11 项主菜单（点击只切内部组件，URL 不变） -->
      <el-menu
        v-if="!collapsed"
        :default-active="activeId"
        background-color="#001529"
        text-color="#b8c4d0"
        active-text-color="#ffffff"
        class="layout-menu"
        @select="handleSelect"
      >
        <MenuTreeNode
          v-for="node in consoleMenus"
          :key="node.key"
          :node="node"
          id-path=""
        />
      </el-menu>

      <!-- 收合态：窄列首字磁贴（同一套菜单 id，行为与展开态一致） -->
      <div v-else class="collapsed-menu">
        <el-tooltip
          v-for="node in consoleMenus"
          :key="node.key"
          :content="node.name"
          placement="right"
          :show-after="200"
        >
          <div
            class="collapsed-item"
            :class="{ 'is-active': activeId === node.key }"
            @click="pickCollapsed(node.key)"
          >
            {{ node.icon || node.name.charAt(0) }}
          </div>
        </el-tooltip>
      </div>
    </el-aside>

    <el-container>
      <!-- 固定顶栏：深色背景条 + 浅色文字，不随主区滚动 -->
      <el-header class="layout-header" height="50px">
        <div class="header-left">
          <button
            class="topbar-btn"
            :title="collapsed ? '展开菜单' : '收起菜单'"
            @click="toggleCollapse"
          >
            <ConsoleIcon :name="collapsed ? 'fold-off' : 'fold-on'" />
            <span class="topbar-text">{{ collapsed ? '展开' : '收起' }}</span>
          </button>
          <button
            class="topbar-btn"
            :title="isFullscreen ? '退出扩大' : '扩大'"
            @click="toggleFullscreen"
          >
            <ConsoleIcon :name="isFullscreen ? 'fullscreen-exit' : 'expand'" />
            <span class="topbar-text">{{ isFullscreen ? '退出扩大' : '扩大' }}</span>
          </button>
          <button class="topbar-btn" title="刷新当前页面" @click="refreshMain">
            <ConsoleIcon name="refresh" :class="{ 'is-spinning': spinning }" />
            <span class="topbar-text">刷新</span>
          </button>
        </div>

        <div class="header-right">
          <button class="topbar-btn" title="返回客户端" @click="goClient">
            <ConsoleIcon name="home" />
            <span class="topbar-text">返回客户端</span>
          </button>

          <el-dropdown trigger="click" @command="selectTheme">
            <button class="topbar-btn" title="配色方案">
              <ConsoleIcon name="palette" />
              <span class="topbar-text">配色</span>
              <ConsoleIcon name="chevron-down" class="topbar-arrow" />
            </button>
            <template #dropdown>
              <el-dropdown-menu>
                <el-dropdown-item
                  v-for="t in themeOptions"
                  :key="t.value"
                  :command="t.value"
                >
                  <span class="theme-item">
                    {{ t.label }}
                    <span v-if="theme === t.value" class="theme-check">✓</span>
                  </span>
                </el-dropdown-item>
              </el-dropdown-menu>
            </template>
          </el-dropdown>

          <el-dropdown trigger="click">
            <button class="topbar-btn" title="账号操作">
              <ConsoleIcon name="user" />
              <span class="topbar-text">{{ adminName }}</span>
              <ConsoleIcon name="chevron-down" class="topbar-arrow" />
            </button>
            <template #dropdown>
              <el-dropdown-menu>
                <el-dropdown-item @click="openPwdDialog">
                  <ConsoleIcon name="lock" class="dropdown-icon" />
                  修改密码
                </el-dropdown-item>
                <el-dropdown-item divided @click="handleLogout">
                  <ConsoleIcon name="logout" class="dropdown-icon" />
                  退出登录
                </el-dropdown-item>
              </el-dropdown-menu>
            </template>
          </el-dropdown>

          <button class="topbar-btn topbar-version" title="查看版本说明" @click="openVersionDialog">
            <ConsoleIcon name="info" />
            <span class="topbar-text">{{ versionText }}</span>
          </button>
        </div>
      </el-header>

      <el-main class="layout-main">
        <KeepAlive :max="10">
          <component :is="activeComponent" :key="componentKey" />
        </KeepAlive>
      </el-main>
    </el-container>

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

    <!-- 版本说明弹窗 -->
    <el-dialog v-model="versionVisible" title="版本信息" width="420px">
      <div class="version-body">
        <div class="version-line">
          <span class="version-label">系统名称</span>
          <span>鼎极多端</span>
        </div>
        <div class="version-line">
          <span class="version-label">当前版本</span>
          <span>{{ versionText }}</span>
        </div>
        <div v-if="versionInfo" class="version-line">
          <span class="version-label">运行环境</span>
          <span>PHP {{ versionInfo.php }} · ThinkPHP {{ versionInfo.think }}</span>
        </div>
        <div class="version-note">
          鼎极多端 v1.0.0 · 多端应用工厂，采用单入口壳层架构：
          菜单切换不改变地址栏，全站仅 /#/Console/index 与 /#/Merchant/index 两个固定入口。
        </div>
      </div>
      <template #footer>
        <el-button type="primary" @click="versionVisible = false">知道了</el-button>
      </template>
    </el-dialog>
  </el-container>
</template>

<style scoped>
/* ===== 配色主题变量（顶栏/主区表面色，默认：深色顶栏 + 浅灰主区） ===== */
.console-layout {
  --c-header-bg: #001529;
  --c-header-text: rgba(255, 255, 255, 0.85);
  --c-header-hover: rgba(255, 255, 255, 0.12);
  --c-header-divider: rgba(255, 255, 255, 0.18);
  --c-main-bg: #f5f7fa;
}

.console-layout[data-theme='light'] {
  --c-header-bg: #ffffff;
  --c-header-text: #303133;
  --c-header-hover: #f2f3f5;
  --c-header-divider: #e6e8eb;
  --c-main-bg: #f5f7fa;
}

.console-layout[data-theme='dark'] {
  --c-header-bg: #1d1e1f;
  --c-header-text: rgba(255, 255, 255, 0.85);
  --c-header-hover: rgba(255, 255, 255, 0.12);
  --c-header-divider: rgba(255, 255, 255, 0.15);
  --c-main-bg: #141414;
}

.layout {
  height: 100%;
}

.layout-aside {
  background-color: #001529;
  display: flex;
  flex-direction: column;
  transition: width 0.2s;
  overflow: hidden;
}

/* 控制台总汇标题 */
.console-title {
  height: 40px;
  line-height: 40px;
  padding-left: 18px;
  color: #ffffff;
  font-size: 16px;
  font-weight: 600;
  border-bottom: 1px solid rgba(255, 255, 255, 0.1);
  flex-shrink: 0;
}

.logo {
  height: 50px;
  line-height: 50px;
  text-align: center;
  color: #ffffff;
  font-size: 18px;
  font-weight: 600;
  letter-spacing: 2px;
  flex-shrink: 0;
  white-space: nowrap;
  overflow: hidden;
  cursor: pointer;
}

.logo:hover {
  background-color: rgba(255, 255, 255, 0.08);
}

.layout-menu {
  border-right: none;
  flex: 1;
  overflow-y: auto;
  /* 紧凑行距（对齐参考系统风格）：压缩菜单项高度与内边距 */
  --el-menu-item-height: 40px;
  --el-menu-sub-menu-item-height: 38px;
}

.layout-menu :deep(.el-menu-item),
.layout-menu :deep(.el-sub-menu__title) {
  height: 40px;
  line-height: 40px;
  font-size: 13px;
  padding-left: 18px !important;
}

/* 收合态窄列：首字磁贴 */
.collapsed-menu {
  flex: 1;
  overflow-y: auto;
  padding: 6px 0;
}

.collapsed-item {
  width: 40px;
  height: 40px;
  margin: 2px auto;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #b8c4d0;
  font-size: 15px;
  border-radius: 6px;
  cursor: pointer;
  user-select: none;
}

.collapsed-item:hover {
  color: #ffffff;
  background-color: rgba(255, 255, 255, 0.08);
}

.collapsed-item.is-active {
  color: #ffffff;
  background-color: #409eff;
}

/* ===== 固定顶栏：深色背景条 + 浅色文字 ===== */
.layout-header {
  height: 50px;
  flex-shrink: 0;
  background-color: var(--c-header-bg);
  color: var(--c-header-text);
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0 12px;
}

.header-left,
.header-right {
  display: flex;
  align-items: center;
  gap: 2px;
  min-width: 0;
}

.topbar-btn {
  height: 34px;
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 0 10px;
  border: none;
  background: transparent;
  border-radius: 6px;
  color: var(--c-header-text);
  font-size: 13px;
  cursor: pointer;
  white-space: nowrap;
}

.topbar-btn:hover {
  background-color: var(--c-header-hover);
  color: #ffffff;
}

.console-layout[data-theme='light'] .topbar-btn:hover {
  color: #303133;
}

.topbar-btn :deep(.console-icon) {
  font-size: 16px;
}

.topbar-arrow {
  font-size: 12px !important;
  opacity: 0.7;
}

.topbar-text {
  line-height: 1;
}



/* 刷新图标旋转反馈 */
.is-spinning {
  animation: console-spin 0.6s ease;
}

@keyframes console-spin {
  from {
    transform: rotate(0deg);
  }
  to {
    transform: rotate(360deg);
  }
}

/* 配色下拉：当前项打勾 */
.theme-item {
  display: inline-flex;
  align-items: center;
  justify-content: space-between;
  gap: 18px;
  min-width: 72px;
}

.theme-check {
  color: var(--el-color-primary);
  font-weight: 700;
}

.dropdown-icon {
  margin-right: 6px;
  font-size: 15px;
}

.layout-main {
  background-color: var(--c-main-bg);
  /* 主区域四边边距：白卡片离四周约 3px（缩小到当前值的 1/4） */
  padding: 3px;
}

/* 版本弹窗 */
.version-body {
  font-size: 13px;
  color: var(--el-text-color-regular);
}

.version-line {
  display: flex;
  gap: 12px;
  padding: 5px 0;
}

.version-label {
  width: 64px;
  flex-shrink: 0;
  color: var(--el-text-color-secondary);
}

.version-note {
  margin-top: 10px;
  padding: 10px;
  border-radius: 6px;
  background-color: var(--el-fill-color-light);
  line-height: 1.7;
  color: var(--el-text-color-secondary);
}
</style>
