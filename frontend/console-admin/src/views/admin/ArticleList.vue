<script setup lang="ts">
// 文章列表（对照点大 Article 控制器 / article 视图）
// - 平台「扩展→文章管理→文章列表」预置 bid='0'（仅平台文章，可添加）；
// - 平台「商户→文章列表」预置 bid='gt0'（仅商户文章，平台只管理、不提供添加，列表多“所属商户”列）；
//   二者是同一张 article 表按 bid 过滤的两个视图，记录各自独立，故 ID 不连续/不相同。
// - 编辑页两个选项卡：基本信息 / 内容编辑（内容为页面设计器 JSON，复用 GoodsDetailEditor）。
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  copyArticle,
  deleteArticle,
  getArticleCategoryList,
  getArticleDetail,
  getArticleList,
  saveArticle,
  setArticleSt,
  type ArticleCategoryItem,
  type ArticleItem,
} from '../../api/article'
import type { DesignerModule } from '../../api/designer'
import { defaultModule } from './designerModules'
import GoodsDetailEditor from '../../components/GoodsDetailEditor.vue'
import ImgIconPicker from '../../components/ImgIconPicker.vue'
import { formatTime } from '../../utils/time'

const props = withDefaults(defineProps<{ bid?: string }>(), { bid: '' })
/** 商户视图：平台在「商户」菜单下查看各商户文章（bid>0），不提供“添加” */
const isMerchantView = computed(() => props.bid === 'gt0')

// ============ 列表 ============
const loading = ref(false)
const list = ref<ArticleItem[]>([])
const total = ref(0)
const query = reactive({
  page: 1,
  pagesize: 10,
  keyword: '',
  status: '',
  cid: 0 as number,
})
const selected = ref<ArticleItem[]>([])

// ============ 分类 ============
const categories = ref<ArticleCategoryItem[]>([])
const categoryMap = computed(() => {
  const m: Record<number, string> = {}
  categories.value.forEach((c) => (m[c.id] = c.name))
  return m
})
const categoryTree = computed(() => {
  const tops = categories.value.filter((c) => c.pid === 0)
  return tops.map((t) => ({
    id: t.id,
    name: t.name,
    children: categories.value.filter((c) => c.pid === t.id),
  }))
})

async function loadCategories() {
  const res = await getArticleCategoryList()
  categories.value = res.list || []
}
async function load() {
  loading.value = true
  try {
    const res = await getArticleList({ ...query, bid: props.bid })
    list.value = res.list || []
    total.value = res.total || 0
  } finally {
    loading.value = false
  }
}
function handleSearch() {
  query.page = 1
  load()
}

// ============ 编辑（基本信息 / 内容编辑 两个选项卡） ============
const dialogVisible = ref(false)
const saving = ref(false)
const activeTab = ref<'base' | 'content'>('base')

const form = reactive({
  id: 0,
  cid: 0,
  name: '',
  subname: '',
  pic: '',
  author: '',
  content: '',
  readcount: 0,
  sort: 0,
  createtime: 0,
  status: 1,
  showname: 1,
  showsubname: 1,
  showreadcount: 1,
  showsendtime: 1,
  showauthor: 1,
  canpl: 1,
  canplrp: 1,
  pinglun_check: 0,
  showtj: '-1',
})

/** 内容设计器模块数组（article.content 存其 JSON） */
const contentModules = ref<DesignerModule[]>([])

/** 发布时间（表单用毫秒，库内为秒） */
const createTimeMs = computed<number>({
  get: () => (form.createtime ? form.createtime * 1000 : Date.now()),
  set: (v) => {
    form.createtime = v ? Math.floor(new Date(v).getTime() / 1000) : 0
  },
})

/** 详情页显示：四个开关 ↔ 复选按钮组 */
const detailShow = computed<string[]>({
  get: () => {
    const arr: string[] = []
    if (form.showname) arr.push('showname')
    if (form.showreadcount) arr.push('showreadcount')
    if (form.showsendtime) arr.push('showsendtime')
    if (form.showauthor) arr.push('showauthor')
    return arr
  },
  set: (vals) => {
    form.showname = vals.includes('showname') ? 1 : 0
    form.showreadcount = vals.includes('showreadcount') ? 1 : 0
    form.showsendtime = vals.includes('showsendtime') ? 1 : 0
    form.showauthor = vals.includes('showauthor') ? 1 : 0
  },
})

