<script setup lang="ts">
// 文章列表（批次5-A 任务194）：搜索/编辑/删除/上下架/审核/复制
// bid prop 由 merchantViewOf 注入（'gt0' 商户过滤视图），缺省不过滤
import { computed, onMounted, reactive, ref, shallowRef, watch } from 'vue'
import { ElMessage, ElMessageBox, type UploadRequestOptions } from 'element-plus'
import '@wangeditor/editor/dist/css/style.css'
import { Editor, Toolbar } from '@wangeditor/editor-for-vue'
import {
  copyArticle,
  deleteArticle,
  getArticleCategoryList,
  getArticleDetail,
  getArticleList,
  saveArticle,
  setArticleCheckst,
  setArticleSt,
  type ArticleCategoryItem,
  type ArticleItem,
} from '../../api/article'
import { getMemberLevelList } from '../../api/member'
import { uploadFile } from '../../api/goods'
import { formatTime } from '../../utils/time'
import ImgIconPicker from '../../components/ImgIconPicker.vue'

const props = withDefaults(defineProps<{ bid?: string }>(), { bid: '' })

// ============ 列表 ============
const loading = ref(false)
const list = ref<ArticleItem[]>([])
const total = ref(0)
const query = reactive({
  page: 1,
  pagesize: 10,
  keyword: '',
  status: '',
  cid: 0,
  start_time: 0,
  end_time: 0,
})
const dateRange = ref<[number, number] | null>(null)
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

// ============ 会员等级（showtj 多选用） ============
const levels = ref<{ id: number; name: string }[]>([])

// ============ 加载 ============
async function loadCategories() {
  const res = await getArticleCategoryList()
  categories.value = res.list || []
}
async function loadLevels() {
  try {
    const res = await getMemberLevelList()
    levels.value = (res.list || []).map((l) => ({ id: l.id, name: l.name }))
  } catch {
    levels.value = []
  }
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
  query.start_time = dateRange.value ? Math.floor(dateRange.value[0] / 1000) : 0
  query.end_time = dateRange.value ? Math.floor(dateRange.value[1] / 1000) : 0
  query.page = 1
  load()
}
function handleReset() {
  query.keyword = ''
  query.status = ''
  query.cid = 0
  dateRange.value = null
  handleSearch()
}

// ============ 编辑弹窗 ============
const dialogVisible = ref(false)
const saving = ref(false)
const form = reactive({
  id: 0,
  cid: 0,
  name: '',
  subname: '',
  pic: '',
  author: '',
  content: '',
  sort: 1,
  status: 1,
  showname: 1,
  showsubname: 1,
  showreadcount: 1,
  showsendtime: 1,
  showauthor: 1,
  canpl: 1,
  canplrp: 1,
  pinglun_check: 0,
  showtj: ['-1'] as string[],
  fujian: '',
  mid: 0,
})

const editorRef = shallowRef()
const toolbarConfig = {}
const editorConfig = { placeholder: '请输入文章内容...', MENU_CONF: {} }
function handleCreated(editor: any) {
  editorRef.value = editor
}

function resetForm() {
  form.id = 0
  form.cid = 0
  form.name = ''
  form.subname = ''
  form.pic = ''
  form.author = ''
  form.content = ''
  form.sort = 1
  form.status = 1
  form.showname = 1
  form.showsubname = 1
  form.showreadcount = 1
  form.showsendtime = 1
  form.showauthor = 1
  form.canpl = 1
  form.canplrp = 1
  form.pinglun_check = 0
  form.showtj = ['-1']
  form.fujian = ''
  form.mid = 0
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
    showtj: String(detail.showtj ?? '-1').split(',').filter((v) => v !== ''),
  })
  dialogVisible.value = true
}
async function handleSave() {
  if (!form.name.trim()) {
    ElMessage.warning('请填写文章标题')
    return
  }
  saving.value = true
  try {
    await saveArticle({ ...form, id: form.id || undefined, content: form.content })
    ElMessage.success('保存成功')
    dialogVisible.value = false
    load()
  } finally {
    saving.value = false
  }
}

