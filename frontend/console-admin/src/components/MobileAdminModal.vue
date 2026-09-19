<script setup lang="ts">
/**
 * 手机端管理入口弹窗（任务272，对照参考系统形态：375×600 手机框嵌入真实手机管理 H5）
 * - 形态：遮罩 + 居中手机框（圆角边框模拟手机），可拖拽（复用任务267拖拽逻辑）、
 *   点遮罩关闭、右上 X
 * - 打开时：取当前 B端 admin JWT（localStorage 键 token，见 api/request.ts TOKEN_KEY）
 *   → POST /api/adminidx/autologin {jwt} 换发手机端 token
 *   → iframe src = buildH5Url('admin/index/index') + '?mtoken=' + token（免登直达）
 * - iframe 内未登录/token 失效时由 H5 登录页兜底
 * - H5 基址口径不变（任务266）：DEV→本地开发服务器；PROD→同域 /h5/ 哈希路由
 */
import { onBeforeUnmount, reactive, ref, watch } from 'vue'
import request, { TOKEN_KEY } from '../api/request'

const props = defineProps<{ visible: boolean }>()
const emit = defineEmits<{ (e: 'update:visible', v: boolean): void }>()

/** 手机管理 H5 入口页路径 */
const ADMIN_PATH = 'admin/index/index'

/**
 * H5 预览链接单一拼装口径（与 LinkAddressModal 同口径，任务266）：
 * - DEV：本地开发服务器 http://localhost:5174/#/ + 路径
 * - PROD：同域 /h5/ 哈希路由 location.origin + /h5/#/ + 路径
 */
function buildH5Url(p: string): string {
  const rel = p.replace(/^\//, '')
  return import.meta.env.DEV
    ? 'http://localhost:5174/#/' + rel
    : location.origin + '/h5/#/' + rel
}

// ============ 打开流程：autologin 换发手机端 token 后装载 iframe ============
const iframeUrl = ref('')
const loading = ref(false)
const loadError = ref('')

async function openPhone() {
  iframeUrl.value = ''
  loadError.value = ''
  loading.value = true
  centerPanel()

  const jwt = localStorage.getItem(TOKEN_KEY) || ''
  const base = buildH5Url(ADMIN_PATH)

  // 无 B 端会话：直接装载 H5，由其登录页兜底
  if (!jwt) {
    iframeUrl.value = base
    loading.value = false
    return
  }

  try {
    // POST /api/adminidx/autologin {jwt} → {token, admin}（响应拦截器已解信封）
    const res = await request.post<unknown, { token?: string }>('/api/adminidx/autologin', {
      jwt,
    })
    const token = res?.token || ''
    iframeUrl.value = token ? `${base}?mtoken=${encodeURIComponent(token)}` : base
  } catch {
    // 换发失败（silentError 已静默）：仍装载 H5，由登录页兜底
    iframeUrl.value = base
  } finally {
    loading.value = false
  }
}

watch(
  () => props.visible,
  (v) => {
    if (v) {
      void openPhone()
    } else {
      // 关闭即卸载 iframe，下次打开重新换发 token
      iframeUrl.value = ''
    }
  },
)

// ============ 拖拽（按住标题栏移动，限制在视口内） ============
const PANEL_W = 395
const PANEL_H = 676
const pos = reactive({ x: 0, y: 0 })
let dragging = false
let dragOffsetX = 0
let dragOffsetY = 0

function centerPanel() {
  pos.x = Math.max(0, Math.round((window.innerWidth - PANEL_W) / 2))
  pos.y = Math.max(0, Math.round((window.innerHeight - PANEL_H) / 2))
}

function onDragStart(e: MouseEvent) {
  if ((e.target as HTMLElement).closest('.mam-close')) return
  dragging = true
  dragOffsetX = e.clientX - pos.x
  dragOffsetY = e.clientY - pos.y
  document.addEventListener('mousemove', onDragMove)
  document.addEventListener('mouseup', onDragEnd)
  e.preventDefault()
}
function onDragMove(e: MouseEvent) {
  if (!dragging) return
  pos.x = Math.min(Math.max(0, e.clientX - dragOffsetX), Math.max(0, window.innerWidth - 120))
  pos.y = Math.min(Math.max(0, e.clientY - dragOffsetY), Math.max(0, window.innerHeight - 44))
}
function onDragEnd() {
  dragging = false
  document.removeEventListener('mousemove', onDragMove)
  document.removeEventListener('mouseup', onDragEnd)
}
onBeforeUnmount(onDragEnd)

function close() {
  emit('update:visible', false)
}
</script>

<template>
  <Teleport to="body">
    <div v-if="visible" class="mam-mask" @click.self="close">
      <div
        class="mam-panel"
        :style="{ left: pos.x + 'px', top: pos.y + 'px', width: PANEL_W + 'px' }"
      >
        <div class="mam-header" @mousedown="onDragStart">
          <span class="mam-title">手机端管理</span>
          <span class="mam-close" title="关闭" @click="close">&times;</span>
        </div>

        <div class="mam-body">
          <!-- 手机框：375×600 屏幕，圆角边框模拟手机 -->
          <div class="mam-phone">
            <div class="mam-screen">
              <div v-if="loading" class="mam-loading">正在打开手机端管理…</div>
              <iframe
                v-if="iframeUrl"
                :src="iframeUrl"
                class="mam-iframe"
                title="手机端管理"
                frameborder="0"
              ></iframe>
              <div v-if="loadError" class="mam-error">{{ loadError }}</div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.mam-mask {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.35);
  z-index: 3100;
}

.mam-panel {
  position: absolute;
  background: #fff;
  border-radius: 10px;
  box-shadow: 0 8px 30px rgba(0, 0, 0, 0.25);
  overflow: hidden;
}

.mam-header {
  height: 42px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0 16px;
  background: #f7f8fa;
  border-bottom: 1px solid #ebeef5;
  cursor: move;
  user-select: none;
}

.mam-title {
  font-size: 14px;
  font-weight: 600;
  color: #303133;
}

.mam-close {
  font-size: 20px;
  line-height: 1;
  color: #909399;
  cursor: pointer;
  padding: 2px 6px;
}

.mam-close:hover {
  color: #f56c6c;
}

.mam-body {
  padding: 12px;
  background: #e9ebf0;
}

/* 手机框：深色圆角边框模拟手机外壳 */
.mam-phone {
  width: 375px;
  height: 600px;
  border: 8px solid #2c2e33;
  border-radius: 32px;
  background: #2c2e33;
  box-shadow: 0 6px 20px rgba(0, 0, 0, 0.25);
  overflow: hidden;
}

/* 手机屏幕 */
.mam-screen {
  position: relative;
  width: 100%;
  height: 100%;
  background: #ffffff;
  border-radius: 22px;
  overflow: hidden;
}

.mam-iframe {
  display: block;
  width: 100%;
  height: 100%;
  border: 0;
  background: #ffffff;
}

.mam-loading {
  position: absolute;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 13px;
  color: #909399;
}

.mam-error {
  position: absolute;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 13px;
  color: #f56c6c;
}
</style>
