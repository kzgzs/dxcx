<script setup lang="ts">
/**
 * 统一选图选图标组件（任务296 对照参考重做）
 * 固定双 Tab：上传图片｜选择图标。
 * - iconOnly 语义=默认激活图标 Tab，不隐藏上传 Tab（接入方零改动；defaultTab='library' 旧值兼容映射为上传 Tab）
 * - 上传 Tab：左分组树（两级+未分组+新建分组入口）+关键词搜索+排序下拉+右上「上传图片」蓝色按钮
 *   （真实触发 input file → POST /admin/upload 入库后刷新网格）+网格（缩略图+名称，选中右上蓝勾）
 *   +底部全选/移动/删除+分页28/页；确定无选中时仅禁用不报错（移除「请选择要上传的文件」误报）
 * - 图标 Tab：最近使用行+搜索+分类行+网格；点图标弹内层小窗：棋盘格透明底大预览（随改色实时变）
 *   +预设色块两排+hex输入+el-color-picker+尺寸px+确定
 * - 图标链路：iconToSvg 带 width/height → canvas onload → dataURL(data:image/png;base64)
 *   → POST /admin/upload/iconrender → 回填 url
 * emit：pick(urls: string[], meta)；单选场景 urls[0]
 */
import { computed, reactive, ref, watch } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { uploadFile } from '../api/goods'
import {
  getUploadBrowser,
  deleteUploadFiles,
  moveUploadFiles,
  addUploadGroup,
  renderUploadIcon,
  assetUrl,
} from '../api/upload'
import type { UploadAssetRow, UploadGroupNode } from '../api/upload'
import { ICON_CATEGORIES, searchIcons, iconToDataUrl, iconToSvg } from '../assets/iconlib'
import type { IconItem } from '../assets/iconlib'

/** 对外兼容旧值：library 等价 upload */
type TabKey = 'upload' | 'library' | 'icon'
type ActiveTab = 'upload' | 'icon'

export interface PickMeta {
  from: TabKey
  names?: string[]
  iconId?: string
  fill?: string
  size?: number
}

const props = withDefaults(
  defineProps<{
    visible: boolean
    /** 多选（否则单选，urls[0]） */
    multi?: boolean
    /** 仅默认激活图标 Tab，不隐藏上传 Tab */
    iconOnly?: boolean
    defaultTab?: TabKey
  }>(),
  { multi: false, iconOnly: false, defaultTab: 'upload' },
)

const emit = defineEmits<{
  (e: 'update:visible', v: boolean): void
  (e: 'pick', urls: string[], meta: PickMeta): void
}>()

function validDefaultTab(): ActiveTab {
  if (props.iconOnly) return 'icon'
  return props.defaultTab === 'icon' ? 'icon' : 'upload'
}

const activeTab = ref<ActiveTab>(validDefaultTab())

// ============ 选中集合（上传 Tab 用） ============
const selected = ref<Array<{ url: string; name: string }>>([])
function isSelected(url: string) {
  return selected.value.some((s) => s.url === url)
}
function toggleSelect(url: string, name: string) {
  const idx = selected.value.findIndex((s) => s.url === url)
  if (idx >= 0) {
    selected.value.splice(idx, 1)
    return
  }
  if (!props.multi) {
    selected.value = [{ url, name }]
    return
  }
  selected.value.push({ url, name })
}
const confirmDisabled = computed(() => selected.value.length === 0)

// ============ 上传 Tab：素材库浏览 + 真实上传 ============
const VIDEO_EXTS = ['mp4', 'mov', 'avi', 'wmv', 'flv', 'mkv', 'webm', 'm4v']
function isVideoRow(row: UploadAssetRow) {
  return VIDEO_EXTS.includes(String(row.type || '').toLowerCase())
}

const libQuery = reactive({
  gid: '' as number | '',
  keyword: '',
  sort: 'new' as 'new' | 'old' | 'name',
  page: 1,
})
const LIB_PAGESIZE = 28
const libList = ref<UploadAssetRow[]>([])
const libTotal = ref(0)
const libGroups = ref<UploadGroupNode[]>([])
const libLoading = ref(false)
const expanded = ref<Record<number, boolean>>({})

async function loadLibrary() {
  libLoading.value = true
  try {
    const data = await getUploadBrowser({
      gid: libQuery.gid === '' ? undefined : libQuery.gid,
      keyword: libQuery.keyword || undefined,
      sort: libQuery.sort,
      page: libQuery.page,
      pagesize: LIB_PAGESIZE,
    })
    libList.value = data.list || []
    libTotal.value = data.total || 0
    if (data.groups?.length) libGroups.value = data.groups
  } catch {
    libList.value = []
    libTotal.value = 0
  } finally {
    libLoading.value = false
  }
}

