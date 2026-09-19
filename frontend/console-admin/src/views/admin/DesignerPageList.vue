<script setup lang="ts">
/**
 * 页面设计器 - 页面列表（租户侧「设计 - 页面设计」，任务275 卡片化对照参考系统重做）
 * - 卡片网格：标题行（[首页]/[会员中心] 红色前缀 + 标题链接可点编辑）/ 日期 / 缩略图预览区 / 底部图标行
 * - 底部图标显隐规则（对照参考系统 designer_page/index.html）：
 *   首页卡：二维码/编辑/复制（无设为首页、无删除）
 *   会员中心卡：二维码/编辑/复制（无设为首页、无删除）
 *   其它卡：二维码/设为首页/编辑/复制/删除
 * - 设为首页弹框提供「设为首页 / 设为会员中心 / 取消」三态
 * - 模板库已迁出为独立视图「页面模板」（DesignerTemplateList.vue）
 * - type prop：0常规页 2外卖页 3点餐页（餐饮设计页双挂，P24-8b 任务232）
 */
import { nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  copyDesignerPage,
  deleteDesignerPage,
  getDesignerPageDetail,
  getDesignerPageList,
  saveDesignerTemplate,
  setDesignerPageHome,
  type DesignerModule,
  type DesignerPageRow,
} from '../../api/designer'
import LinkAddressModal from '../../components/LinkAddressModal.vue'
import DesignerPreviewFrame from '../../components/DesignerPreviewFrame.vue'

const props = withDefaults(defineProps<{ type?: number; title?: string }>(), {
  type: 0,
  title: '页面设计',
})

// ============ 列表 ============
const pageList = ref<DesignerPageRow[]>([])
const pageLoading = ref(false)
const keyword = ref('')

async function loadPages() {
  pageLoading.value = true
  resetPreviews()
  try {
    const res = await getDesignerPageList({
      type: props.type,
      page: 1,
      limit: 100,
      name: keyword.value.trim() || undefined,
    })
    pageList.value = res.list || []
  } finally {
    pageLoading.value = false
  }
}

function handleSearch() {
  loadPages()
}

// ============ 真实全貌预览（任务276：IntersectionObserver 懒挂载 + detail 取JSON + 缩放渲染） ============
const previewMap = ref<Record<number, { topbar: DesignerModule | null; items: DesignerModule[] }>>({})
const previewLoading = new Set<number>()
const previewFailed = new Set<number>()
const previewEls = new Map<number, HTMLElement>()

function previewOf(id: number) {
  return previewMap.value[id]
}

/** 卡片预览容器 ref 注册（v-for 函数式 ref） */
function setPreviewEl(id: number, el: unknown) {
  if (el instanceof HTMLElement) {
    previewEls.set(id, el)
  } else {
    previewEls.delete(id)
  }
}

// 进入视口再拉取设计JSON（rootMargin 提前 120px 预载）
const io = typeof IntersectionObserver !== 'undefined'
  ? new IntersectionObserver((entries) => {
      for (const entry of entries) {
        if (!entry.isIntersecting) continue
        const id = Number((entry.target as HTMLElement).dataset.pageId || 0)
        io?.unobserve(entry.target)
        if (id > 0) loadPreview(id)
      }
    }, { rootMargin: '120px' })
  : null

/** 懒加载页面设计JSON并渲染（pageinfo[0]=topbar，content=组件列表） */
async function loadPreview(id: number) {
  if (previewMap.value[id] || previewLoading.has(id)) return
  previewLoading.add(id)
  try {
    const detail = await getDesignerPageDetail(id)
    const pageinfo = detail.pageinfo || []
    previewMap.value[id] = {
      topbar: pageinfo.length > 0 && pageinfo[0].temp === 'topbar' ? pageinfo[0] : null,
      items: detail.content || [],
    }
  } catch {
    previewFailed.add(id)
  } finally {
    previewLoading.delete(id)
  }
}

/** 重置预览状态（搜索/刷新重新懒挂载） */
function resetPreviews() {
  previewMap.value = {}
  previewFailed.clear()
  previewLoading.clear()
  previewEls.forEach((el) => io?.unobserve(el))
  previewEls.clear()
}