// showtj 互斥逻辑：选中 -1 时清空其它
function handleShowtjChange(vals: string[]) {
  if (vals.length > 1 && vals[vals.length - 1] === '-1') {
    form.showtj = ['-1']
  } else if (vals.length > 1 && vals.includes('-1')) {
    form.showtj = vals.filter((v) => v !== '-1')
  }
}

// ============ 选图（ImgIconPicker 统一选图） ============
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

// ============ 附件上传 ============
async function uploadFujian(options: UploadRequestOptions) {
  try {
    const res = await uploadFile(options.file)
    form.fujian = res.url
  } catch {
    ElMessage.error('附件上传失败')
  }
}
// wangEditor 内嵌图片上传
const editorUploadConfig = {
  async customUpload(file: File, insertFn: (url: string) => void) {
    try {
      const res = await uploadFile(file)
      insertFn(res.url)
    } catch {
      ElMessage.error('图片上传失败')
    }
  },
}
editorConfig.MENU_CONF = { uploadImage: editorUploadConfig }

// ============ 行操作 ============
async function handleSetst(row: ArticleItem) {
  await setArticleSt([row.id], row.status === 1 ? 0 : 1)
  ElMessage.success(row.status === 1 ? '已下架' : '已上架')
  load()
}
async function handleCopy(row: ArticleItem) {
  await copyArticle(row.id)
  ElMessage.success('复制成功')
  load()
}
async function handleDelete(rows: ArticleItem[]) {
  await ElMessageBox.confirm(`确定删除选中的 ${rows.length} 篇文章？关联评论等数据将一并删除`, '提示', { type: 'warning' })
  await deleteArticle(rows.map((r) => r.id))
  ElMessage.success('删除成功')
  load()
}
async function handleCheck(row: ArticleItem, st: 1 | 2) {
  let reason = ''
  if (st === 2) {
    const { value } = await ElMessageBox.prompt('请输入驳回原因', '审核驳回', { inputType: 'textarea' })
    reason = value || ''
  }
  await setArticleCheckst(row.id, st, reason)
  ElMessage.success(st === 1 ? '已通过' : '已驳回')
  load()
}
async function handleBatchSetst(st: number) {
  if (!selected.value.length) return
  await setArticleSt(selected.value.map((r) => r.id), st)
  ElMessage.success(st === 1 ? '已上架' : '已下架')
  load()
}

// ============ 展示辅助 ============
function showtjText(v: string) {
  const arr = String(v ?? '').split(',').filter((x) => x !== '')
  if (!arr.length || arr.includes('-1')) return '全部会员'
  return arr
    .map((x) => (x === '0' ? '关注会员' : levels.value.find((l) => String(l.id) === x)?.name || `等级${x}`))
    .join('，')
}

watch(
  () => props.bid,
  () => handleSearch()
)

onMounted(() => {
  loadCategories()
  loadLevels()
  load()
})
</script>

