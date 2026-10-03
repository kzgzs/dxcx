<script setup lang="ts">
/**
 * 查看链接小弹窗（全站统一标准，对标参考系统 chooseurl / 查看链接）
 * - 居中弹窗 + 右上角 X 关闭 + 点击遮罩关闭
 * - 二维码前端生成（qrcode 库，本地无微信凭证）：
 *   默认编码 H5 全链；点「查看小程序码」切换为编码页面路径（以路径码占位小程序码，位置保留）
 * - 字段：链接地址（H5 全链）+ 复制；页面路径 + 复制
 * - 调用处必须用 v-model:visible 绑定响应式变量，禁止硬编码 :visible="true"（否则 X 无法关闭）
 */
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue'
import { ElMessage } from 'element-plus'
import QRCode from 'qrcode'

const props = defineProps<{
  visible: boolean
  /** 可选，仅用于日志/无障碍，标题固定为「查看链接」 */
  name?: string
  /** 小程序/前端页面路径，如 /yuyue/yuyue/product?id=272 */
  path: string
  /** H5 预览专用路径（与小程序路径不同时传入） */
  h5Path?: string
}>()

const emit = defineEmits<{ (e: 'update:visible', v: boolean): void }>()

/** H5 全链拼装口径：DEV 本地哈希；PROD 同域 /h5/ 哈希 */
function buildH5Url(p: string): string {
  const rel = p.replace(/^\//, '')
  return import.meta.env.DEV
    ? location.origin + '/h5/#/' + rel
    : location.origin + '/h5/#/' + rel
}

const h5Url = computed(() => buildH5Url(props.h5Path || props.path))

function close() {
  emit('update:visible', false)
}

// ============ 二维码 / 小程序码切换 ============
const qrMode = ref<'h5' | 'mp'>('h5')
const qrDataUrl = ref('')

async function renderQr() {
  const text = qrMode.value === 'h5' ? h5Url.value : props.path
  try {
    qrDataUrl.value = await QRCode.toDataURL(text, { width: 220, margin: 1 })
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

// ============ 居中（弹窗固定居中，不拖拽，与截图一致） ============
const PANEL_W = 460
const PANEL_H = 480
const pos = reactive({ x: 0, y: 0 })

function centerPanel() {
  pos.x = Math.max(0, Math.round((window.innerWidth - PANEL_W) / 2))
  pos.y = Math.max(0, Math.round((window.innerHeight - PANEL_H) / 2))
}

onBeforeUnmount(() => {})

// ============ 复制（clipboard 优先，execCommand 降级） ============
async function copyText(text: string) {
  try {
    await navigator.clipboard.writeText(text)
    ElMessage.success('复制成功')
    return
  } catch {
    // 降级
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
        <div class="lam-header">
          <span class="lam-title">查看链接</span>
          <span class="lam-close" title="关闭" @click="close">&times;</span>
        </div>

        <div class="lam-body">
          <div class="lam-qr">
            <img v-if="qrDataUrl" :src="qrDataUrl" :alt="qrMode === 'h5' ? '二维码' : '小程序码'" class="lam-qr-img" />
          </div>
          <div class="lam-qr-actions">
            <el-button
              size="small"
              :type="qrMode === 'mp' ? 'primary' : 'default'"
              @click="qrMode = qrMode === 'mp' ? 'h5' : 'mp'"
            >
              {{ qrMode === 'mp' ? '返回查看二维码' : '查看小程序码' }}
            </el-button>
            <div class="lam-qr-tip">本地环境无微信凭证，以页面路径码占位小程序码</div>
          </div>

          <div class="lam-row">
            <div class="lam-row-label">
              链接地址：<el-button size="small" @click="copyText(h5Url)">复制</el-button>
            </div>
            <div class="lam-row-value">{{ h5Url }}</div>
          </div>
          <div class="lam-row">
            <div class="lam-row-label">
              页面路径：<el-button size="small" @click="copyText(path)">复制</el-button>
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
  background: rgba(0, 0, 0, 0.25);
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
  height: 44px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0 16px;
  border-bottom: 1px solid #ebeef5;
}

.lam-title {
  font-size: 15px;
  font-weight: 600;
  color: #303133;
}

.lam-close {
  font-size: 22px;
  line-height: 1;
  color: #909399;
  cursor: pointer;
  padding: 2px 6px;
}

.lam-close:hover {
  color: #f56c6c;
}

.lam-body {
  padding: 18px 24px 20px;
}

.lam-qr {
  display: flex;
  justify-content: center;
}

.lam-qr-img {
  width: 220px;
  height: 220px;
}

.lam-qr-actions {
  text-align: center;
  margin: 12px 0 16px;
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
  margin-top: 2px;
  font-size: 13px;
  color: #606266;
  word-break:break-all;
}
</style>
