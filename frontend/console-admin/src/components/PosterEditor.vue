<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { getPoster, savePoster } from '../api/memberGroup'
import type { PosterElement } from '../api/memberGroup'
import ImgIconPicker from './ImgIconPicker.vue'

// 共享海报编辑器：画布（背景图 + 元素拖拽/缩放/属性编辑）+ 保存 content JSON 到 admin_set_poster
// 供商品/会员分享/拼团/砍价/幸运拼团/团购/积分兑换/周期购等多类海报页复用
type PosterEditorType =
  | 'product'
  | 'index'
  | 'collage'
  | 'collageteam'
  | 'kanjia'
  | 'kanjiateam'
  | 'lucky_collage'
  | 'lucky_collageteam'
  | 'tuangou'
  | 'tuangouteam'
  | 'scoreshop'
  | 'cycle'
  | 'shortvideo'
  | 'kecheng'
  | 'yuyue'

// 八个终端：mp 微信公众号 / wx 微信小程序 / alipay 支付宝小程序 / baidu 百度小程序 /
// toutiao 抖音小程序 / qq QQ小程序 / h5 手机H5 / app 手机APP
type PosterPlatform = 'mp' | 'wx' | 'alipay' | 'baidu' | 'toutiao' | 'qq' | 'h5' | 'app'

const props = withDefaults(
  defineProps<{
    type: PosterEditorType
    platform: PosterPlatform
    posterId?: number
    showGuize?: boolean
    // 是否展示"商品图片"元素（商品海报使用，对齐参考系统商品海报元素）
    showProductImage?: boolean
    // 是否始终展示全部二维码元素按钮（拼团海报：网页/微信/支付宝/百度/抖音/QQ 全显示）
    showAllQr?: boolean
  }>(),
  { posterId: 0, showGuize: false, showProductImage: false, showAllQr: false },
)

const emit = defineEmits<{
  (e: 'loaded', data: { id: number; poster_bg: string; poster_data: PosterElement[] }): void
  (e: 'saved', id: number): void
}>()

// ===== 模板状态 =====
const loading = ref(false)
const saving = ref(false)
const templateId = ref(0)
const posterBg = ref('')
const elements = ref<PosterElement[]>([])
const guize = ref('')
const clearHistory = ref(false)
const selectedIndex = ref(-1)

const selected = computed<PosterElement | null>(() =>
  selectedIndex.value >= 0 ? elements.value[selectedIndex.value] || null : null,
)

// 画布尺寸（手机海报基准，展示按 0.9 缩放由外层处理，这里用原始 px）
const CANVAS_W = 375
const CANVAS_H = 600

async function load() {
  loading.value = true
  try {
    const res = await getPoster({ type: props.type, platform: props.platform, id: props.posterId || undefined })
    templateId.value = res.id
    posterBg.value = res.poster_bg
    elements.value = (res.poster_data || []).map((el) => ({ ...el }))
    guize.value = res.guize || ''
    selectedIndex.value = -1
    emit('loaded', { id: res.id, poster_bg: posterBg.value, poster_data: elements.value })
  } catch {
    // 拦截器已提示
  } finally {
    loading.value = false
  }
}

watch(() => [props.type, props.platform, props.posterId], load, { immediate: true })

// ===== 数值工具（px 字符串互转） =====
function px(v: string | undefined): number {
  return parseInt(String(v || '0'), 10) || 0
}
function toPx(v: number): string {
  return `${Math.round(v)}px`
}

// ===== 拖拽移动 =====
let dragStart = { x: 0, y: 0, left: 0, top: 0 }
let dragging = false

function onElementMousedown(e: MouseEvent, index: number) {
  // 仅左键参与拖拽，右键交给上下文层级菜单
  if (e.button !== 0) return
  e.preventDefault()
  e.stopPropagation()
  selectedIndex.value = index
  const el = elements.value[index]
  dragging = true
  dragStart = { x: e.clientX, y: e.clientY, left: px(el.left), top: px(el.top) }
  document.addEventListener('mousemove', onDragMove)
  document.addEventListener('mouseup', onDragEnd)
}