function searchLibrary() {
  libQuery.page = 1
  loadLibrary()
}
function onPageChange(p: number) {
  libQuery.page = p
  loadLibrary()
}
function selectGroup(gid: number | '') {
  libQuery.gid = gid
  libQuery.page = 1
  loadLibrary()
}
function toggleExpand(id: number) {
  expanded.value[id] = !expanded.value[id]
}

async function addGroup(pid: number) {
  let name = ''
  try {
    const r = await ElMessageBox.prompt('请填写分组名称', '新建分组', {
      inputPlaceholder: '分组名称',
      confirmButtonText: '确定',
      cancelButtonText: '取消',
    })
    name = String(r.value || '').trim()
  } catch {
    return
  }
  if (!name) return
  try {
    await addUploadGroup(name, pid)
    ElMessage.success('分组已创建')
    if (pid > 0) expanded.value[pid] = true
    loadLibrary()
  } catch {
    /* 拦截器已提示 */
  }
}

// ---- 真实上传：触发隐藏 input file ----
const fileInput = ref<HTMLInputElement | null>(null)
const uploading = ref(false)
function triggerUpload() {
  fileInput.value?.click()
}
async function onFileChange(e: Event) {
  const input = e.target as HTMLInputElement
  const files = Array.from(input.files || [])
  input.value = ''
  if (!files.length) return
  uploading.value = true
  let ok = 0
  try {
    for (const f of files) {
      try {
        await uploadFile(f)
        ok++
      } catch {
        /* 拦截器已提示 */
      }
    }
  } finally {
    uploading.value = false
  }
  if (ok > 0) {
    ElMessage.success(`上传成功 ${ok} 个文件，点击选中后确定回填`)
    loadLibrary()
  }
}

// ---- 底部：全选 / 移动 / 删除 ----
const allChecked = computed({
  get: () => libList.value.length > 0 && libList.value.every((r) => isSelected(r.url)),
  set: (v: boolean) => {
    if (v) {
      for (const r of libList.value) {
        if (!isSelected(r.url)) selected.value.push({ url: r.url, name: r.name })
      }
    } else {
      const pageUrls = libList.value.map((r) => r.url)
      selected.value = selected.value.filter((s) => !pageUrls.includes(s.url))
    }
  },
})

const selectedIds = computed(() =>
  libList.value.filter((r) => isSelected(r.url)).map((r) => r.id),
)

const moveVisible = ref(false)
const moveGid = ref(0)
function openMove() {
  if (!selectedIds.value.length) {
    ElMessage.warning('请先勾选要移动的素材')
    return
  }
  moveGid.value = 0
  moveVisible.value = true
}
async function confirmMove() {
  try {
    await moveUploadFiles(selectedIds.value, moveGid.value)
    ElMessage.success('移动成功')
    moveVisible.value = false
    selected.value = []
    loadLibrary()
  } catch {
    /* 拦截器已提示 */
  }
}

async function batchDelete() {
  const ids = selectedIds.value
  if (!ids.length) {
    ElMessage.warning('请先勾选要删除的素材')
    return
  }
  try {
    await ElMessageBox.confirm(`确定删除选中的 ${ids.length} 个素材吗？`, '删除素材', {
      type: 'warning',
    })
  } catch {
    return
  }
  try {
    await deleteUploadFiles(ids)
    ElMessage.success('已删除')
    const delUrls = libList.value.filter((r) => ids.includes(r.id)).map((r) => r.url)
    selected.value = selected.value.filter((s) => !delUrls.includes(s.url))
    loadLibrary()
  } catch {
    /* 拦截器已提示 */
  }
}

// ============ 图标 Tab ============
const RECENT_KEY = 'imgicon_recent_icons'
const iconCat = ref('')
const iconKw = ref('')
const filteredIcons = computed(() => searchIcons(iconKw.value, iconCat.value || undefined))

const recentIds = ref<string[]>(loadRecent())
function loadRecent(): string[] {
  try {
    const arr = JSON.parse(localStorage.getItem(RECENT_KEY) || '[]')
    return Array.isArray(arr) ? arr.filter((v) => typeof v === 'string').slice(0, 10) : []
  } catch {
    return []
  }
}
function pushRecent(id: string) {
  recentIds.value = [id, ...recentIds.value.filter((v) => v !== id)].slice(0, 10)
  localStorage.setItem(RECENT_KEY, JSON.stringify(recentIds.value))
}
const recentIcons = computed(() =>
  recentIds.value
    .map((id) => searchIcons('').find((it) => it.id === id))
    .filter((v): v is IconItem => !!v),
)