<template>
  <div class="article-list">
    <div class="toolbar">
      <el-input v-model="query.keyword" placeholder="文章标题/副标题" clearable style="width: 200px" @keyup.enter="handleSearch" />
      <el-select v-model="query.status" placeholder="状态" clearable style="width: 120px">
        <el-option label="待审核" value="0" />
        <el-option label="已上架" value="1" />
        <el-option label="已驳回" value="2" />
      </el-select>
      <el-select v-model="query.cid" placeholder="分类" clearable style="width: 160px">
        <el-option v-for="top in categoryTree" :key="top.id" :label="top.name" :value="top.id" />
        <template v-for="top in categoryTree" :key="`c${top.id}`">
          <el-option v-for="c in top.children" :key="c.id" :label="`　${top.name} / ${c.name}`" :value="c.id" />
        </template>
      </el-select>
      <el-date-picker v-model="dateRange" type="datetimerange" range-separator="至" start-placeholder="开始时间" end-placeholder="结束时间" value-format="x" />
      <el-button type="primary" @click="handleSearch">搜索</el-button>
      <el-button @click="handleReset">重置</el-button>
      <div class="toolbar-right">
        <el-button type="primary" @click="openAdd">新增文章</el-button>
        <el-button @click="handleBatchSetst(1)" :disabled="!selected.length">批量上架</el-button>
        <el-button @click="handleBatchSetst(0)" :disabled="!selected.length">批量下架</el-button>
        <el-button type="danger" @click="handleDelete(selected)" :disabled="!selected.length">批量删除</el-button>
      </div>
    </div>

    <el-table v-loading="loading" :data="list" border stripe @selection-change="(rows: ArticleItem[]) => (selected = rows)">
      <el-table-column type="selection" width="42" />
      <el-table-column prop="id" label="ID" width="70" />
      <el-table-column label="封面" width="80">
        <template #default="{ row }">
          <el-image v-if="row.pic" :src="row.pic" style="width: 56px; height: 40px" fit="cover" />
          <span v-else>-</span>
        </template>
      </el-table-column>
      <el-table-column prop="name" label="标题" min-width="180" show-overflow-tooltip />
      <el-table-column label="分类" width="110">
        <template #default="{ row }">{{ categoryMap[row.cid] || '-' }}</template>
      </el-table-column>
      <el-table-column prop="readcount" label="阅读" width="80" />
      <el-table-column prop="zan" label="点赞" width="80" />
      <el-table-column label="可见" width="110">
        <template #default="{ row }">{{ showtjText(row.showtj) }}</template>
      </el-table-column>
      <el-table-column label="状态" width="90">
        <template #default="{ row }">
          <el-tag v-if="row.status === 1" type="success">已上架</el-tag>
          <el-tag v-else-if="row.status === 2" type="danger">已驳回</el-tag>
          <el-tag v-else type="warning">待审核</el-tag>
        </template>
      </el-table-column>
      <el-table-column label="发布时间" width="150">
        <template #default="{ row }">{{ formatTime(row.sendtime || row.createtime) }}</template>
      </el-table-column>
      <el-table-column label="操作" width="300" fixed="right">
        <template #default="{ row }">
          <el-button link type="primary" @click="openEdit(row)">编辑</el-button>
          <el-button link :type="row.status === 1 ? 'warning' : 'success'" @click="handleSetst(row)">
            {{ row.status === 1 ? '下架' : '上架' }}
          </el-button>
          <el-button v-if="row.status !== 1" link type="success" @click="handleCheck(row, 1)">通过</el-button>
          <el-button v-if="row.status !== 2" link type="danger" @click="handleCheck(row, 2)">驳回</el-button>
          <el-button link @click="handleCopy(row)">复制</el-button>
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

    <!-- 编辑弹窗 -->
    <el-dialog v-model="dialogVisible" :title="form.id ? '编辑文章' : '新增文章'" width="860px" destroy-on-close>
      <el-form label-width="100px">
        <el-form-item label="标题" required>
          <el-input v-model="form.name" placeholder="文章标题" maxlength="100" show-word-limit />
        </el-form-item>
        <el-form-item label="副标题">
          <el-input v-model="form.subname" placeholder="副标题（可选）" maxlength="100" />
        </el-form-item>
        <el-form-item label="所属分类">
          <el-select v-model="form.cid" placeholder="选择分类" style="width: 260px">
            <el-option v-for="top in categoryTree" :key="top.id" :label="top.name" :value="top.id" />
            <template v-for="top in categoryTree" :key="`f${top.id}`">
              <el-option v-for="c in top.children" :key="c.id" :label="`　${top.name} / ${c.name}`" :value="c.id" />
            </template>
          </el-select>
        </el-form-item>
        <el-form-item label="封面图">
          <div class="pic-uploader">
            <el-image v-if="form.pic" :src="form.pic" style="width: 120px; height: 80px" fit="cover" />
            <el-button size="small" @click="openImgPicker((u) => (form.pic = u))">{{ form.pic ? '更换' : '选择图片' }}</el-button>
          </div>
        </el-form-item>
        <el-form-item label="作者">
          <el-input v-model="form.author" placeholder="作者（可选）" style="width: 260px" />
        </el-form-item>
        <el-form-item label="内容">
          <div class="editor-wrap">
            <Toolbar :editor="editorRef" :defaultConfig="toolbarConfig" style="border-bottom: 1px solid #e4e7ed" />
            <Editor
              v-model="form.content"
              :defaultConfig="editorConfig"
              style="height: 300px; overflow-y: hidden"
              @onCreated="handleCreated"
            />
          </div>
        </el-form-item>
        <el-form-item label="排序">
          <el-input-number v-model="form.sort" :min="0" :max="9999" />
        </el-form-item>
        <el-form-item label="附件">
          <div style="display: flex; gap: 8px; align-items: center">
            <el-input v-model="form.fujian" placeholder="附件地址（可选）" style="width: 360px" />
            <el-upload :show-file-list="false" :http-request="uploadFujian">
              <el-button size="small">上传附件</el-button>
            </el-upload>
          </div>
        </el-form-item>
        <el-form-item label="打赏收款会员">
          <el-input-number v-model="form.mid" :min="0" placeholder="会员ID，0 表示不开启打赏" />
          <span class="tip">填会员ID开启打赏，0 为不开启</span>
        </el-form-item>
        <el-form-item label="可见会员">
          <el-select v-model="form.showtj" multiple placeholder="选择可见范围" style="width: 360px" @change="handleShowtjChange">
            <el-option label="全部会员" value="-1" />
            <el-option label="关注会员" value="0" />
            <el-option v-for="l in levels" :key="l.id" :label="l.name" :value="String(l.id)" />
          </el-select>
        </el-form-item>
        <el-form-item label="显示开关">
          <el-checkbox v-model="form.showname" :true-value="1" :false-value="0">显示标题</el-checkbox>
          <el-checkbox v-model="form.showsubname" :true-value="1" :false-value="0">显示副标题</el-checkbox>
          <el-checkbox v-model="form.showauthor" :true-value="1" :false-value="0">显示作者</el-checkbox>
          <el-checkbox v-model="form.showsendtime" :true-value="1" :false-value="0">显示发布时间</el-checkbox>
          <el-checkbox v-model="form.showreadcount" :true-value="1" :false-value="0">显示阅读数</el-checkbox>
        </el-form-item>
        <el-form-item label="评论设置">
          <el-checkbox v-model="form.canpl" :true-value="1" :false-value="0">允许评论</el-checkbox>
          <el-checkbox v-model="form.canplrp" :true-value="1" :false-value="0">允许回复</el-checkbox>
          <el-checkbox v-model="form.pinglun_check" :true-value="1" :false-value="0">评论需审核</el-checkbox>
        </el-form-item>
        <el-form-item label="上架状态">
          <el-switch v-model="form.status" :active-value="1" :inactive-value="0" active-text="上架" inactive-text="下架" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="saving" @click="handleSave">保存</el-button>
      </template>
    </el-dialog>

    <ImgIconPicker v-model:visible="imgPickerVisible" default-tab="library" @pick="onImgPick" />
  </div>
</template>

<style scoped>
.article-list {
  padding: 12px;
}
.toolbar {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
  margin-bottom: 12px;
}
.toolbar-right {
  margin-left: auto;
  display: flex;
  gap: 8px;
}
.pic-uploader {
  display: flex;
  align-items: center;
  gap: 12px;
}
.editor-wrap {
  border: 1px solid #e4e7ed;
  width: 100%;
}
.tip {
  margin-left: 8px;
  color: #909399;
  font-size: 12px;
}
</style>