function onDragMove(e: MouseEvent) {
  if (!dragging || selectedIndex.value < 0) return
  const el = elements.value[selectedIndex.value]
  const w = px(el.width)
  const h = px(el.height)
  let left = dragStart.left + (e.clientX - dragStart.x)
  let top = dragStart.top + (e.clientY - dragStart.y)
  left = Math.max(0, Math.min(left, CANVAS_W - w))
  top = Math.max(0, Math.min(top, CANVAS_H - h))
  el.left = toPx(left)
  el.top = toPx(top)
}

function onDragEnd() {
  dragging = false
  document.removeEventListener('mousemove', onDragMove)
  document.removeEventListener('mouseup', onDragEnd)
}

// ===== 右下角手柄缩放 =====
let resizeStart = { x: 0, y: 0, w: 0, h: 0 }
let resizing = false

function onResizeMousedown(e: MouseEvent) {
  e.preventDefault()
  e.stopPropagation()
  if (selectedIndex.value < 0) return
  const el = elements.value[selectedIndex.value]
  resizing = true
  resizeStart = { x: e.clientX, y: e.clientY, w: px(el.width), h: px(el.height) }
  document.addEventListener('mousemove', onResizeMove)
  document.addEventListener('mouseup', onResizeEnd)
}

function onResizeMove(e: MouseEvent) {
  if (!resizing || selectedIndex.value < 0) return
  const el = elements.value[selectedIndex.value]
  const w = Math.max(10, resizeStart.w + (e.clientX - resizeStart.x))
  const h = Math.max(10, resizeStart.h + (e.clientY - resizeStart.y))
  el.width = toPx(w)
  el.height = toPx(h)
}

function onResizeEnd() {
  resizing = false
  document.removeEventListener('mousemove', onResizeMove)
  document.removeEventListener('mouseup', onResizeEnd)
}

onMounted(() => {
  // 点击菜单外区域关闭右键菜单
  document.addEventListener('mousedown', closeCtx)
})

onBeforeUnmount(() => {
  document.removeEventListener('mousedown', closeCtx)
  document.removeEventListener('mousemove', onDragMove)
  document.removeEventListener('mouseup', onDragEnd)
  document.removeEventListener('mousemove', onResizeMove)
  document.removeEventListener('mouseup', onResizeEnd)
})

// ===== 元素增删 =====
const ELEMENT_PRESETS: Record<PosterElement['type'], () => PosterElement> = {
  img: () => ({
    left: '100px', top: '100px', type: 'img', width: '120px', height: '120px',
    src: '/static/img/poster_goods.jpg',
  }),
  pro_img: () => ({ left: '60px', top: '60px', type: 'pro_img', width: '255px', height: '255px' }),
  shadow: () => ({ left: '20px', top: '430px', type: 'shadow', width: '335px', height: '70px' }),
  text: () => ({
    left: '100px', top: '100px', type: 'text', width: '160px', height: '22px',
    size: '16px', color: '#333333', content: '文字内容',
  }),
  textarea: () => ({
    left: '100px', top: '100px', type: 'textarea', width: '200px', height: '47px',
    size: '14px', color: '#333333', content: '多行文字内容',
  }),
  head: () => ({
    left: '100px', top: '100px', type: 'head', width: '50px', height: '50px', radius: '100',
  }),
  qrmp: () => ({ left: '100px', top: '100px', type: 'qrmp', width: '94px', height: '94px', size: '' }),
  qrwx: () => ({ left: '100px', top: '100px', type: 'qrwx', width: '94px', height: '94px', size: '' }),
  qralipay: () => ({ left: '100px', top: '100px', type: 'qralipay', width: '94px', height: '94px', size: '' }),
  qrbaidu: () => ({ left: '100px', top: '100px', type: 'qrbaidu', width: '94px', height: '94px', size: '' }),
  qrtoutiao: () => ({ left: '100px', top: '100px', type: 'qrtoutiao', width: '94px', height: '94px', size: '' }),
  qrqq: () => ({ left: '100px', top: '100px', type: 'qrqq', width: '94px', height: '94px', size: '' }),
}

function addElement(type: PosterElement['type']) {
  elements.value.push(ELEMENT_PRESETS[type]())
  selectedIndex.value = elements.value.length - 1
}

function removeSelected() {
  if (selectedIndex.value < 0) return
  elements.value.splice(selectedIndex.value, 1)
  selectedIndex.value = -1
}