// ---- 内层改色小窗 ----
const PRESET_COLORS = [
  '#f56c6c', '#e6a23c', '#ffd04d', '#67c23a', '#409eff', '#9b59b6', '#e91e63',
  '#ffffff', '#d8d8d8', '#b0b0b0', '#808080', '#4d4d4d', '#000000',
]
const editingOpen = ref(false)
const editing = ref<IconItem | null>(null)
const editFill = ref('#409eff')
const hexInput = ref('#409eff')
const editSize = ref(64)
const iconRendering = ref(false)

function openEdit(icon: IconItem) {
  editing.value = icon
  editFill.value = icon.defaultFill
  hexInput.value = icon.defaultFill
  editSize.value = 64
  editingOpen.value = true
}
function setFill(color: string) {
  editFill.value = color
  hexInput.value = color
}
function onHexInput() {
  if (/^#[0-9a-fA-F]{6}$/.test(hexInput.value)) editFill.value = hexInput.value
}

/** SVG（带 width/height）→ canvas onload → PNG dataURL */
function svgToPng(svg: string, size: number): Promise<string> {
  return new Promise((resolve, reject) => {
    const img = new Image()
    const url = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(svg)
    img.onload = () => {
      const canvas = document.createElement('canvas')
      canvas.width = size
      canvas.height = size
      const ctx = canvas.getContext('2d')
      if (!ctx) {
        reject(new Error('canvas unavailable'))
        return
      }
      ctx.drawImage(img, 0, 0, size, size)
      resolve(canvas.toDataURL('image/png'))
    }
    img.onerror = () => reject(new Error('svg load error'))
    img.src = url
  })
}

async function confirmIcon() {
  const icon = editing.value
  if (!icon) return
  const size = Math.min(512, Math.max(16, Math.round(editSize.value) || 64))
  iconRendering.value = true
  try {
    const dataurl = await svgToPng(iconToSvg(icon, editFill.value, size), size)
    const res = await renderUploadIcon(dataurl, icon.name, size, size)
    pushRecent(icon.id)
    editingOpen.value = false
    emit('pick', [res.url], {
      from: 'icon',
      names: [icon.name],
      iconId: icon.id,
      fill: editFill.value,
      size,
    })
    close()
  } catch {
    ElMessage.error('图标生成失败')
  } finally {
    iconRendering.value = false
  }
}

// ============ 弹窗控制 ============
watch(
  () => props.visible,
  (v) => {
    if (v) {
      activeTab.value = validDefaultTab()
      selected.value = []
      editing.value = null
      editingOpen.value = false
      if (activeTab.value === 'upload') loadLibrary()
    }
  },
)
watch(activeTab, (t) => {
  if (t === 'upload') loadLibrary()
})

function close() {
  emit('update:visible', false)
}
function confirm() {
  if (!selected.value.length) return
  emit('pick', selected.value.map((s) => s.url), {
    from: 'upload',
    names: selected.value.map((s) => s.name),
  })
  close()
}
</script>

<template>
  <el-dialog
    :model-value="visible"
    title="选择图片/图标"
    width="860px"
    append-to-body
    @update:model-value="(v: boolean) => emit('update:visible', v)"
  >
    <el-tabs v-model="activeTab">
      <!-- ============ 上传图片 ============ -->
      <el-tab-pane name="upload" label="上传图片">
        <div class="iip-up-body">
          <div class="iip-up-side">
            <div class="iip-gnode" :class="{ active: libQuery.gid === '' }" @click="selectGroup('')">全部</div>
            <div class="iip-gnode" :class="{ active: libQuery.gid === 0 }" @click="selectGroup(0)">未分组</div>
            <template v-for="g in libGroups" :key="g.id">
              <div class="iip-gnode top" :class="{ active: libQuery.gid === g.id }">
                <span
                  v-if="g.children && g.children.length"
                  class="iip-garrow"
                  :class="{ open: expanded[g.id] }"
                  @click="toggleExpand(g.id)"
                >▸</span>
                <span v-else class="iip-garrow ph"></span>
                <span class="iip-gname" :title="g.name" @click="selectGroup(g.id)">{{ g.name }}</span>
                <span class="iip-gadd" title="新建子分组" @click.stop="addGroup(g.id)">+</span>
              </div>
              <template v-if="expanded[g.id] && g.children && g.children.length">
                <div
                  v-for="ch in g.children"
                  :key="ch.id"
                  class="iip-gnode child"
                  :class="{ active: libQuery.gid === ch.id }"
                  @click="selectGroup(ch.id)"
                >{{ ch.name }}</div>
              </template>
            </template>
            <div class="iip-gnew" @click="addGroup(0)">新建分组</div>
          </div>

          <div class="iip-up-main">
            <div class="iip-toolbar">
              <el-input
                v-model="libQuery.keyword"
                placeholder="搜索"
                clearable
                style="width: 150px"
                @keyup.enter="searchLibrary"
                @clear="searchLibrary"
              />
              <el-select v-model="libQuery.sort" style="width: 130px" @change="searchLibrary">
                <el-option label="上传时间倒序" value="new" />
                <el-option label="上传时间正序" value="old" />
                <el-option label="名称排序" value="name" />
              </el-select>
              <div class="iip-spacer"></div>
              <el-button type="primary" :loading="uploading" @click="triggerUpload">上传图片</el-button>
              <input
                ref="fileInput"
                type="file"
                accept="image/*,video/*"
                :multiple="multi"
                style="display: none"
                @change="onFileChange"
              />
            </div>

            <div v-loading="libLoading" class="iip-grid">
              <div
                v-for="row in libList"
                :key="row.id"
                class="iip-cell"
                :class="{ 'is-selected': isSelected(row.url) }"
                @click="toggleSelect(row.url, row.name)"
              >
                <img v-if="!isVideoRow(row)" :src="assetUrl(row.thumb || row.url)" :alt="row.name" />
                <div v-else class="iip-video-ph">▶</div>
                <div class="iip-cell-name" :title="row.name">{{ row.name }}</div>
                <span v-if="isSelected(row.url)" class="iip-check">✓</span>
              </div>
              <div v-if="!libLoading && !libList.length" class="iip-empty">暂无素材，点击右上角「上传图片」</div>
            </div>

            <div class="iip-up-foot">
              <el-checkbox v-model="allChecked">全选</el-checkbox>
              <span class="iip-link" @click="openMove">移动</span>
              <span class="iip-link danger" @click="batchDelete">删除</span>
              <div class="iip-spacer"></div>
              <el-pagination
                small
                background
                layout="prev, pager, next"
                :current-page="libQuery.page"
                :page-size="LIB_PAGESIZE"
                :total="libTotal"
                @current-change="onPageChange"
              />
            </div>
          </div>
        </div>
      </el-tab-pane>

      <!-- ============ 选择图标 ============ -->
      <el-tab-pane name="icon" label="选择图标">
        <div class="iip-icon-top">
          <div class="iip-recent-line">
            <span class="iip-recent-label">最近使用:</span>
            <div class="iip-recent-row">
              <span
                v-for="icon in recentIcons"
                :key="'r-' + icon.id"
                class="iip-icon-cell sm"
                :title="icon.name"
                @click="openEdit(icon)"
              >
                <img :src="iconToDataUrl(icon, '#606266', 22)" :alt="icon.name" />
              </span>
              <span v-if="!recentIcons.length" class="iip-recent-empty">暂无</span>
            </div>
          </div>
          <el-input
            v-model="iconKw"
            placeholder="关键词检索（如：购物车/箭头）"
            clearable
            style="width: 220px"
          />
        </div>

        <div class="iip-cat-tabs">
          <span class="iip-cat" :class="{ active: iconCat === '' }" @click="iconCat = ''">全部</span>
          <span
            v-for="cat in ICON_CATEGORIES"
            :key="cat.key"
            class="iip-cat"
            :class="{ active: iconCat === cat.key }"
            @click="iconCat = cat.key"
          >{{ cat.label }}</span>
        </div>

        <div class="iip-sub-title">图标库（{{ filteredIcons.length }}）</div>
        <div class="iip-icon-grid">
          <div
            v-for="icon in filteredIcons"
            :key="icon.id"
            class="iip-icon-cell"
            :title="icon.name"
            @click="openEdit(icon)"
          >
            <img :src="iconToDataUrl(icon, '#606266', 26)" :alt="icon.name" />
          </div>
          <div v-if="!filteredIcons.length" class="iip-empty">未找到匹配图标</div>
        </div>
      </el-tab-pane>
    </el-tabs>

    <template #footer>
      <div class="iip-footer">
        <span v-if="multi && selected.length" class="iip-sel-count">已选 {{ selected.length }} 项</span>
        <el-button @click="close">取消</el-button>
        <el-button v-if="activeTab === 'upload'" type="primary" :disabled="confirmDisabled" @click="confirm">
          确定
        </el-button>
      </div>
    </template>
  </el-dialog>

  <!-- 移动分组小窗 -->
  <el-dialog v-model="moveVisible" title="移动到分组" width="360px" append-to-body>
    <el-select v-model="moveGid" style="width: 100%">
      <el-option label="未分组" :value="0" />
      <template v-for="g in libGroups" :key="'m-' + g.id">
        <el-option :label="g.name" :value="g.id" />
        <el-option
          v-for="ch in g.children || []"
          :key="'m-' + ch.id"
          :label="'├ ' + ch.name"
          :value="ch.id"
        />
      </template>
    </el-select>
    <template #footer>
      <el-button @click="moveVisible = false">取消</el-button>
      <el-button type="primary" @click="confirmMove">确定</el-button>
    </template>
  </el-dialog>

  <!-- 图标改色内层小窗 -->
  <el-dialog
    v-model="editingOpen"
    :title="editing ? editing.name + ' · 改色与尺寸' : ''"
    width="380px"
    append-to-body
    @closed="editing = null"
  >
    <div v-if="editing" class="iip-edit-body">
      <div class="iip-preview">
        <img :src="iconToDataUrl(editing, editFill, 96)" :alt="editing.name" />
      </div>
      <div class="iip-presets">
        <span
          v-for="color in PRESET_COLORS.slice(0, 7)"
          :key="color"
          class="iip-preset"
          :class="{ active: editFill === color }"
          :style="{ background: color }"
          @click="setFill(color)"
        />
      </div>
      <div class="iip-presets">
        <span
          v-for="color in PRESET_COLORS.slice(7)"
          :key="color"
          class="iip-preset"
          :class="{ active: editFill === color }"
          :style="{ background: color }"
          @click="setFill(color)"
        />
      </div>
      <div class="iip-edit-row">
        <el-input
          v-model="hexInput"
          placeholder="#000000"
          style="width: 110px"
          @input="onHexInput"
          @change="onHexInput"
        />
        <el-color-picker v-model="editFill" size="small" @change="(v: string | null) => v && setFill(v)" />
        <el-input-number v-model="editSize" :min="16" :max="512" :step="8" size="small" />
        <span class="iip-size-unit">px</span>
      </div>
      <div class="iip-edit-foot">
        <el-button type="primary" :loading="iconRendering" @click="confirmIcon">确定</el-button>
      </div>
    </div>
  </el-dialog>
</template>

<style scoped>
.iip-up-body {
  display: flex;
  gap: 12px;
  min-height: 420px;
}

.iip-up-side {
  width: 132px;
  flex: none;
  border-right: 1px solid #f0f2f5;
  padding-right: 8px;
  overflow-y: auto;
  max-height: 460px;
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.iip-gnode {
  padding: 6px 8px;
  font-size: 13px;
  color: #606266;
  border-radius: 4px;
  cursor: pointer;
  display: flex;
  align-items: center;
  gap: 4px;
  white-space: nowrap;
}

.iip-gnode:hover {
  background: #f5f7fa;
}

.iip-gnode.active {
  color: var(--el-color-primary);
  background: #ecf5ff;
}

.iip-gnode.child {
  padding-left: 26px;
}

.iip-gname {
  flex: 1;
  overflow: hidden;
  text-overflow: ellipsis;
}

.iip-garrow {
  width: 12px;
  font-size: 11px;
  color: #909399;
  transition: transform 0.15s;
  user-select: none;
}

.iip-garrow.open {
  transform: rotate(90deg);
}

.iip-garrow.ph {
  visibility: hidden;
}

.iip-gadd {
  width: 16px;
  height: 16px;
  line-height: 14px;
  text-align: center;
  border-radius: 3px;
  color: #909399;
  display: none;
  font-size: 13px;
}

.iip-gnode.top:hover .iip-gadd {
  display: block;
}

.iip-gadd:hover {
  color: var(--el-color-primary);
  background: #ecf5ff;
}

.iip-gnew {
  margin-top: 6px;
  padding: 4px 8px;
  font-size: 12px;
  color: var(--el-color-primary);
  cursor: pointer;
}

.iip-gnew:hover {
  text-decoration: underline;
}

.iip-up-main {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
}

.iip-toolbar {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
  margin-bottom: 10px;
}

.iip-spacer {
  flex: 1;
}

.iip-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(96px, 1fr));
  gap: 10px;
  min-height: 260px;
  align-content: start;
}

