<script setup lang="ts">
// 文章列表（对照点大 Article 控制器 / article 视图）
// - 平台「扩展→文章管理→文章列表」预置 bid='0'（仅平台文章，可添加）；
// - 平台「商户→文章列表」预置 bid='gt0'（仅商户文章，平台只管理、不提供添加，列表多“所属商户”列）；
//   二者是同一张 article 表按 bid 过滤的两个视图，记录各自独立，故 ID 不连续/不相同。
// - 点大商户文章由商户登录商户后台（Backstage）自建（bid=商户ID，分类也随商户）；
//   本项目暂无商户端入口，演示数据由 php think demo:articles 生成。
// - 编辑/添加平铺在主区域，右上角 X 关闭；两个选项卡：基本信息 / 内容编辑（内容为页面设计器 JSON，复用 GoodsDetailEditor）。
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

// ============ 视图切换：列表 / 整页编辑 ============
const viewMode = ref<'list' | 'edit'>('list')

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
  // 点大口径：文章分类随归属（bid=0 平台分类，bid>0 商户自建分类）
  const all = res.list || []
  categories.value = isMerchantView.value
    ? all.filter((c) => Number(c.bid) > 0)
    : all.filter((c) => Number(c.bid) === 0)
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
  viewMode.value = 'edit'
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
  viewMode.value = 'edit'
}
function backToList() {
  viewMode.value = 'list'
  resetForm()
  load()
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
    backToList()
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
  await ElMessageBox.confirm(`确定删除选中的 ${rows.length} 篇文章？删除后无法恢复！`, '删除确认', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })
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
  () => {
    query.cid = 0
    loadCategories()
    handleSearch()
  },
)

onMounted(() => {
  loadCategories()
  load()
})
</script>