// ===== 右键层级菜单（对齐参考系统：上层/下层/最顶层/最底层/删除） =====
const ctxMenu = ref<{ visible: boolean; x: number; y: number; index: number }>({
  visible: false,
  x: 0,
  y: 0,
  index: -1,
})

function onContextMenu(e: MouseEvent, index: number) {
  selectedIndex.value = index
  ctxMenu.value = { visible: true, x: e.clientX, y: e.clientY, index }
}

function closeCtx() {
  ctxMenu.value.visible = false
}

// 数组顺序即层级（越靠后越渲染在上层）
function layerMove(action: 'up' | 'down' | 'top' | 'bottom') {
  const arr = elements.value
  let i = ctxMenu.value.index
  if (i < 0 || i >= arr.length) {
    closeCtx()
    return
  }
  if (action === 'up' && i < arr.length - 1) {
    ;[arr[i], arr[i + 1]] = [arr[i + 1], arr[i]]
    i += 1
  } else if (action === 'down' && i > 0) {
    ;[arr[i], arr[i - 1]] = [arr[i - 1], arr[i]]
    i -= 1
  } else if (action === 'top' && i < arr.length - 1) {
    const it = arr.splice(i, 1)[0]
    arr.push(it)
    i = arr.length - 1
  } else if (action === 'bottom' && i > 0) {
    const it = arr.splice(i, 1)[0]
    arr.unshift(it)
    i = 0
  }
  ctxMenu.value.index = i
  selectedIndex.value = i
  closeCtx()
}

function ctxDelete() {
  const i = ctxMenu.value.index
  if (i >= 0 && i < elements.value.length) {
    elements.value.splice(i, 1)
    if (selectedIndex.value === i) selectedIndex.value = -1
  }
  closeCtx()
}

function elementTypeText(type: string): string {
  const map: Record<string, string> = {
    img: '图片',
    pro_img: '商品图片',
    shadow: '阴影区',
    text: '文字',
    textarea: '文本块',
    head: '头像',
    qrmp: '网页二维码',
    qrwx: '微信小程序码',
    qralipay: '支付宝小程序码',
    qrbaidu: '百度小程序码',
    qrtoutiao: '抖音小程序码',
    qrqq: 'QQ小程序码',
  }
  return map[type] || type
}

// 二维码元素元信息：画布占位图标字 + 名称
const QR_META: Record<string, { icon: string; name: string; color: string }> = {
  qrmp: { icon: '网', name: '网页二维码', color: '#409eff' },
  qrwx: { icon: '微', name: '微信小程序码', color: '#07c160' },
  qralipay: { icon: '支', name: '支付宝小程序码', color: '#1677ff' },
  qrbaidu: { icon: '百', name: '百度小程序码', color: '#2932e1' },
  qrtoutiao: { icon: '抖', name: '抖音小程序码', color: '#161823' },
  qrqq: { icon: 'Q', name: 'QQ小程序码', color: '#12b7f5' },
}

function isQrType(t: string): boolean {
  return t.startsWith('qr')
}

function qrMeta(t: string): { icon: string; name: string; color: string } {
  return QR_META[t] || { icon: '码', name: '二维码', color: '#909399' }
}

// 当前终端对应的小程序码（微信公众号 / 手机H5 / 手机APP 无，仅网页二维码）
const miniQr = computed<{ type: PosterElement['type']; name: string } | null>(() => {
  const map: Record<string, { type: PosterElement['type']; name: string }> = {
    wx: { type: 'qrwx', name: '微信小程序码' },
    alipay: { type: 'qralipay', name: '支付宝小程序码' },
    baidu: { type: 'qrbaidu', name: '百度小程序码' },
    toutiao: { type: 'qrtoutiao', name: '抖音小程序码' },
    qq: { type: 'qrqq', name: 'QQ小程序码' },
  }
  return map[props.platform] || null
})

// ===== 图片选择（ImgIconPicker：背景图 / 图片元素） =====
const imgPickerVisible = ref(false)
const pickTarget = ref<((url: string) => void) | null>(null)

function openImgPick(set: (url: string) => void) {
  pickTarget.value = set
  imgPickerVisible.value = true
}

function onImgPick(urls: string[]) {
  if (pickTarget.value && urls[0]) pickTarget.value(urls[0])
  pickTarget.value = null
}

