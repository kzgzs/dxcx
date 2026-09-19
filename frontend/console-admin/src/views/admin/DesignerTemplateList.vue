<script setup lang="ts">
/**
 * 页面模板（任务276：卡片化重做，真实全貌可滚预览）
 * - 顶部对照参考图：左「模板列表」标题 + 标题搜索框 + 右侧「同步模板」按钮
 * - 卡片：标题行（平台/自建标记+名称）/ 更新时间 / 真实全貌预览（模板JSON同渲染口径，直接取行内 pageinfo/content）
 * - 底部图标仅两个：二维码 + 应用该模板
 * - 同步模板：清空平台内置模板（aid=0）重插内置两套，保留租户自建模板
 */
import { computed, onMounted, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  applyDesignerTemplate,
  getDesignerTemplateList,
  syncDesignerTemplates,
  type DesignerModule,
  type DesignerTemplateRow,
} from '../../api/designer'
import LinkAddressModal from '../../components/LinkAddressModal.vue'
import DesignerPreviewFrame from '../../components/DesignerPreviewFrame.vue'

// ============ 模板列表 ============
const tplList = ref<DesignerTemplateRow[]>([])
const tplLoading = ref(false)
const keyword = ref('')

async function loadTemplates() {
  tplLoading.value = true
  try {
    const res = await getDesignerTemplateList()
    tplList.value = res.list || []
  } finally {
    tplLoading.value = false
  }
}

/** 标题搜索（列表已全量下发，前端过滤 name） */
const filteredList = computed(() => {
  const kw = keyword.value.trim()
  if (!kw) return tplList.value
  return tplList.value.filter((r) => (r.name || '').includes(kw))
})

// ============ 预览数据（模板行自带 pageinfo/content，无需懒加载） ============
function topbarOf(row: DesignerTemplateRow): DesignerModule | null {
  const pageinfo = row.pageinfo || []
  return pageinfo.length > 0 && pageinfo[0].temp === 'topbar' ? pageinfo[0] : null
}

function itemsOf(row: DesignerTemplateRow): DesignerModule[] {
  return row.content || []
}

// ============ 二维码（复用 LinkAddressModal 小弹窗） ============
const qrVisible = ref(false)
const qrName = ref('')
const qrPath = ref('')

function handleQr(row: DesignerTemplateRow) {
  qrName.value = row.name
  // 模板卡二维码口径（任务278）：pages/index/main?tid={模板id}，C端按 tid 加载模板内容渲染
  qrPath.value = `pages/index/main?tid=${row.id}`
  qrVisible.value = true
}

// ============ 应用该模板：生成新页面后新开标签进入编辑器 ============
function editorUrl(id: number): string {
  return `${location.origin}${location.pathname}#/designer/editor?id=${id}`
}

async function handleApply(row: DesignerTemplateRow) {
  await ElMessageBox.confirm(`应用模板「${row.name}」将生成一个新的页面，是否继续？`, '应用该模板', {
    confirmButtonText: '应用',
    cancelButtonText: '取消',
  })
  const res = await applyDesignerTemplate(row.id, 0)
  ElMessage.success('模板已应用，进入编辑')
  window.open(editorUrl(res.id), '_blank')
}

// ============ 同步模板（清空平台内置重插内置集，保留自建） ============
const syncing = ref(false)

async function handleSync() {
  await ElMessageBox.confirm('同步模板将重置平台内置模板（自建模板不受影响），是否继续？', '同步模板', {
    confirmButtonText: '同步',
    cancelButtonText: '取消',
  })
  syncing.value = true
  try {
    await syncDesignerTemplates()
    ElMessage.success('模板同步成功')
    loadTemplates()
  } finally {
    syncing.value = false
  }
}

onMounted(() => {
  loadTemplates()
})
</script>