// 列表渲染后挂观察器（不支持 IntersectionObserver 的环境直接全量加载）
watch(pageList, () => {
  nextTick(() => {
    for (const row of pageList.value) {
      const el = previewEls.get(row.id)
      if (!el) continue
      if (io) {
        io.observe(el)
      } else {
        loadPreview(row.id)
      }
    }
  })
})

onBeforeUnmount(() => {
  io?.disconnect()
})

// 编辑器新开标签地址（hash 路由 /designer/editor?id=；新建时携带 type）
function editorUrl(id?: number): string {
  if (id) {
    return `${location.origin}${location.pathname}#/designer/editor?id=${id}`
  }
  return `${location.origin}${location.pathname}#/designer/editor${props.type ? `?type=${props.type}` : ''}`
}

function handleCreate() {
  window.open(editorUrl(), '_blank')
}

function handleEdit(row: DesignerPageRow) {
  window.open(editorUrl(row.id), '_blank')
}

async function handleCopy(row: DesignerPageRow) {
  await ElMessageBox.confirm(`确定要复制页面「${row.title}」吗？`, '操作确认', {
    confirmButtonText: '复制',
    cancelButtonText: '取消',
  })
  await copyDesignerPage(row.id)
  ElMessage.success('复制成功，已生成副本')
  loadPages()
}

async function handleDel(row: DesignerPageRow) {
  await ElMessageBox.confirm(`确定删除页面「${row.title}」吗？删除后不可恢复`, '删除页面', {
    type: 'warning',
    confirmButtonText: '删除',
    cancelButtonText: '取消',
  })
  await deleteDesignerPage(row.id)
  ElMessage.success('删除成功')
  loadPages()
}

/** 设为首页（三态：设为首页 / 设为会员中心 / 取消，对照参考系统 sethome 弹层） */
async function handleSetHome(row: DesignerPageRow) {
  try {
    await ElMessageBox.confirm(`是否要将「${row.title}」设计为首页？`, '设为首页', {
      confirmButtonText: '设为首页',
      cancelButtonText: '设为会员中心',
      distinguishCancelAndClose: true,
      closeOnClickModal: false,
    })
    await setDesignerPageHome(row.id, 1)
    ElMessage.success('已设为首页')
    loadPages()
  } catch (action) {
    // 点击「设为会员中心」时 reject 参数为 'cancel'；点 X/关闭为 'close'
    if (action === 'cancel') {
      await setDesignerPageHome(row.id, 2)
      ElMessage.success('已设为会员中心页')
      loadPages()
    }
  }
}

// ============ 二维码（复用 LinkAddressModal 小弹窗） ============
const qrVisible = ref(false)
const qrName = ref('')
const qrPath = ref('')
/** 任务318：H5 预览路径（C端 custom 渲染页，pageid 参数口径与 navigate.ts/custom.vue 一致） */
const qrH5Path = ref('')

/** 页面小程序路径口径（对照参考系统 showqr）：首页/会员中心/自定义内页 */
function pagePathOf(row: DesignerPageRow): string {
  if (row.ishome === 1) return 'pages/index/index'
  if (row.ishome === 2) return 'pages/my/usercenter'
  return `pages/index/main?id=${row.id}`
}

function handleQr(row: DesignerPageRow) {
  qrName.value = row.title
  qrPath.value = pagePathOf(row)
  qrH5Path.value = `pages/designer/custom?pageid=${row.id}`
  qrVisible.value = true
}

// ============ 存为模板（任务250 保留入口，迁至卡片图标行末） ============
async function handleSaveAsTemplate(row: DesignerPageRow) {
  const { value } = await ElMessageBox.prompt(`将页面「${row.title}」存为模板`, '存为模板', {
    confirmButtonText: '保存',
    cancelButtonText: '取消',
    inputPlaceholder: '请输入模板名称',
    inputValue: row.title,
    inputValidator: (v: string) => (v && v.trim() ? true : '模板名称不能为空'),
  })
  await saveDesignerTemplate(row.id, (value || '').trim())
  ElMessage.success('已存为模板，可在「页面模板」查看')
}

onMounted(() => {
  loadPages()
})
</script>