// ===== 保存 =====
async function save() {
  saving.value = true
  try {
    const res = await savePoster({
      type: props.type,
      platform: props.platform,
      id: templateId.value,
      poster_bg: posterBg.value,
      poster_data: elements.value,
      guize: guize.value,
      clearhistory: clearHistory.value ? 1 : 0,
    })
    templateId.value = res.id
    clearHistory.value = false
    ElMessage.success('保存成功')
    emit('saved', res.id)
  } catch {
    // 拦截器已提示
  } finally {
    saving.value = false
  }
}

async function resetBg() {
  try {
    await ElMessageBox.confirm('恢复默认背景图？', '提示', { type: 'warning' })
    posterBg.value = '/static/img/posterbg.jpg'
  } catch {
    // 取消
  }
}

function onCanvasClick() {
  selectedIndex.value = -1
}

defineExpose({ reload: load, templateId })
</script>

<template>
  <div class="poster-editor" v-loading="loading">
    <!-- 左：画布 -->
    <div class="canvas-wrap">
      <div
        class="canvas"
        :style="{ width: `${CANVAS_W}px`, height: `${CANVAS_H}px` }"
        @click="onCanvasClick"
        @contextmenu.prevent
      >
        <img v-if="posterBg" :src="posterBg" class="canvas-bg" alt="背景" draggable="false" />
        <div
          v-for="(el, idx) in elements"
          :key="idx"
          class="poster-el"
          :class="{ active: idx === selectedIndex }"
          :style="{ left: el.left, top: el.top, width: el.width, height: el.height }"
          @mousedown="onElementMousedown($event, idx)"
          @click.stop
          @contextmenu.prevent="onContextMenu($event, idx)"
        >
          <img v-if="el.type === 'img' && el.src" :src="el.src" class="fill" draggable="false" />
          <div
            v-else-if="el.type === 'text' || el.type === 'textarea'"
            class="fill text-el"
            :style="{ fontSize: el.size || '14px', color: el.color || '#333', whiteSpace: el.type === 'textarea' ? 'normal' : 'nowrap' }"
          >
            {{ el.content }}
          </div>
          <div
            v-else-if="el.type === 'head'"
            class="fill head-el"
            :style="{ borderRadius: `${Math.min(50, Number(el.radius || 0))}%` }"
          >
            头像
          </div>
          <div v-else-if="el.type === 'pro_img'" class="fill pro-img-el">商品图片</div>
          <div v-else-if="el.type === 'shadow'" class="fill shadow-el" />
          <div v-else-if="isQrType(el.type)" class="fill qr-el">
            <span class="qr-icon" :style="{ background: qrMeta(el.type).color }">{{ qrMeta(el.type).icon }}</span>
            <span class="qr-tip">{{ qrMeta(el.type).name }}</span>
          </div>
          <div v-if="idx === selectedIndex" class="resize-handle" @mousedown.stop="onResizeMousedown" />
        </div>
        <div v-if="!elements.length" class="canvas-empty">点击右侧按钮添加海报元素</div>
      </div>
    </div>

    <!-- 右：工具与属性 -->
    <div class="panel">
      <div class="panel-block">
        <div class="panel-title">背景图片</div>
        <div class="bg-row">
          <el-button size="small" @click="openImgPick((url) => (posterBg = url))">选择或上传</el-button>
          <el-button size="small" @click="resetBg">恢复默认</el-button>
          <span class="panel-hint">建议尺寸：宽680像素，高1300像素</span>
        </div>
      </div>

      <div class="panel-block">
        <div class="panel-title">海报元素</div>
        <div class="add-row">
          <el-button size="small" @click="addElement('head')">头像</el-button>
          <el-button size="small" @click="addElement('text')">文字</el-button>
          <el-button size="small" @click="addElement('img')">图片</el-button>
          <el-button v-if="showProductImage" size="small" @click="addElement('pro_img')">商品图片</el-button>
          <el-button size="small" @click="addElement('shadow')">阴影区</el-button>
          <el-button size="small" @click="addElement('textarea')">文本块</el-button>
        </div>
      </div>

      <div class="panel-block">
        <div class="panel-title">二维码元素</div>
        <div class="add-row">
          <el-button size="small" @click="addElement('qrmp')">网页二维码</el-button>
          <template v-if="showAllQr">
            <el-button size="small" @click="addElement('qrwx')">微信小程序码</el-button>
            <el-button size="small" @click="addElement('qralipay')">支付宝小程序码</el-button>
            <el-button size="small" @click="addElement('qrbaidu')">百度小程序码</el-button>
            <el-button size="small" @click="addElement('qrtoutiao')">抖音小程序码</el-button>
            <el-button size="small" @click="addElement('qrqq')">QQ小程序码</el-button>
          </template>
          <el-button v-else-if="miniQr" size="small" @click="miniQr && addElement(miniQr.type)">{{ miniQr.name }}</el-button>
        </div>
      </div>

      <div v-if="selected" class="panel-block">
        <div class="panel-title">元素属性 · {{ elementTypeText(selected.type) }}</div>
        <el-form label-width="64px" size="small">
          <el-form-item label="X坐标">
            <el-input
              :model-value="px(selected.left)"
              @update:model-value="(v: string | number) => selected && (selected.left = toPx(Number(v) || 0))"
            >
              <template #append>px</template>
            </el-input>
          </el-form-item>
          <el-form-item label="Y坐标">
            <el-input
              :model-value="px(selected.top)"
              @update:model-value="(v: string | number) => selected && (selected.top = toPx(Number(v) || 0))"
            >
              <template #append>px</template>
            </el-input>
          </el-form-item>
          <el-form-item label="宽度">
            <el-input
              :model-value="px(selected.width)"
              @update:model-value="(v: string | number) => selected && (selected.width = toPx(Number(v) || 0))"
            >
              <template #append>px</template>
            </el-input>
          </el-form-item>
          <el-form-item label="高度">
            <el-input
              :model-value="px(selected.height)"
              @update:model-value="(v: string | number) => selected && (selected.height = toPx(Number(v) || 0))"
            >
              <template #append>px</template>
            </el-input>
          </el-form-item>

          <template v-if="selected.type === 'img'">
            <el-form-item label="图片地址">
              <el-input v-model="selected.src" placeholder="图片URL" />
            </el-form-item>
            <el-form-item label="选择图片">
              <div class="img-pick-row">
                <span class="img-box">
                  <img v-if="selected.src" :src="selected.src" />
                  <span v-else class="img-empty">—</span>
                </span>
                <el-button size="small" @click="openImgPick((url) => selected && (selected.src = url))">选择图片</el-button>
              </div>
            </el-form-item>
          </template>

          <template v-if="selected.type === 'text' || selected.type === 'textarea'">
            <el-form-item label="内容">
              <el-input
                v-model="selected.content"
                :type="selected.type === 'textarea' ? 'textarea' : 'text'"
                :rows="3"
                placeholder="支持占位符：[昵称]/[商品名称]/[价格]/[商品销售价]/[商品市场价]"
              />
            </el-form-item>
            <el-form-item label="字号">
              <el-input
                :model-value="px(selected.size || '14px')"
                @update:model-value="(v: string | number) => selected && (selected.size = toPx(Number(v) || 14))"
              >
                <template #append>px</template>
              </el-input>
            </el-form-item>
            <el-form-item label="颜色">
              <el-color-picker v-model="selected.color" />
            </el-form-item>
          </template>

          <template v-if="selected.type === 'head'">
            <el-form-item label="圆角度">
              <el-slider v-model="selected.radius" :min="0" :max="100" />
            </el-form-item>
          </template>

          <el-form-item>
            <el-button type="danger" size="small" @click="removeSelected">删除该元素</el-button>
          </el-form-item>
        </el-form>
      </div>
      <el-empty v-else description="点击画布元素进行编辑" :image-size="50" />

      <div v-if="showGuize" class="panel-block">
        <div class="panel-title">规则说明（C端展示）</div>
        <el-input v-model="guize" type="textarea" :rows="4" placeholder="分享海报规则文案" />
      </div>

      <div class="panel-block save-row">
        <el-checkbox v-if="showGuize" v-model="clearHistory">保存后清理已生成的会员海报</el-checkbox>
        <el-button type="primary" :loading="saving" @click="save">保存</el-button>
      </div>
    </div>

    <ul
      v-if="ctxMenu.visible"
      class="ctx-menu"
      :style="{ left: `${ctxMenu.x}px`, top: `${ctxMenu.y}px` }"
      @mousedown.stop
      @click.stop
      @contextmenu.prevent.stop
    >
      <li @click="layerMove('up')">调整到上层</li>
      <li @click="layerMove('down')">调整到下层</li>
      <li @click="layerMove('top')">调整到最顶层</li>
      <li @click="layerMove('bottom')">调整到最底层</li>
      <li class="ctx-danger" @click="ctxDelete">删除元素</li>
    </ul>

    <ImgIconPicker v-model:visible="imgPickerVisible" default-tab="library" @pick="(urls: string[]) => onImgPick(urls)" />
  </div>