<template>
  <el-card shadow="never">
    <!-- 顶部：模板列表标题 + 标题搜索 + 同步模板（对照参考图） -->
    <div class="dtl-toolbar">
      <span class="dtl-title">模板列表</span>
      <div class="dtl-search">
        <span class="dtl-search-label">标题</span>
        <el-input
          v-model="keyword"
          placeholder="请输入模板名称"
          clearable
          style="width: 200px"
        />
      </div>
      <div class="dtl-toolbar-right">
        <el-button type="primary" :loading="syncing" @click="handleSync">同步模板</el-button>
      </div>
    </div>

    <!-- 卡片网格（真实全貌可滚预览，任务276） -->
    <div v-loading="tplLoading" class="dtl-grid">
      <div v-for="row in filteredList" :key="row.id" class="dtl-card">
        <!-- 标题行：平台/自建标记 + 模板名称 -->
        <h1 class="dtl-card-title" :title="row.name">
          <span v-if="Number(row.aid) === 0" class="dtl-tag dtl-tag-platform">平台</span>
          <span v-else class="dtl-tag dtl-tag-mine">自建</span>
          <span class="dtl-name">{{ row.name }}</span>
        </h1>

        <!-- 更新时间 -->
        <div class="dtl-card-date">{{ row.update_time }}</div>

        <!-- 真实全貌预览区（DesignerPreviewFrame 默认高 400px 可滚，滚动条隐藏，任务277） -->
        <DesignerPreviewFrame :topbar="topbarOf(row)" :items="itemsOf(row)" />

        <!-- 底部图标行：仅 二维码 + 应用该模板 -->
        <div class="dtl-card-actions">
          <span class="dtl-action" title="二维码" @click="handleQr(row)">
            <svg viewBox="0 0 1024 1024" width="18" height="18"><path fill="currentColor" d="M469.333 128H128v341.333h341.333V128zm-64 277.333H192V192h213.333v213.333zM469.333 554.667H128V896h341.333V554.667zm-64 277.333H192V618.667h213.333v213.333zM896 128H554.667v341.333H896V128zm-64 277.333H618.667V192h213.333v213.333zM554.667 554.667h149.333v149.333H554.667zM746.667 746.667H896V896H746.667zM554.667 789.333h106.666V896H554.667zM789.333 554.667H896v106.666H789.333z"/></svg>
          </span>
          <span class="dtl-action" title="应用该模板" @click="handleApply(row)">
            <svg viewBox="0 0 1024 1024" width="18" height="18"><path fill="currentColor" d="M512 64 128 192v640l384 128 384-128V192L512 64zm0 68.864 320 106.667V763.2l-320 106.667L192 763.2V239.53l320-106.666zM480 320h64v128h128v64H544v128h-64V512H352v-64h128V320z"/></svg>
          </span>
        </div>
      </div>

      <el-empty v-if="!tplLoading && filteredList.length === 0" description="暂无模板，可在「页面设计」中将页面存为模板" />
    </div>

    <!-- 二维码小弹窗（复用 LinkAddressModal） -->
    <LinkAddressModal v-model:visible="qrVisible" :name="qrName" :path="qrPath" />
  </el-card>
</template>

<style scoped>
/* 顶部工具栏（对照参考图：标题 + 搜索 + 右侧同步按钮） */
.dtl-toolbar {
  display: flex;
  align-items: center;
  gap: 16px;
  margin-bottom: 16px;
}

.dtl-title {
  font-size: 15px;
  font-weight: 600;
  color: #303133;
}

.dtl-search {
  display: flex;
  align-items: center;
  gap: 8px;
}

.dtl-search-label {
  font-size: 13px;
  color: #606266;
}

.dtl-toolbar-right {
  margin-left: auto;
}

/* 卡片网格（自适应多列，与页面设计列表同口径） */
.dtl-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
  gap: 18px;
  min-height: 120px;
}

.dtl-card {
  background: #fff;
  box-shadow: 0 0 2px #919dab;
  padding: 12px;
  display: flex;
  flex-direction: column;
}

.dtl-card-title {
  height: 20px;
  line-height: 20px;
  margin: 0 0 4px;
  overflow: hidden;
  white-space: nowrap;
  font-weight: normal;
  font-size: 14px;
  color: #282828;
  display: flex;
  align-items: center;
  gap: 6px;
}

.dtl-tag {
  flex-shrink: 0;
  font-size: 11px;
  line-height: 16px;
  padding: 0 5px;
  border-radius: 3px;
}

.dtl-tag-platform {
  color: #7b7de5;
  background: #f0f0fd;
}

.dtl-tag-mine {
  color: #67c23a;
  background: #f0f9eb;
}

.dtl-name {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.dtl-card-date {
  height: 20px;
  line-height: 20px;
  margin-bottom: 6px;
  color: #282828;
  font-size: 12px;
}

/* 底部图标行 */
.dtl-card-actions {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 4px;
  border-top: 1px solid #e6ebf1;
  padding: 10px 0 2px;
}

.dtl-action {
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

.dtl-action:hover {
  background: #f5f7fa;
  color: var(--el-color-primary);
}
</style>
