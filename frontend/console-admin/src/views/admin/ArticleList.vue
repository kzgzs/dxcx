<script setup lang="ts">
// 文章列表（对齐点大「扩展→文章管理→文章列表」截图）
// - 列表：工具栏 添加/删除/开启/关闭 + 标题/分类/状态搜索；表格 文章信息(图+标题+日期)/阅读量/序号/创建时间/状态/操作
// - 添加/编辑平铺主区域 + 右上 X；两个选项卡：基本信息 / 内容编辑
//   基本信息：标题/描述/作者/图片/分类/阅读量/序号/发布时间/详情页显示/评论开关/状态（输入框收窄）
//   内容编辑：左手机预览 + 右组件库 + 富文本设置(SimpleRichEditor)
// - 商户视图 bid='gt0' 仅查看商户文章，不提供添加（同张表按 bid 过滤）
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
import ImgIconPicker from '../../components/ImgIconPicker.vue'
import SimpleRichEditor from '../../components/SimpleRichEditor.vue'
import { formatTime } from '../../utils/time'

const props = withDefaults(defineProps<{ bid?: string }>(), { bid: '' })
const isMerchantView = computed(() => props.bid === 'gt0')

// ============ 视图切换 ============
const viewMode = ref<'list' | 'edit'>('list')

// ============ 列表 ============
const loading = ref(false)
const list = ref<ArticleItem[]>([])
const total = ref(0)
const query = reactive({ page: 1, pagesize: 10, keyword: '', status: '', cid: 0 as number })
const selected = ref<ArticleItem[]>([])

// ============ 分类 ============
const categories = ref<ArticleCategoryItem[]>([])
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

// ============ 编辑 ============
const saving = ref(false)
const activeTab = ref<'base' | 'content'>('base')
const form = reactive({
  id: 0,
  cid: 0,
  name: '',
  subname: '',
  pic: '',
  author: '',
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
})
/** 富文本 HTML（content 字段直存 HTML 字符串） */
const contentHtml = ref('')

const createTimeMs = computed<number>({
  get: () => (form.createtime ? form.createtime * 1000 : Date.now()),
  set: (v) => {
    form.createtime = v ? Math.floor(new Date(v).getTime() / 1000) : 0
  },
})

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

/** 从历史 designer JSON 内容中提取富文本 HTML；纯 HTML 则原样返回 */
function extractRichText(content: string): string {
  if (!content) return ''
  try {
    const arr = JSON.parse(content)
    if (Array.isArray(arr)) {
      const hit = arr.find((m: any) => m && m.temp === 'richtext' && typeof m.content === 'string' && m.content)
      if (hit) return hit.content
      const any = arr.find((m: any) => m && typeof m.content === 'string' && m.content)
      return any ? any.content : ''
    }
  } catch {
    // 非 JSON，按 HTML 原样
  }
  return content
}

function resetForm() {
  Object.assign(form, {
    id: 0,
    cid: 0,
    name: '',
    subname: '',
    pic: '',
    author: '',
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
  })
  contentHtml.value = ''
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
    id: detail.id,
    cid: Number(detail.cid) || 0,
    name: detail.name || '',
    subname: detail.subname || '',
    pic: detail.pic || '',
    author: detail.author || '',
    readcount: Number(detail.readcount) || 0,
    sort: Number(detail.sort) || 0,
    createtime: Number(detail.createtime) || Math.floor(Date.now() / 1000),
    status: Number(detail.status ?? 1),
    showname: Number(detail.showname ?? 1),
    showsubname: Number(detail.showsubname ?? 1),
    showreadcount: Number(detail.showreadcount ?? 1),
    showsendtime: Number(detail.showsendtime ?? 1),
    showauthor: Number(detail.showauthor ?? 1),
    canpl: Number(detail.canpl ?? 1),
    canplrp: Number(detail.canplrp ?? 1),
    pinglun_check: Number(detail.pinglun_check ?? 0),
  })
  contentHtml.value = extractRichText(String(detail.content || ''))
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
      content: contentHtml.value,
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
    })
    ElMessage.success(form.id ? '保存成功' : '新增成功')
    backToList()
  } finally {
    saving.value = false
  }
}

// ============ 选图 ============
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