<template>
  <div class="article-page">
    <!-- ============ 列表视图 ============ -->
    <div v-if="viewMode === 'list'">
      <el-card shadow="never" class="toolbar">
        <div class="toolbar-left">
          <el-button v-if="!isMerchantView" type="success" @click="openAdd">添加</el-button>
          <el-button @click="handleDelete(selected)">删除</el-button>
          <el-button @click="handleBatchSetst(1)">开启</el-button>
          <el-button @click="handleBatchSetst(0)">关闭</el-button>
        </div>
        <el-form inline class="toolbar-search" @submit.prevent>
          <el-form-item label="标题">
            <el-input
              v-model="query.keyword"
              placeholder="标题"
              clearable
              style="width: 120px"
              @keyup.enter="handleSearch"
            />
          </el-form-item>
          <el-form-item label="分类">
            <el-select v-model="query.cid" placeholder="全部" clearable style="width: 110px">
              <el-option label="全部" :value="0" />
              <el-option v-for="top in categoryTree" :key="top.id" :label="top.name" :value="top.id" />
              <template v-for="top in categoryTree" :key="`c${top.id}`">
                <el-option v-for="c in top.children" :key="c.id" :label="`　${top.name} / ${c.name}`" :value="c.id" />
              </template>
            </el-select>
          </el-form-item>
          <el-form-item label="状态">
            <el-select v-model="query.status" placeholder="全部" clearable style="width: 90px">
              <el-option label="开启" value="1" />
              <el-option label="关闭" value="0" />
            </el-select>
          </el-form-item>
          <el-form-item>
            <el-button type="success" class="search-icon-btn" @click="handleSearch">
              <svg viewBox="0 0 1024 1024" width="16" height="16" fill="currentColor"><path d="M945.82578 870.125405l-179.064759-179.128198c37.633442-50.252111 60.226958-112.396119 60.226958-179.635713 0-165.019429-133.863717-298.820032-298.94691-298.820032S229.157273 346.342065 229.157273 511.361494s133.800278 298.883471 298.883471 298.883471c64.175523 0 123.67499-20.451714 172.925368-54.893622l180.206658 180.270097c8.639241 8.575802 19.883819 12.926817 31.191832 12.926817s22.552591-4.351015 31.128393-12.926817C962.978033 917.976954 962.978033 887.274231 945.82578 870.125405zM528.040744 710.308079c-109.760556 0-198.946585-89.122915-198.946585-198.946585s89.186354-198.946585 198.946585-198.946585 198.946585 89.122915 198.946585 198.946585S637.8013 710.308079 528.040744 710.308079z" /></svg>
            </el-button>
          </el-form-item>
        </el-form>
      </el-card>

      <el-card shadow="never">
        <el-table
          v-loading="loading"
          :data="list"
          border
          @selection-change="(rows: ArticleItem[]) => (selected = rows)"
        >
          <el-table-column type="selection" width="45" />
          <el-table-column prop="id" label="ID" width="70" sortable="custom" align="center" header-align="center" />
          <el-table-column v-if="isMerchantView" prop="bname" label="所属商户" width="120" show-overflow-tooltip>
            <template #default="{ row }">{{ row.bname || '-' }}</template>
          </el-table-column>
          <el-table-column label="文章信息" min-width="300">
            <template #default="{ row }">
              <div class="art-cell">
                <el-image v-if="row.pic" :src="row.pic" class="art-pic" fit="cover" />
                <div v-else class="art-pic art-pic-empty">无图</div>
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
          <el-table-column prop="readcount" label="阅读量" width="90" sortable align="center" header-align="center" />
          <el-table-column prop="sort" label="序号" width="80" sortable align="center" header-align="center" />
          <el-table-column label="创建时间" width="160" align="center" header-align="center">
            <template #default="{ row }">{{ row.createtime ? formatTime(Number(row.createtime)) : '-' }}</template>
          </el-table-column>
          <el-table-column label="状态" width="90" align="center" header-align="center">
            <template #default="{ row }">
              <el-tag
                v-if="row.status === 1"
                effect="dark"
                type="primary"
                class="st-tag"
                @click="handleSetst(row)"
              >已开启</el-tag>
              <el-tag
                v-else-if="row.status === 0"
                effect="dark"
                type="info"
                class="st-tag"
                @click="handleSetst(row)"
              >已关闭</el-tag>
              <el-tag v-else effect="dark" type="danger">已驳回</el-tag>
            </template>
          </el-table-column>
          <el-table-column label="操作" width="90" fixed="right" align="center" header-align="center">
            <template #default="{ row }">
              <div class="row-ops">
                <el-button link type="primary" @click="handleCopy(row)">复制</el-button>
                <el-button link type="primary" @click="openEdit(row)">编辑</el-button>
                <el-button link type="danger" @click="handleDelete([row])">删除</el-button>
              </div>
            </template>
          </el-table-column>
          <template #empty>暂无数据</template>
        </el-table>

        <el-pagination
          v-model:current-page="query.page"
          v-model:page-size="query.pagesize"
          :total="total"
          :page-sizes="[10, 20, 50]"
          layout="total, sizes, prev, pager, next, jumper"
          class="pagination"
          @change="load"
        />
      </el-card>
    </div>

    <!-- ============ 整页编辑 ============ -->
    <div v-else class="goods-edit">
      <div class="ge-head">
        <el-button class="ge-close" circle @click="backToList">
          <svg viewBox="0 0 1024 1024" width="16" height="16" fill="currentColor"><path d="M764.288 214.595 512 466.883 259.712 214.595a32 32 0 0 0-45.248 45.248L466.752 512 214.464 764.288a32 32 0 1 0 45.248 45.248L512 557.117l252.288 252.419a32 32 0 0 0 45.248-45.248L557.248 512l252.288-252.157a32 32 0 0 0-45.248-45.248z" /></svg>
        </el-button>
      </div>

      <el-tabs v-model="activeTab" class="ge-tabs">
        <!-- ===== 基本信息 ===== -->
        <el-tab-pane label="基本信息" name="base">
          <el-form label-width="92px" class="ge-form">
            <el-form-item label="标题" required>
              <el-input v-model="form.name" maxlength="100" show-word-limit style="width: 500px" placeholder="请输入文章标题" />
            </el-form-item>
            <el-form-item label="描述">
              <el-input v-model="form.subname" maxlength="200" style="width: 500px" />
              <span class="ge-hint">用于转发分享描述</span>
            </el-form-item>
            <el-form-item label="作者">
              <el-input v-model="form.author" maxlength="50" style="width: 240px" />
            </el-form-item>
            <el-form-item label="图片">
              <div class="ge-pic-row">
                <el-button @click="openImgPicker((u) => (form.pic = u))">上传图片</el-button>
                <span class="ge-hint">建议尺寸：600×430像素</span>
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
              <span class="ge-hint">用于排序，越大越靠前</span>
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
              <el-button type="warning" :loading="saving" @click="handleSave">提 交</el-button>
            </el-form-item>
          </el-form>
        </el-tab-pane>

        <!-- ===== 内容编辑（页面设计器，与商品详情装修同源） ===== -->
        <el-tab-pane label="内容编辑" name="content" lazy>
          <GoodsDetailEditor v-if="activeTab === 'content'" v-model="contentModules" title="文章详情" />
          <div class="tab-footer">
            <el-button type="primary" @click="activeTab = 'base'">上一步:编辑基本信息</el-button>
            <el-button type="warning" :loading="saving" @click="handleSave">提 交</el-button>
          </div>
        </el-tab-pane>
      </el-tabs>
    </div>

    <ImgIconPicker v-model:visible="imgPickerVisible" default-tab="library" @pick="onImgPick" />
  </div>