.iip-cell {
  position: relative;
  border: 2px solid #ebeef5;
  border-radius: 6px;
  overflow: hidden;
  cursor: pointer;
  background: #fff;
}

.iip-cell:hover {
  border-color: #c6e2ff;
}

.iip-cell.is-selected {
  border-color: var(--el-color-primary);
}

.iip-cell img {
  width: 100%;
  height: 72px;
  object-fit: cover;
  display: block;
  background: #f5f7fa;
}

.iip-video-ph {
  height: 72px;
  display: flex;
  align-items: center;
  justify-content: center;
  background: #303133;
  color: #fff;
  font-size: 18px;
}

.iip-cell-name {
  padding: 3px 6px;
  font-size: 12px;
  color: #606266;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  background: #f5f7fa;
}

.iip-check {
  position: absolute;
  top: 4px;
  right: 4px;
  width: 18px;
  height: 18px;
  border-radius: 50%;
  background: var(--el-color-primary);
  color: #fff;
  font-size: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
}

.iip-empty {
  grid-column: 1 / -1;
  padding: 26px 0;
  text-align: center;
  color: #909399;
  font-size: 13px;
}

.iip-up-foot {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-top: 10px;
}

.iip-link {
  font-size: 13px;
  color: var(--el-color-primary);
  cursor: pointer;
}