<template>
  <el-card shadow="never">
    <template #header>
      <div class="dpl-header">
        <div class="dpl-header-left">
          <span class="dpl-title">{{ title }}</span>
          <span class="dpl-tip">保存即发布；点击标题或编辑图标将新开标签页进入设计器</span>
        </div>
      </div>
    </template>

    <!-- 工具栏：添加 + 标题搜索（对照参考系统） -->
    <div class="dpl-toolbar">
      <el-button type="primary" @click="handleCreate">添加</el-button>
      <div class="dpl-search">
        <span class="dpl-search-label">标题</span>
        <el-input
          v-model="keyword"
          placeholder="请输入页面标题"
          clearable
          style="width: 200px"
          @keyup.enter="handleSearch"
          @clear="handleSearch"
        />
        <el-button @click="handleSearch">搜索</el-button>
      </div>
    </div>

    <!-- 卡片网格 -->
    <div v-loading="pageLoading" class="dpl-grid">
      <div v-for="row in pageList" :key="row.id" class="dpl-card">
        <!-- 标题行：[首页]/[会员中心] 红色前缀 + 标题链接可点编辑 -->
        <h1 class="dpl-card-title" :title="row.title">
          <span v-if="row.ishome === 1" class="dpl-prefix">[首页]</span>
          <span v-else-if="row.ishome === 2" class="dpl-prefix">[会员中心]</span>
          <a class="dpl-title-link" @click="handleEdit(row)">{{ row.title }}</a>
        </h1>

        <!-- 创建时间 -->
        <div class="dpl-card-date">{{ row.create_time }}</div>

        <!-- 真实全貌预览区（默认高 400px 可滚，滚动条隐藏；IntersectionObserver 懒挂载，任务276/277） -->
        <div class="dpl-card-preview" :data-page-id="row.id" :ref="(el: unknown) => setPreviewEl(row.id, el)">
          <DesignerPreviewFrame
            v-if="previewOf(row.id)"
            :topbar="previewOf(row.id)?.topbar ?? null"
            :items="previewOf(row.id)?.items ?? []"
          />
          <div v-else-if="previewFailed.has(row.id)" class="dpl-thumb-placeholder">
            <span class="dpl-thumb-tip">预览加载失败</span>
          </div>
          <div v-else class="dpl-thumb-placeholder">
            <span class="dpl-thumb-tip">加载中…</span>
          </div>
        </div>

        <!-- 底部操作图标行（显隐规则对照参考系统） -->
        <div class="dpl-card-actions">
          <span class="dpl-action" title="二维码" @click="handleQr(row)">
            <svg viewBox="0 0 1024 1024" width="18" height="18"><path fill="currentColor" d="M469.333 128H128v341.333h341.333V128zm-64 277.333H192V192h213.333v213.333zM469.333 554.667H128V896h341.333V554.667zm-64 277.333H192V618.667h213.333v213.333zM896 128H554.667v341.333H896V128zm-64 277.333H618.667V192h213.333v213.333zM554.667 554.667h149.333v149.333H554.667zM746.667 746.667H896V896H746.667zM554.667 789.333h106.666V896H554.667zM789.333 554.667H896v106.666H789.333z"/></svg>
          </span>
          <span
            v-if="row.ishome === 0"
            class="dpl-action"
            title="设为首页"
            @click="handleSetHome(row)"
          >
            <svg viewBox="0 0 1024 1024" width="18" height="18"><path fill="currentColor" d="M512 128 128 448v448h277.333V640h213.334v256H896V448L512 128zm0 85.333 320 266.667V832h-149.333V576H341.333v256H192V480l320-266.667z"/></svg>
          </span>
          <span class="dpl-action" title="编辑" @click="handleEdit(row)">
            <svg viewBox="0 0 1024 1024" width="18" height="18"><path fill="currentColor" d="M764.587 190.72 256 699.307V832h132.693l508.587-508.587L764.587 190.72zm45.226-45.227 45.28 45.28 90.507-90.56-45.227-45.226-90.56 90.506zM192 672.64 673.92 190.72 832 348.8 350.08 830.72 192 832v-159.36z"/></svg>
          </span>
          <span class="dpl-action" title="复制" @click="handleCopy(row)">
            <svg viewBox="0 0 1024 1024" width="18" height="18"><path fill="currentColor" d="M704 192h-64V128H192v640h64v64H128V64h576v128zm192 128v640H320V320h576zm-64 64H384v512h448V384z"/></svg>
          </span>
          <span
            v-if="row.ishome === 0"
            class="dpl-action dpl-action-danger"
            title="删除"
            @click="handleDel(row)"
          >
            <svg viewBox="0 0 1024 1024" width="18" height="18"><path fill="currentColor" d="M384 128h256v64H384v-64zm320 64h192v64H704v704a64 64 0 0 1-64 64H384a64 64 0 0 1-64-64V256H128v-64h192v-64a64 64 0 0 1 64-64h256a64 64 0 0 1 64 64v64zM384 256v640h256V256H384zm64 128h64v384h-64V384zm128 0h64v384h-64V384z"/></svg>
          </span>
          <span class="dpl-action" title="存为模板" @click="handleSaveAsTemplate(row)">
            <svg viewBox="0 0 1024 1024" width="18" height="18"><path fill="currentColor" d="M512 64 128 192v640l384 128 384-128V192L512 64zm0 68.864 320 106.667V763.2l-320 106.667L192 763.2V239.53l320-106.666zM480 320h64v128h128v64H544v128h-64V512H352v-64h128V320z"/></svg>
          </span>
        </div>
      </div>

      <el-empty v-if="!pageLoading && pageList.length === 0" description="暂无页面，点击「添加」新建或到「页面模板」套用" />
    </div>

    <!-- 二维码小弹窗（复用 LinkAddressModal：H5二维码 + 链接地址 + 复制 + 预览） -->
    <LinkAddressModal v-model:visible="qrVisible" :name="qrName" :path="qrPath" :h5-path="qrH5Path" />
  </el-card>