</template>

<style scoped>
.poster-editor {
  display: flex;
  gap: 16px;
  align-items: flex-start;
}
.canvas-wrap {
  flex: none;
  padding: 10px;
  background: #f0f2f5;
  border-radius: 8px;
}
.canvas {
  position: relative;
  overflow: hidden;
  background: #fff;
  box-shadow: 0 2px 12px rgba(0, 0, 0, 0.12);
}
.canvas-bg {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  object-fit: cover;
  user-select: none;
}
.canvas-empty {
  position: absolute;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #909399;
  font-size: 13px;
}
.poster-el {
  position: absolute;
  cursor: move;
  border: 1px dashed transparent;
  box-sizing: border-box;
}
.poster-el:hover {
  border-color: rgba(64, 158, 255, 0.5);
}
.poster-el.active {
  border-color: #409eff;
}
.fill {
  width: 100%;
  height: 100%;
  display: block;
}
.text-el {
  overflow: hidden;
  line-height: 1.4;
  user-select: none;
}
.head-el {
  background: #dcdfe6;
  color: #909399;
  font-size: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
  overflow: hidden;
}
.pro-img-el {
  border: 1px dashed #c0c4cc;
  background: #f5f7fa;
  color: #909399;
  font-size: 13px;
  display: flex;
  align-items: center;
  justify-content: center;
}
.shadow-el {
  background: rgba(0, 0, 0, 0.28);
  border-radius: 4px;
}
.qr-el {
  background: #fff;
  border: 1px solid #dcdfe6;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 4px;
}
.qr-icon {
  width: 28px;
  height: 28px;
  border-radius: 6px;
  color: #fff;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 14px;
}
.qr-el .qr-tip {
  font-size: 11px;
  color: #909399;
  text-align: center;
}
.ctx-menu {
  position: fixed;
  z-index: 9999;
  min-width: 120px;
  margin: 0;
  padding: 4px 0;
  list-style: none;
  background: #fff;
  border: 1px solid #e4e7ed;
  border-radius: 6px;
  box-shadow: 0 4px 16px rgba(0, 0, 0, 0.14);
  user-select: none;
}
.ctx-menu li {
  padding: 7px 16px;
  font-size: 13px;
  color: #303133;
  cursor: pointer;
}
.ctx-menu li:hover {
  background: #f5f7fa;
  color: #409eff;
}
.ctx-menu li.ctx-danger:hover {
  color: #f56c6c;
}
.resize-handle {
  position: absolute;
  right: -5px;
  bottom: -5px;
  width: 10px;
  height: 10px;
  background: #409eff;
  border: 1px solid #fff;
  border-radius: 2px;
  cursor: nwse-resize;
}
.panel {
  flex: 1;
  min-width: 320px;
}
.panel-block {
  margin-bottom: 16px;
  padding-bottom: 12px;
  border-bottom: 1px solid #f0f2f5;
}
.panel-title {
  font-size: 13px;
  font-weight: 600;
  color: #303133;
  margin-bottom: 8px;
}
.panel-hint {
  color: #909399;
  font-size: 12px;
  align-self: center;
}
.bg-row,
.add-row {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  align-items: center;
}
.save-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  border-bottom: none;
}
.img-pick-row {
  display: flex;
  align-items: center;
  gap: 8px;
}
.img-box {
  width: 40px;
  height: 40px;
  border: 1px solid #ebeef5;
  border-radius: 4px;
  display: flex;
  align-items: center;
  justify-content: center;
  overflow: hidden;
  background: #fafafa;
  flex-shrink: 0;
}
.img-box img {
  max-width: 100%;
  max-height: 100%;
}
.img-empty {
  color: #c0c4cc;
}
</style>