.iip-link.danger {
  color: #f56c6c;
}

.iip-link:hover {
  text-decoration: underline;
}

.iip-icon-top {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 10px;
}

.iip-recent-line {
  display: flex;
  align-items: center;
  gap: 8px;
  flex: 1;
  min-width: 0;
}

.iip-recent-label {
  font-size: 13px;
  color: #606266;
  flex: none;
}

.iip-recent-row {
  display: flex;
  align-items: center;
  gap: 6px;
  flex-wrap: wrap;
}

.iip-recent-empty {
  font-size: 12px;
  color: #c0c4cc;
}

.iip-cat-tabs {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  margin-bottom: 8px;
}

.iip-cat {
  padding: 3px 10px;
  font-size: 12px;
  color: #606266;
  border: 1px solid #e7e7eb;
  border-radius: 12px;
  cursor: pointer;
  user-select: none;
}

.iip-cat:hover {
  color: var(--el-color-primary);
  border-color: var(--el-color-primary);
}

.iip-cat.active {
  color: #fff;
  background: var(--el-color-primary);
  border-color: var(--el-color-primary);
}

.iip-sub-title {
  font-size: 13px;
  font-weight: 600;
  color: #303133;
  margin: 8px 0;
}

.iip-icon-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(32px, 1fr));
  gap: 4px;
  max-height: 300px;
  overflow-y: auto;
}