</template>

<style scoped>
.article-page {
  padding: 12px;
}
/* 工具栏：左操作 / 右搜索，强制一行 */
.toolbar {
  margin-bottom: 12px;
}
.toolbar :deep(.el-card__body) {
  padding: 10px 14px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  flex-wrap: nowrap;
}
.toolbar-left {
  display: flex;
  align-items: center;
  gap: 8px;
  flex: 0 0 auto;
}
.toolbar-left .el-button {
  margin: 0;
}
.toolbar-search {
  display: flex;
  align-items: center;
  flex: 1 1 auto;
  justify-content: flex-end;
  flex-wrap: nowrap;
  min-width: 0;
  margin: 0;
}
.toolbar-search :deep(.el-form-item) {
  margin-bottom: 0;
  margin-right: 8px;
}
.toolbar-search :deep(.el-form-item:last-child) {
  margin-right: 0;
}
.toolbar-search :deep(.el-form-item__label) {
  padding-right: 6px;
}
.search-icon-btn {
  padding: 8px 14px;
}
.search-icon-btn svg {
  display: block;
}
.pagination {
  margin-top: 12px;
  justify-content: flex-end;
}
/* 行操作纵向排列（对标点大） */
.row-ops {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 2px;
  line-height: 1.5;
}
.row-ops .el-button {
  margin: 0;
  height: auto;
  min-height: 0;
  padding: 0;
  font-size: 12px;
}
.st-tag {
  cursor: pointer;
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
/* 整页编辑（与商品页 ge- 范式一致） */
.goods-edit {
  background: #fff;
  border-radius: 6px;
  padding: 16px 20px;
}
.ge-head {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  margin-bottom: 12px;
}
.ge-close {
  border: none;
  background: transparent;
  color: #909399;
}
.ge-close:hover {
  color: #409eff;
  background: transparent;
}
.ge-form :deep(.el-form-item) {
  margin-bottom: 15px;
}
.ge-hint {
  color: #909399;
  font-size: 12px;
  line-height: 1.5;
  margin-left: 10px;
}
.ge-pic-row {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
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
.tab-footer {
  display: flex;
  justify-content: center;
  gap: 14px;
  padding: 16px 0 8px;
  border-top: 1px solid #ebeef5;
}
</style>