// ============ 组件库（装饰，照截图排布） ============
const componentLib = [
  '公告', '轮播图', '店招', '搜索框', '单图', '多图', '图片魔方', '富文本',
  '视频', '标题', '列表导航', '文本', '按钮', '按钮导航', '悬浮按钮', '辅助线',
  '辅助空白', '选项卡', '地图', '热区', '商城商品', '拼团商品', '周期购商品', '砍价商品',
  '秒杀商品', '团购商品', '幸运拼团', '餐饮菜品', '积分兑换', '优惠券', '文章列表', '表单',
  '短视频', '直播列表', '商家', '预约服务', '课程列表', '小程序广告', '会员信息',
]

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
    <!-- ============ 列表 ============ -->
    <div v-if="viewMode === 'list'">
      <div class="page-title"><span class="title-bar">≡</span>文章管理</div>

      <el-card shadow="never" class="toolbar-card">
        <div class="toolbar-left">
          <el-button v-if="!isMerchantView" type="success" @click="openAdd">添加</el-button>
          <el-button @click="handleDelete(selected)">删除</el-button>
          <el-button @click="handleBatchSetst(1)">开启</el-button>
          <el-button @click="handleBatchSetst(0)">关闭</el-button>
        </div>
        <el-form inline class="toolbar-search" @submit.prevent>
          <el-form-item label="标题">
            <el-input v-model="query.keyword" clearable style="width: 200px" @keyup.enter="handleSearch" />
          </el-form-item>
          <el-form-item label="分类">
            <el-select v-model="query.cid" placeholder="全部" clearable style="width: 130px">
              <el-option label="全部" :value="0" />
              <el-option v-for="top in categoryTree" :key="top.id" :label="top.name" :value="top.id" />
              <template v-for="top in categoryTree" :key="`c${top.id}`">
                <el-option v-for="c in top.children" :key="c.id" :label="`　${top.name} / ${c.name}`" :value="c.id" />
              </template>
            </el-select>
          </el-form-item>
          <el-form-item label="状态">
            <el-select v-model="query.status" placeholder="全部" clearable style="width: 100px">
              <el-option label="开启" value="1" />
              <el-option label="关闭" value="0" />
            </el-select>
          </el-form-item>
          <el-form-item>
            <el-button type="success" class="search-btn" @click="handleSearch">
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
          :header-cell-style="{ background: '#e6e6e6' }"
          @selection-change="(rows: ArticleItem[]) => (selected = rows)"
        >
          <el-table-column type="selection" width="45" />
          <el-table-column prop="id" label="ID" width="70" align="left" header-align="left" sortable="custom" />
          <el-table-column v-if="isMerchantView" prop="bname" label="所属商户" width="120" show-overflow-tooltip align="left" header-align="left">
            <template #default="{ row }">{{ row.bname || '-' }}</template>
          </el-table-column>
          <el-table-column label="文章信息" min-width="320" align="left" header-align="left">
            <template #default="{ row }">
              <div class="art-cell">
                <el-image v-if="row.pic" :src="row.pic" class="art-pic" fit="cover" />
                <div v-else class="art-pic art-pic-empty">
                  <svg viewBox="0 0 1024 1024" width="26" height="26" fill="#c0c4cc"><path d="M512 64C264.6 64 64 264.6 64 512s200.6 448 448 448 448-200.6 448-448S759.4 64 512 64zm0 128a96 96 0 1 1 0 192 96 96 0 0 1 0-192zm224 512H288c0-88.4 80.4-160 224-160s224 71.6 224 160z"/></svg>
                </div>
                <div class="art-meta">
                  <div class="art-name">
                    <span v-if="row.cname" class="art-cname">[{{ row.cname }}]</span>{{ row.name }}
                  </div>
                  <div class="art-date">{{ row.createtime ? formatTime(Number(row.createtime)).slice(0, 10) : '' }}</div>
                </div>
              </div>
            </template>
          </el-table-column>
          <el-table-column prop="readcount" label="阅读量" width="90" align="left" header-align="left" sortable />
          <el-table-column prop="sort" label="序号" width="90" align="left" header-align="left" sortable />
          <el-table-column label="创建时间" width="160" align="left" header-align="left">
            <template #default="{ row }">{{ row.createtime ? formatTime(Number(row.createtime)) : '-' }}</template>
          </el-table-column>
          <el-table-column label="状态" width="90" align="left" header-align="left">
            <template #default="{ row }">
              <el-tag v-if="row.status === 1" effect="dark" type="primary" class="st-tag" @click="handleSetst(row)">已开启</el-tag>
              <el-tag v-else effect="dark" type="info" class="st-tag" @click="handleSetst(row)">已关闭</el-tag>
            </template>
          </el-table-column>
          <el-table-column label="操作" width="110" align="left" header-align="left">
            <template #default="{ row }">
              <div class="row-ops">
                <div class="row-ops-top">
                  <el-button link type="primary" @click="handleCopy(row)">复制</el-button>
                  <el-button link type="primary" @click="openEdit(row)">编辑</el-button>
                </div>
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

    <!-- ============ 平铺编辑 ============ -->
    <div v-else class="edit-panel">
      <div class="edit-head">
        <el-tabs v-model="activeTab" class="edit-tabs">
          <el-tab-pane label="基本信息" name="base" />
          <el-tab-pane label="内容编辑" name="content" />
        </el-tabs>
        <el-button class="edit-close" circle @click="backToList">
          <svg viewBox="0 0 1024 1024" width="16" height="16" fill="currentColor"><path d="M764.288 214.595 512 466.883 259.712 214.595a32 32 0 0 0-45.248 45.248L466.752 512 214.464 764.288a32 32 0 1 0 45.248 45.248L512 557.117l252.288 252.419a32 32 0 0 0 45.248-45.248L557.248 512l252.288-252.157a32 32 0 0 0-45.248-45.248z" /></svg>
        </el-button>
      </div>

      <!-- ===== 基本信息 ===== -->
      <div v-show="activeTab === 'base'">
        <el-form label-width="80px" class="edit-form">
          <el-form-item label="标题">
            <el-input v-model="form.name" maxlength="100" style="width: 320px" placeholder="请输入标题" />
          </el-form-item>
          <el-form-item label="描述">
            <el-input v-model="form.subname" maxlength="200" style="width: 320px" />
            <span class="hint">用于转发分享描述</span>
          </el-form-item>
          <el-form-item label="作者">
            <el-input v-model="form.author" maxlength="50" style="width: 200px" />
          </el-form-item>
          <el-form-item label="图片">
            <el-button @click="openImgPicker((u) => (form.pic = u))">上传图片</el-button>
            <span class="hint">建议尺寸：600×430像素</span>
            <div class="pic-preview">
              <el-image v-if="form.pic" :src="form.pic" class="pic-preview-img" fit="cover" />
              <div v-else class="pic-preview-empty"></div>
            </div>
          </el-form-item>
          <el-form-item label="分类">
            <el-select v-model="form.cid" placeholder="--请选择--" clearable style="width: 200px">
              <el-option label="--请选择--" :value="0" />
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
            <span class="hint">用于排序，越大越靠前</span>
          </el-form-item>
          <el-form-item label="发布时间">
            <el-date-picker
              v-model="createTimeMs"
              type="datetime"
              placeholder="选择发布时间"
              format="YYYY-MM-DD HH:mm:ss"
              style="width: 220px"
            />
          </el-form-item>
          <el-form-item label="详情页显示">
            <el-checkbox-group v-model="detailShow" class="show-group">
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
            <el-button type="primary" @click="activeTab = 'content'">下一步，编辑文章详情</el-button>
            <el-button type="danger" :loading="saving" @click="handleSave">提 交</el-button>
          </el-form-item>
        </el-form>
      </div>

      <!-- ===== 内容编辑 ===== -->
      <div v-show="activeTab === 'content'">
        <div class="content-wrap">
          <!-- 左：手机预览 -->
          <div class="phone-col">
            <div class="phone">
              <div class="phone-status">
                <span>wechat</span><span>12:00</span><span>100%</span>
              </div>
              <div class="phone-title">文章详情</div>
              <div class="phone-body" v-html="contentHtml || '<p style=\'color:#bbb\'>这里是【富文本】区域</p>'"></div>
            </div>
          </div>
          <!-- 右：组件库 + 富文本设置 -->
          <div class="right-col">
            <div class="comp-lib">
              <span v-for="c in componentLib" :key="c" class="comp-item">{{ c }}</span>
            </div>
            <div class="rt-setting">
              <div class="rt-label">富文本设置</div>
              <SimpleRichEditor v-model="contentHtml" />
            </div>
          </div>
        </div>
        <div class="tab-footer">
          <el-button type="primary" @click="activeTab = 'base'">上一步，编辑基本信息</el-button>
          <el-button type="danger" :loading="saving" @click="handleSave">提 交</el-button>
        </div>
      </div>
    </div>

    <ImgIconPicker v-model:visible="imgPickerVisible" default-tab="library" @pick="onImgPick" />
  </div>