</template>

<style scoped>
.dpl-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.dpl-header-left {
  display: flex;
  align-items: baseline;
  gap: 10px;
}

.dpl-title {
  font-size: 15px;
  font-weight: 600;
  color: #303133;
}

.dpl-tip {
  font-size: 12px;
  color: #909399;
}

.dpl-toolbar {
  display: flex;
  align-items: center;
  gap: 16px;
  margin-bottom: 16px;
}

.dpl-search {
  display: flex;
  align-items: center;
  gap: 8px;
}

.dpl-search-label {
  font-size: 13px;
  color: #606266;
}

/* 卡片网格（自适应多列，对照参考系统 250px 卡片浮动排布） */
.dpl-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
  gap: 18px;
  min-height: 120px;
}

.dpl-card {
  background: #fff;
  box-shadow: 0 0 2px #919dab;
  padding: 12px;
  display: flex;
  flex-direction: column;
}

.dpl-card-title {
  height: 20px;
  line-height: 20px;
  margin: 0 0 4px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-weight: normal;
  font-size: 14px;
  color: #282828;
}

.dpl-prefix {
  color: #fb6b5b;
}

.dpl-title-link {
  color: #282828;
  cursor: pointer;
}

.dpl-title-link:hover {
  color: var(--el-color-primary);
}

.dpl-card-date {
  height: 20px;
  line-height: 20px;
  margin-bottom: 6px;
  color: #282828;
  font-size: 12px;
}

/* 真实全貌预览区（任务276：DesignerPreviewFrame 默认高 400px+隐藏滚动条，任务277） */
.dpl-card-preview {
  min-height: 120px;
}

.dpl-thumb-placeholder {
  height: 400px;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 8px;
  color: #909399;
  border: 1px solid #eee;
  background: #fafafa;
}

.dpl-thumb-tip {
  font-size: 12px;
  color: #c0c4cc;
}

/* 底部操作图标行 */
.dpl-card-actions {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 4px;
  border-top: 1px solid #e6ebf1;
  padding: 10px 0 2px;
}

.dpl-action {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 28px;
  height: 28px;
  border-radius: 4px;
  color: #6c707f;
  cursor: pointer;
  transition: all 0.2s;
}

.dpl-action:hover {
  background: #f5f7fa;
  color: var(--el-color-primary);
}

.dpl-action-danger:hover {
  color: #f56c6c;
}
</style>