function defaultContentModules(): DesignerModule[] {
  return [defaultModule('richtext')]
}
function parseContent(content: string): DesignerModule[] {
  try {
    const arr = JSON.parse(content)
    if (Array.isArray(arr) && arr.length) return arr as DesignerModule[]
  } catch {
    // 非法/空内容则回落为默认富文本
  }
  return defaultContentModules()
}
function resetForm() {
  Object.assign(form, {
    id: 0,
    cid: 0,
    name: '',
    subname: '',
    pic: '',
    author: '',
    content: '',
    readcount: 0,
    sort: 0,
    createtime: Math.floor(Date.now() / 1000),
    status: 1,
    showname: 1,
    showsubname: 1,
    showreadcount: 1,
    showsendtime: 1,
    showauthor: 1,
    canpl: 1,
    canplrp: 1,
    pinglun_check: 0,
    showtj: '-1',
  })
  contentModules.value = defaultContentModules()
  activeTab.value = 'base'
}
function openAdd() {
  resetForm()
  dialogVisible.value = true
}
async function openEdit(row: ArticleItem) {
  resetForm()
  const detail = await getArticleDetail(row.id)
  Object.assign(form, {
    ...detail,
    showtj: String(detail.showtj ?? '-1'),
    cid: Number(detail.cid) || 0,
    readcount: Number(detail.readcount) || 0,
    sort: Number(detail.sort) || 0,
  })
  contentModules.value = parseContent(detail.content || '')
  dialogVisible.value = true
}

async function handleSave() {
  if (!form.name.trim()) {
    ElMessage.warning('请填写文章标题')
    activeTab.value = 'base'
    return
  }
  saving.value = true
  try {
    await saveArticle({
      id: form.id || undefined,
      cid: Number(form.cid) || 0,
      name: form.name,
      subname: form.subname,
      pic: form.pic,
      author: form.author,
      content: JSON.stringify(contentModules.value || []),
      readcount: Number(form.readcount) || 0,
      sort: Number(form.sort) || 0,
      createtime: form.createtime || Math.floor(Date.now() / 1000),
      status: form.status,
      showname: form.showname,
      showsubname: form.showsubname,
      showreadcount: form.showreadcount,
      showsendtime: form.showsendtime,
      showauthor: form.showauthor,
      canpl: form.canpl,
      canplrp: form.canplrp,
      pinglun_check: form.pinglun_check,
      showtj: form.showtj,
    })
    ElMessage.success(form.id ? '保存成功' : '新增成功')
    dialogVisible.value = false
    load()
  } finally {
    saving.value = false
  }
}

// ============ 选图（ImgIconPicker） ============
const imgPickerVisible = ref(false)
let imgPickerSetter: ((url: string) => void) | null = null
function openImgPicker(setter: (url: string) => void) {
  imgPickerSetter = setter
  imgPickerVisible.value = true
}
function onImgPick(urls: string[]) {
  if (urls[0]) imgPickerSetter?.(urls[0])
  imgPickerSetter = null
}

// ============ 行操作 ============
async function handleSetst(row: ArticleItem) {
  const next = row.status === 1 ? 0 : 1
  await setArticleSt([row.id], next)
  ElMessage.success(next === 1 ? '已开启' : '已关闭')
  load()
}
async function handleCopy(row: ArticleItem) {
  await ElMessageBox.confirm('确定要复制该文章吗？', '操作确认', { type: 'warning' })
  await copyArticle(row.id)
  ElMessage.success('复制成功')
  load()
}
async function handleDelete(rows: ArticleItem[]) {
  if (!rows.length) {
    ElMessage.warning('请选择数据')
    return
  }
  await ElMessageBox.confirm(`确定删除选中的 ${rows.length} 篇文章？删除后无法恢复！`, '操作确认', { type: 'warning' })
  await deleteArticle(rows.map((r) => r.id))
  ElMessage.success('删除成功')
  selected.value = []
  load()
}
async function handleBatchSetst(st: number) {
  if (!selected.value.length) {
    ElMessage.warning('请选择数据')
    return
  }
  await ElMessageBox.confirm(`确定要${st === 1 ? '开启' : '关闭'}选中的文章吗？`, '操作确认', { type: 'warning' })
  await setArticleSt(
    selected.value.map((r) => r.id),
    st,
  )
  ElMessage.success(st === 1 ? '已开启' : '已关闭')
  load()
}