</template>

<style scoped>
.article-page {
  padding: 3px;
}
.page-title {
  font-size: 15px;
  font-weight: 600;
  color: #303133;
  padding: 4px 2px 8px;
}
.title-bar {
  margin-right: 4px;
}
.toolbar-card {
  margin-bottom: 3px;
}
.toolbar-card :deep(.el-card__body) {
  padding: 10px 14px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  flex-wrap: nowrap;
}
.toolbar-left {
  display: flex;
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
  margin: 0;
}
.toolbar-search :deep(.el-form-item) {
  margin-bottom: 0;
  margin-right: 8px;
}
.toolbar-search :deep(.el-form-item:last-child) {
  margin-right: 0;
}
.search-btn {
  padding: 8px 14px;
}
.search-btn svg {
  display: block;
}
.pagination {
  margin-top: 12px;
  justify-content: flex-end;
}
.st-tag {
  cursor: pointer;
}
/* 文章信息 */
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
}
.art-meta {
  flex: 1;
  min-width: 0;
}
.art-name {
  font-size: 13px;
  font-weight: 600;
  color: #303133;
  word-break: break-all;
  line-height: 18px;
}
.art-cname {
  color: #f56c6c;
  font-weight: 400;
}
.art-date {
  font-size: 12px;
  color: #999;
  margin-top: 2px;
}
/* 行操作：复制/编辑 一行，删除 换行 */
.row-ops {
  display: flex;
  flex-direction: column;
  gap: 2px;
}
.row-ops-top {
  display: flex;
  gap: 10px;
}
.row-ops .el-button {
  margin: 0;
}
/* 平铺编辑 */
.edit-panel {
  background: #fff;
  border: 1px solid #e6e6e6;
  padding: 8px 20px 20px;
}
.edit-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  border-bottom: 1px solid #f0f0f0;
  margin-bottom: 16px;
}
.edit-tabs {
  flex: 1;
}
.edit-tabs :deep(.el-tabs__header) {
  margin-bottom: 0;
}
.edit-close {
  border: none;
  background: transparent;
  color: #909399;
}
.edit-close:hover {
  color: #409eff;
  background: transparent;
}
.edit-form :deep(.el-form-item) {
  margin-bottom: 14px;
}
.hint {
  color: #909399;
  font-size: 12px;
  margin-left: 10px;
}
.pic-preview {
  margin-top: 8px;
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
  background: #fafafa;
}
.show-group {
  display: flex;
  gap: 8px;
}
/* 内容编辑 */
.content-wrap {
  display: flex;
  gap: 24px;
  align-items: flex-start;
}
.phone-col {
  flex: 0 0 280px;
}
.phone {
  width: 260px;
  border: 8px solid #333;
  border-radius: 28px;
  background: #fff;
  overflow: hidden;
}
.phone-status {
  display: flex;
  justify-content: space-between;
  font-size: 10px;
  color: #666;
  padding: 4px 8px;
  background: #f7f7f7;
}
.phone-title {
  text-align: center;
  font-size: 14px;
  font-weight: 600;
  padding: 8px;
  border-bottom: 1px solid #eee;
}
.phone-body {
  min-height: 300px;
  padding: 12px;
  font-size: 13px;
  line-height: 1.6;
}
.right-col {
  flex: 1;
  min-width: 0;
}
.comp-lib {
  display: grid;
  grid-template-columns: repeat(8, 1fr);
  gap: 6px;
  margin-bottom: 16px;
}
.comp-item {
  border: 1px solid #dcdfe6;
  border-radius: 3px;
  font-size: 12px;
  text-align: center;
  padding: 5px 2px;
  color: #606266;
  background: #fafafa;
}
.rt-label {
  font-size: 13px;
  color: #606266;
  margin-bottom: 8px;
}
.tab-footer {
  display: flex;
  justify-content: center;
  gap: 14px;
  padding: 16px 0 8px;
}
</style>
