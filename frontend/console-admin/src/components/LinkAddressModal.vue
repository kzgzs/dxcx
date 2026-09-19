<script setup lang="ts">
/**
 * 链接地址小弹窗（对标参考系统 chooseurl 的 geturl 模式，任务263）
 * - 可拖拽（按住标题栏移动，限制在视口内）
 * - 二维码前端生成（qrcode 库，我方无微信凭证）：默认编码 H5 链接；
 *   「查看微信小程序码」按钮切换为编码页面路径（本地环境以路径码替代）
 * - 链接地址（H5 全链）+ 复制 + 预览（新窗口打开）；页面路径 + 复制；
 *   小程序链接地址（去首斜杠含参数）+ 复制（navigator.clipboard + execCommand 降级）
 */
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue'
import { ElMessage } from 'element-plus'
import QRCode from 'qrcode'

const props = defineProps<{
  visible: boolean
  name: string
  path: string
  /** 任务318：H5 预览专用路径（与小程序路径不同时传入，如设计页 pages/designer/custom?id=x） */
  h5Path?: string
}>()

const emit = defineEmits<{ (e: 'update:visible', v: boolean): void }>()

/**
 * H5 预览链接单一拼装口径（任务266）：
 * - DEV：本地开发服务器 http://localhost:5174/#/ + 去首斜杠路径
 * - PROD：同域 /h5/ 哈希路由 location.origin + /h5/#/ + 去首斜杠路径
 */
function buildH5Url(p: string): string {
  const rel = p.replace(/^\//, '')
  return import.meta.env.DEV
    ? 'http://localhost:5174/#/' + rel
    : location.origin + '/h5/#/' + rel
}

/** 任务318：H5 链接/二维码优先用 h5Path，未传退回小程序路径 */
const h5Url = computed(() => buildH5Url(props.h5Path || props.path))

/** 小程序链接地址：小程序页面路径口径（去首斜杠含参数，如 pages/my/my?id=2） */
const mpPath = computed(() => props.path.replace(/^\//, ''))

/** 预览：新窗口打开 H5 链接 */
function previewH5() {
  window.open(h5Url.value, '_blank')
}

/** 二维码内容：默认 H5 链接；点「查看微信小程序码」后切换为页面路径 */
const qrMode = ref<'h5' | 'path'>('h5')
const qrDataUrl = ref('')

async function renderQr() {
  const text = qrMode.value === 'h5' ? h5Url.value : props.path
  try {
    qrDataUrl.value = await QRCode.toDataURL(text, { width: 200, margin: 1 })
  } catch {
    qrDataUrl.value = ''
  }
}

watch(
  () => props.visible,
  (v) => {
    if (v) {
      qrMode.value = 'h5'
      centerPanel()
      renderQr()
    }
  },
)
watch(qrMode, () => {
  if (props.visible) renderQr()
})

// ============ 拖拽 ============
const PANEL_W = 560
const PANEL_H = 480
const pos = reactive({ x: 0, y: 0 })
let dragging = false
let dragOffsetX = 0
let dragOffsetY = 0

function centerPanel() {
  pos.x = Math.max(0, Math.round((window.innerWidth - PANEL_W) / 2))
  pos.y = Math.max(0, Math.round((window.innerHeight - PANEL_H) / 2))
}

function onDragStart(e: MouseEvent) {
  if ((e.target as HTMLElement).closest('.lam-close')) return
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

// ============ 复制（clipboard 优先，execCommand 降级） ============
async function copyText(text: string) {
  try {
    await navigator.clipboard.writeText(text)
    ElMessage.success('复制成功')
    return
  } catch {
    // 降级方案
  }
  const textarea = document.createElement('textarea')
  textarea.value = text
  textarea.style.position = 'fixed'
  textarea.style.opacity = '0'
  document.body.appendChild(textarea)
  textarea.select()
  let ok = false
  try {
    ok = document.execCommand('copy')
  } catch {
    ok = false
  }
  document.body.removeChild(textarea)
  ElMessage[ok ? 'success' : 'error'](ok ? '复制成功' : '复制失败，请手动复制')
}
</script>

<template>
  <Teleport to="body">
    <div v-if="visible" class="lam-mask" @click.self="close">
      <div
        class="lam-panel"
        :style="{ left: pos.x + 'px', top: pos.y + 'px', width: PANEL_W + 'px' }"
      >
        <div class="lam-header" @mousedown="onDragStart">
          <span class="lam-title">链接地址<span v-if="name" class="lam-name">（{{ name }}）</span></span>
          <span class="lam-close" title="关闭" @click="close">&times;</span>
        </div>

        <div class="lam-body">
          <div class="lam-qr">
            <img v-if="qrDataUrl" :src="qrDataUrl" alt="二维码" class="lam-qr-img" />
          </div>
          <div class="lam-qr-actions">
            <el-button
              size="small"
              :type="qrMode === 'path' ? 'primary' : 'default'"
              @click="qrMode = qrMode === 'path' ? 'h5' : 'path'"
            >
              {{ qrMode === 'path' ? '返回 H5 链接码' : '查看微信小程序码' }}
            </el-button>
            <div class="lam-qr-tip">本地环境无微信凭证，以页面路径码替代小程序码</div>
          </div>

          <div class="lam-row">
            <div class="lam-row-label">
              链接地址：
              <el-button size="small" @click="copyText(h5Url)">复制</el-button>
              <el-button size="small" type="primary" @click="previewH5">预览</el-button>
            </div>
            <div class="lam-row-value">{{ h5Url }}</div>
          </div>
          <div class="lam-row">
            <div class="lam-row-label">
              小程序链接地址：
              <el-button size="small" @click="copyText(mpPath)">复制</el-button>
            </div>
            <div class="lam-row-value">{{ mpPath }}</div>
          </div>
          <div class="lam-row">
            <div class="lam-row-label">
              页面路径：
              <el-button size="small" @click="copyText(path)">复制</el-button>
            </div>
            <div class="lam-row-value">{{ path }}</div>
          </div>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.lam-mask {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.2);
  z-index: 3100;
}

.lam-panel {
  position: absolute;
  background: #fff;
  border-radius: 6px;
  box-shadow: 0 8px 30px rgba(0, 0, 0, 0.25);
  overflow: hidden;
}

.lam-header {
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

.lam-title {
  font-size: 14px;
  font-weight: 600;
  color: #303133;
}

.lam-name {
  font-weight: 400;
  color: #909399;
  font-size: 12px;
}

.lam-close {
  font-size: 20px;
  line-height: 1;
  color: #909399;
  cursor: pointer;
  padding: 2px 6px;
}

.lam-close:hover {
  color: #f56c6c;
}

.lam-body {
  padding: 16px 24px 20px;
}

.lam-qr {
  display: flex;
  justify-content: center;
}

.lam-qr-img {
  width: 200px;
  height: 200px;
}

.lam-qr-actions {
  text-align: center;
  margin: 10px 0 18px;
}

.lam-qr-tip {
  margin-top: 6px;
  font-size: 12px;
  color: #909399;
}

.lam-row {
  margin-top: 12px;
  line-height: 24px;
}

.lam-row-label {
  font-size: 13px;
  color: #303133;
}

.lam-row-value {
  font-size: 13px;
  color: #606266;
  word-break: break-all;
}
</style>