// ============ 展示辅助 ============
function fmtDate(ts: number | string): string {
  const n = Number(ts)
  if (!n) return ''
  return formatTime(n).slice(0, 10)
}

watch(
  () => props.bid,
  () => handleSearch(),
)

onMounted(() => {
  loadCategories()
  load()
})
</script>

<template>
  <div class="article-list">
    <!-- 工具栏：左操作 / 右搜索，强制一行 -->
    <div class="toolbar">
      <div class="tb-left">
        <el-button v-if="!isMerchantView" type="primary" @click="openAdd">添加</el-button>
        <el-button :disabled="!selected.length" @click="handleDelete(selected)">删除</el-button>
        <el-button :disabled="!selected.length" @click="handleBatchSetst(1)">开启</el-button>
        <el-button :disabled="!selected.length" @click="handleBatchSetst(0)">关闭</el-button>
      </div>
      <div class="tb-right">
        <span class="tb-label">标题</span>
        <el-input
          v-model="query.keyword"
          placeholder="标题"
          clearable
          class="tb-kw"
          @keyup.enter="handleSearch"
        />
        <span class="tb-label">分类</span>
        <el-select v-model="query.cid" placeholder="全部" clearable class="tb-cid">
          <el-option v-for="top in categoryTree" :key="top.id" :label="top.name" :value="top.id" />
          <template v-for="top in categoryTree" :key="`c${top.id}`">
            <el-option v-for="c in top.children" :key="c.id" :label="`　${top.name} / ${c.name}`" :value="c.id" />
          </template>
        </el-select>
        <span class="tb-label">状态</span>
        <el-select v-model="query.status" placeholder="全部" clearable class="tb-st">
          <el-option label="开启" value="1" />
          <el-option label="关闭" value="0" />
        </el-select>
        <el-button type="primary" @click="handleSearch">搜索</el-button>
      </div>
    </div>

    <el-table
      v-loading="loading"
      :data="list"
      border
      stripe
      @selection-change="(rows: ArticleItem[]) => (selected = rows)"
    >
      <el-table-column type="selection" width="44" />
      <el-table-column prop="id" label="ID" width="80" sortable />
      <el-table-column v-if="isMerchantView" prop="bname" label="所属商户" width="140" show-overflow-tooltip>
        <template #default="{ row }">{{ row.bname || '-' }}</template>
      </el-table-column>
      <el-table-column label="文章信息" min-width="340">
        <template #default="{ row }">
          <div class="art-cell">
            <el-image v-if="row.pic" :src="row.pic" class="art-pic" fit="cover" />
            <div v-else class="art-pic art-pic-empty">-</div>
            <div class="art-meta">
              <div class="art-title">
                <span v-if="categoryMap[row.cid]" class="art-cname">[{{ categoryMap[row.cid] }}] </span>
                <span class="art-name">{{ row.name }}</span>
              </div>
              <div v-if="row.subname" class="art-sub">{{ row.subname }}</div>
              <div class="art-date">{{ fmtDate(row.createtime) }}</div>
            </div>
          </div>
        </template>
      </el-table-column>
      <el-table-column prop="readcount" label="阅读量" width="90" sortable />
      <el-table-column prop="sort" label="序号" width="80" sortable />
      <el-table-column label="创建时间" width="150">
        <template #default="{ row }">{{ row.createtime ? formatTime(Number(row.createtime)) : '-' }}</template>
      </el-table-column>
      <el-table-column label="状态" width="95">
        <template #default="{ row }">
          <el-button v-if="row.status === 1" size="small" type="primary" @click="handleSetst(row)">已开启</el-button>
          <el-button v-else-if="row.status === 0" size="small" type="danger" @click="handleSetst(row)">已关闭</el-button>
          <el-tag v-else type="danger">已驳回</el-tag>
        </template>
      </el-table-column>
      <el-table-column label="操作" width="170" fixed="right">
        <template #default="{ row }">
          <el-button link type="primary" @click="handleCopy(row)">复制</el-button>
          <el-button link type="primary" @click="openEdit(row)">编辑</el-button>
          <el-button link type="danger" @click="handleDelete([row])">删除</el-button>
        </template>
      </el-table-column>
      <template #empty>暂无数据</template>
    </el-table>

    <el-pagination
      v-model:current-page="query.page"
      v-model:page-size="query.pagesize"
      :total="total"
      :page-sizes="[10, 20, 50]"
      layout="total, sizes, prev, pager, next"
      style="margin-top: 12px; justify-content: flex-end"
      @change="load"
    />

    <!-- 编辑（全屏，两个选项卡） -->
    <el-dialog
      v-model="dialogVisible"
      :title="form.id ? '编辑文章' : '添加文章'"
      fullscreen
      :close-on-click-modal="false"
      class="art-edit-dialog"
    >
      <el-tabs v-model="activeTab" class="art-tabs">
        <!-- ===== 基本信息 ===== -->
        <el-tab-pane label="基本信息" name="base">
          <el-form label-width="92px" class="art-base-form">
            <el-form-item label="标题" required>
              <el-input v-model="form.name" maxlength="100" show-word-limit style="width: 500px" placeholder="请输入文章标题" />
            </el-form-item>
            <el-form-item label="描述">
              <el-input v-model="form.subname" maxlength="200" style="width: 500px" />
              <span class="tip">用于转发分享描述</span>
            </el-form-item>
            <el-form-item label="作者">
              <el-input v-model="form.author" maxlength="50" style="width: 240px" />
            </el-form-item>
            <el-form-item label="图片">
              <div class="pic-row">
                <el-button @click="openImgPicker((u) => (form.pic = u))">上传图片</el-button>
                <span class="tip">建议尺寸：600×430像素</span>
              </div>
              <div class="pic-preview">
                <el-image v-if="form.pic" :src="form.pic" class="pic-preview-img" fit="cover" />
                <div v-else class="pic-preview-empty">暂无图片</div>
              </div>
            </el-form-item>
            <el-form-item label="分类">
              <el-select v-model="form.cid" placeholder="--请选择--" clearable style="width: 240px">
                <el-option v-for="top in categoryTree" :key="top.id" :label="top.name" :value="top.id" />
                <template v-for="top in categoryTree" :key="`f${top.id}`">
                  <el-option v-for="c in top.children" :key="c.id" :label="`　${top.name} / ${c.name}`" :value="c.id" />
                </template>
              </el-select>
            </el-form-item>
            <el-form-item label="阅读量">
              <el-input-number v-model="form.readcount" :min="0" :controls="false" style="width: 200px" />
            </el-form-item>
            <el-form-item label="序号">
              <el-input-number v-model="form.sort" :min="0" :controls="false" style="width: 200px" />
              <span class="tip">用于排序，越大越靠前</span>
            </el-form-item>
            <el-form-item label="发布时间">
              <el-date-picker
                v-model="createTimeMs"
                type="datetime"
                placeholder="选择发布时间"
                format="YYYY-MM-DD HH:mm:ss"
                style="width: 240px"
              />
            </el-form-item>
            <el-form-item label="详情页显示">
              <el-checkbox-group v-model="detailShow" class="art-show-group">
                <el-checkbox-button value="showname">标题</el-checkbox-button>
                <el-checkbox-button value="showreadcount">阅读量</el-checkbox-button>
                <el-checkbox-button value="showsendtime">发布时间</el-checkbox-button>
                <el-checkbox-button value="showauthor">作者</el-checkbox-button>
              </el-checkbox-group>
            </el-form-item>
            <el-form-item label="文章评论">
              <el-switch v-model="form.canpl" :active-value="1" :inactive-value="0" active-text="开启" inactive-text="关闭" />
            </el-form-item>
            <el-form-item v-if="form.canpl === 1" label="评论回复">
              <el-switch v-model="form.canplrp" :active-value="1" :inactive-value="0" active-text="开启" inactive-text="关闭" />
            </el-form-item>
            <el-form-item v-if="form.canpl === 1" label="评论审核">
              <el-switch v-model="form.pinglun_check" :active-value="1" :inactive-value="0" active-text="开启" inactive-text="关闭" />
            </el-form-item>
            <el-form-item label="状态">
              <el-radio-group v-model="form.status">
                <el-radio :value="1">开启</el-radio>
                <el-radio :value="0">关闭</el-radio>
              </el-radio-group>
            </el-form-item>
            <el-form-item>
              <el-button type="primary" @click="activeTab = 'content'">下一步:编辑文章详情</el-button>
              <el-button type="danger" :loading="saving" @click="handleSave">提 交</el-button>
            </el-form-item>
          </el-form>
        </el-tab-pane>

        <!-- ===== 内容编辑（页面设计器，与商品详情装修同源） ===== -->
        <el-tab-pane label="内容编辑" name="content" lazy>
          <GoodsDetailEditor v-if="activeTab === 'content'" v-model="contentModules" title="文章详情" />
          <div class="content-footer">
            <el-button type="primary" @click="activeTab = 'base'">上一步:编辑基本信息</el-button>
            <el-button type="danger" :loading="saving" @click="handleSave">提 交</el-button>
          </div>
        </el-tab-pane>
      </el-tabs>
    </el-dialog>

    <ImgIconPicker v-model:visible="imgPickerVisible" default-tab="library" @pick="onImgPick" />
  </div>