.iip-icon-cell {
  height: 30px;
  display: flex;
  align-items: center;
  justify-content: center;
  border: 1px solid #f0f2f5;
  border-radius: 6px;
  cursor: pointer;
  background: #fff;
}

.iip-icon-cell:hover {
  border-color: var(--el-color-primary);
  background: #f5f9ff;
}

.iip-icon-cell.sm {
  width: 34px;
  height: 34px;
  flex: none;
}

/* 内层改色小窗 */
.iip-edit-body {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.iip-preview {
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: 150px;
  border: 1px solid #ebeef5;
  border-radius: 6px;
  background: repeating-conic-gradient(#f2f2f2 0 25%, #ffffff 0 50%) 0 0 / 16px 16px;
}

.iip-preview img {
  width: 96px;
  height: 96px;
}

.iip-presets {
  display: flex;
  align-items: center;
  gap: 8px;
}

.iip-preset {
  width: 22px;
  height: 22px;
  border-radius: 4px;
  border: 2px solid #e7e7eb;
  cursor: pointer;
}

.iip-preset.active {
  border-color: #303133;
  box-shadow: 0 0 0 2px rgba(48, 49, 51, 0.15);
}

.iip-edit-row {
  display: flex;
  align-items: center;
  gap: 10px;
}

.iip-size-unit {
  font-size: 12px;
  color: #909399;
}

.iip-edit-foot {
  display: flex;
  justify-content: flex-end;
}

.iip-footer {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 10px;
}

.iip-sel-count {
  margin-right: auto;
  font-size: 13px;
  color: #606266;
}
</style>