</template>

<style scoped>
.article-list {
  padding: 12px;
}
/* 工具栏强制一行：左侧操作，右侧搜索 */
.toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  flex-wrap: nowrap;
  margin-bottom: 12px;
}
.tb-left {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-shrink: 0;
}
.tb-right {
  display: flex;
  align-items: center;
  gap: 6px;
  margin-left: auto;
  flex-shrink: 0;
}
.tb-label {
  color: #606266;
  font-size: 13px;
  white-space: nowrap;
}
.tb-kw {
  width: 170px;
}
.tb-cid {
  width: 150px;
}
.tb-st {
  width: 104px;
}
/* 文章信息单元格 */
.art-cell {
  display: flex;
  align-items: flex-start;
  gap: 10px;
}
.art-pic {
  width: 60px;
  height: 43px;
  border-radius: 4px;
  flex-shrink: 0;
  background: #f5f7fa;
}
.art-pic-empty {
  display: flex;
  align-items: center;
  justify-content: center;
  color: #c0c4cc;
  font-size: 12px;
}
.art-meta {
  flex: 1;
  min-width: 0;
  line-height: 20px;
}
.art-title {
  font-size: 13px;
  color: #303133;
  font-weight: 600;
  word-break: break-all;
}
.art-cname {
  color: #f56c6c;
  font-weight: 400;
}
.art-name {
  font-weight: 600;
}
.art-sub {
  font-size: 12px;
  color: #999;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.art-date {
  font-size: 12px;
  color: #999;
}
.tip {
  margin-left: 10px;
  color: #909399;
  font-size: 12px;
}
/* 编辑弹窗 */
.art-base-form {
  max-width: 780px;
}
.pic-row {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 8px;
}
.pic-preview {
  width: 100px;
  height: 71px;
}
.pic-preview-img {
  width: 100px;
  height: 71px;
  border: 1px solid #ebeef5;
  border-radius: 4px;
}
.pic-preview-empty {
  width: 100px;
  height: 71px;
  border: 1px dashed #dcdfe6;
  border-radius: 4px;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #c0c4cc;
  font-size: 12px;
  background: #fafafa;
}
.art-show-group {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}
.content-footer {
  margin-top: 16px;
  padding-left: 92px;
}
.art-edit-dialog :deep(.el-dialog__body) {
  height: calc(100vh - 55px);
  overflow-y: auto;
  padding-top: 10px;
}
</style>
