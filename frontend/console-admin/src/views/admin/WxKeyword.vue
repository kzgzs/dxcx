<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { delWxKeyword, getWxKeywordList, saveWxKeyword } from '../../api/wxext'
import type { WxKeywordItem } from '../../api/wxext'
import { formatTime } from '../../utils/time'
import ImgIconPicker from '../../components/ImgIconPicker.vue'

const loading = ref(false)
const tableData = ref<WxKeywordItem[]>([])
const total = ref(0)

const query = reactive({
  page: 1,
  pagesize: 10,
  keyword: '',
  ktype: '' as number | string,
})

const KTYPE_TEXT: Record<number, string> = { 0: '全匹配', 1: '半匹配', 2: '关注回复' }
const MSGTYPE_TEXT: Record<string, string> = {
  text: '文本',
  image: '图片',
  link: '图文链接',
  miniprogrampage: '小程序卡片',
}

async function fetchList() {
  loading.value = true
  try {
    const data = await getWxKeywordList({
      page: query.page,
      pagesize: query.pagesize,
      keyword: query.keyword || undefined,
      ktype: query.ktype === '' ? undefined : query.ktype,
    })
    tableData.value = data.list || []
    total.value = data.total || 0
  } catch {
    // 拦截器已提示
  } finally {
    loading.value = false
  }
}

function handleSearch() {
  query.page = 1
  fetchList()
}

function handleReset() {
  query.keyword = ''
  query.ktype = ''
  query.page = 1
  fetchList()
}

function handlePageChange(page: number) {
  query.page = page
  fetchList()
}

function handleSizeChange(size: number) {
  query.pagesize = size
  query.page = 1
  fetchList()
}

// 列表内容预览（非 text 时解析 JSON 摘要）
function contentPreview(row: WxKeywordItem): string {
  if (row.msgtype === 'text') return row.content || ''
  try {
    const obj = JSON.parse(row.content) as Record<string, string>
    if (row.msgtype === 'image') return obj.picurl || '[图片]'
    if (row.msgtype === 'link') return obj.title || obj.url || '[图文]'
    if (row.msgtype === 'miniprogrampage') return obj.title || obj.appid || '[小程序卡片]'
  } catch {
    return row.content
  }
  return row.content
}

// ===== 新增/编辑 =====
const editVisible = ref(false)
const saving = ref(false)

interface KeywordForm {
  id: number
  keyword: string
  ktype: number
  msgtype: string
  sort: number
  status: number
  text: string
  imagePicurl: string
  link: { title: string; description: string; url: string; picurl: string }
  mini: { title: string; appid: string; pagepath: string; thumb: string }
}

const form = reactive<KeywordForm>({
  id: 0,
  keyword: '',
  ktype: 0,
  msgtype: 'text',
  sort: 0,
  status: 1,
  text: '',
  imagePicurl: '',
  link: { title: '', description: '', url: '', picurl: '' },
  mini: { title: '', appid: '', pagepath: '', thumb: '' },
})

function resetForm() {
  form.id = 0
  form.keyword = ''
  form.ktype = 0
  form.msgtype = 'text'
  form.sort = 0
  form.status = 1
  form.text = ''
  form.imagePicurl = ''
  form.link = { title: '', description: '', url: '', picurl: '' }
  form.mini = { title: '', appid: '', pagepath: '', thumb: '' }
}

function openAdd() {
  resetForm()
  editVisible.value = true
}

function openEdit(row: WxKeywordItem) {
  resetForm()
  form.id = row.id
  form.keyword = row.keyword
  form.ktype = row.ktype
  form.msgtype = row.msgtype
  form.sort = row.sort
  form.status = row.status
  if (row.msgtype === 'text') {
    form.text = row.content
  } else {
    try {
      const obj = JSON.parse(row.content) as Record<string, string>
      if (row.msgtype === 'image') {
        form.imagePicurl = obj.picurl || ''
      } else if (row.msgtype === 'link') {
        form.link = {
          title: obj.title || '',
          description: obj.description || '',
          url: obj.url || '',
          picurl: obj.picurl || '',
        }
      } else if (row.msgtype === 'miniprogrampage') {
        form.mini = {
          title: obj.title || '',
          appid: obj.appid || '',
          pagepath: obj.pagepath || '',
          thumb: obj.thumb || '',
        }
      }
    } catch {
      // 历史脏数据：原文回填 text
      form.text = row.content
    }
  }
  editVisible.value = true
}

// 组装 content（非 text 为 JSON 串）
function buildContent(): { content: string; ok: boolean; msg: string } {
  if (form.msgtype === 'text') {
    return form.text.trim()
      ? { content: form.text, ok: true, msg: '' }
      : { content: '', ok: false, msg: '请输入回复文本' }
  }
  if (form.msgtype === 'image') {
    return form.imagePicurl
      ? { content: JSON.stringify({ picurl: form.imagePicurl }), ok: true, msg: '' }
      : { content: '', ok: false, msg: '请上传图片' }
  }
  if (form.msgtype === 'link') {
    if (!form.link.title.trim() || !form.link.url.trim()) {
      return { content: '', ok: false, msg: '图文标题与链接必填' }
    }
    return { content: JSON.stringify(form.link), ok: true, msg: '' }
  }
  // miniprogrampage
  if (!form.mini.title.trim() || !form.mini.appid.trim() || !form.mini.pagepath.trim()) {
    return { content: '', ok: false, msg: '小程序卡片的标题/AppID/页面路径必填' }
  }
  return { content: JSON.stringify(form.mini), ok: true, msg: '' }
}

async function handleSave() {
  if (!form.keyword.trim()) {
    ElMessage.warning('请输入关键字')
    return
  }
  const built = buildContent()
  if (!built.ok) {
    ElMessage.warning(built.msg)
    return
  }
  saving.value = true
  try {
    await saveWxKeyword({
      id: form.id || undefined,
      keyword: form.keyword.trim(),
      ktype: form.ktype,
      msgtype: form.msgtype,
      content: built.content,
      sort: form.sort,
      status: form.status,
    })
    ElMessage.success('操作成功')
    editVisible.value = false
    fetchList()
  } catch {
    // 拦截器已提示
  } finally {
    saving.value = false
  }
}

// ===== 删除 =====
async function handleDel(row: WxKeywordItem) {
  try {
    await ElMessageBox.confirm(`确定删除关键字「${row.keyword}」吗？`, '提示', { type: 'warning' })
  } catch {
    return
  }
  try {
    await delWxKeyword([row.id])
    ElMessage.success('删除成功')
    fetchList()
  } catch {
    // 拦截器已提示
  }
}

// ===== 选图（ImgIconPicker 统一选图） =====
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

onMounted(() => {
  fetchList()
})
</script>

<template>
  <div>
    <!-- 搜索工具栏 -->
    <el-card shadow="never" class="toolbar-card">
      <el-form inline @submit.prevent>
        <el-form-item label="关键字">
          <el-input v-model="query.keyword" placeholder="关键字" clearable style="width: 160px" @keyup.enter="handleSearch" />
        </el-form-item>
        <el-form-item label="匹配">
          <el-select v-model="query.ktype" placeholder="全部" clearable style="width: 120px">
            <el-option label="全匹配" :value="0" />
            <el-option label="半匹配" :value="1" />
            <el-option label="关注回复" :value="2" />
          </el-select>
        </el-form-item>
        <el-form-item>
          <el-button type="primary" @click="handleSearch">搜索</el-button>
          <el-button @click="handleReset">重置</el-button>
          <el-button type="success" @click="openAdd">新增关键字</el-button>
        </el-form-item>
      </el-form>
    </el-card>

    <!-- 列表 -->
    <el-card shadow="never">
      <el-table v-loading="loading" :data="tableData" stripe border size="small">
        <el-table-column prop="keyword" label="关键字" min-width="120" show-overflow-tooltip />
        <el-table-column label="匹配方式" width="100" class-name="compact-col">
          <template #default="{ row }">
            <el-tag size="small" :type="row.ktype === 2 ? 'warning' : 'info'">{{ KTYPE_TEXT[row.ktype] || '-' }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="回复类型" width="110" class-name="compact-col">
          <template #default="{ row }">{{ MSGTYPE_TEXT[row.msgtype] || row.msgtype }}</template>
        </el-table-column>
        <el-table-column label="回复内容" min-width="200" show-overflow-tooltip>
          <template #default="{ row }">{{ contentPreview(row) }}</template>
        </el-table-column>
        <el-table-column prop="sort" label="排序" width="70" class-name="compact-col" />
        <el-table-column label="状态" width="80" class-name="compact-col">
          <template #default="{ row }">
            <el-tag v-if="row.status === 1" type="success" size="small">启用</el-tag>
            <el-tag v-else type="info" size="small">停用</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="创建时间" width="96" class-name="compact-col">
          <template #default="{ row }">
            <el-tooltip v-if="row.createtime" :content="formatTime(row.createtime)" placement="top">
              <span>{{ formatTime(row.createtime).slice(2, 10) }}</span>
            </el-tooltip>
            <span v-else>-</span>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="110" fixed="right">
          <template #default="{ row }">
            <el-button type="primary" link size="small" @click="openEdit(row)">编辑</el-button>
            <el-button type="danger" link size="small" @click="handleDel(row)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>

      <el-pagination
        class="pagination"
        background
        layout="total, sizes, prev, pager, next, jumper"
        :total="total"
        :current-page="query.page"
        :page-size="query.pagesize"
        :page-sizes="[10, 20, 50, 100]"
        @current-change="handlePageChange"
        @size-change="handleSizeChange"
      />
    </el-card>

    <!-- 新增/编辑弹窗 -->
    <el-dialog
      v-model="editVisible"
      :title="form.id ? '编辑关键字回复' : '新增关键字回复'"
      width="620px"
      :close-on-click-modal="false"
    >
      <el-form label-width="90px" @submit.prevent>
        <el-form-item label="关键字" required>
          <el-input v-model="form.keyword" placeholder="会员发送该关键字触发回复" maxlength="50" />
        </el-form-item>
        <el-form-item label="匹配方式">
          <el-radio-group v-model="form.ktype">
            <el-radio :value="0">全匹配</el-radio>
            <el-radio :value="1">半匹配</el-radio>
            <el-radio :value="2">关注回复</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item label="回复类型">
          <el-radio-group v-model="form.msgtype">
            <el-radio value="text">文本</el-radio>
            <el-radio value="image">图片</el-radio>
            <el-radio value="link">图文链接</el-radio>
            <el-radio value="miniprogrampage">小程序卡片</el-radio>
          </el-radio-group>
        </el-form-item>

        <!-- 文本 -->
        <el-form-item v-if="form.msgtype === 'text'" label="回复文本" required>
          <el-input v-model="form.text" type="textarea" :rows="4" placeholder="回复的文本内容" maxlength="600" show-word-limit />
        </el-form-item>

        <!-- 图片 -->
        <el-form-item v-if="form.msgtype === 'image'" label="回复图片" required>
          <div class="img-row">
            <img v-if="form.imagePicurl" :src="form.imagePicurl" class="thumb" alt="回复图片" />
            <el-button size="small" @click="openImgPicker((u) => (form.imagePicurl = u))">{{ form.imagePicurl ? '重新选择' : '选择图片' }}</el-button>
          </div>
        </el-form-item>

        <!-- 图文链接 -->
        <template v-if="form.msgtype === 'link'">
          <el-form-item label="标题" required>
            <el-input v-model="form.link.title" maxlength="60" />
          </el-form-item>
          <el-form-item label="描述">
            <el-input v-model="form.link.description" type="textarea" :rows="2" maxlength="200" />
          </el-form-item>
          <el-form-item label="链接" required>
            <el-input v-model="form.link.url" placeholder="https://" />
          </el-form-item>
          <el-form-item label="封面图">
            <div class="img-row">
              <img v-if="form.link.picurl" :src="form.link.picurl" class="thumb" alt="封面图" />
              <el-button size="small" @click="openImgPicker((u) => (form.link.picurl = u))">{{ form.link.picurl ? '重新选择' : '选择图片' }}</el-button>
            </div>
          </el-form-item>
        </template>

        <!-- 小程序卡片 -->
        <template v-if="form.msgtype === 'miniprogrampage'">
          <el-form-item label="标题" required>
            <el-input v-model="form.mini.title" maxlength="60" />
          </el-form-item>
          <el-form-item label="AppID" required>
            <el-input v-model="form.mini.appid" placeholder="目标小程序 AppID" />
          </el-form-item>
          <el-form-item label="页面路径" required>
            <el-input v-model="form.mini.pagepath" placeholder="例如：pages/index/index" />
          </el-form-item>
          <el-form-item label="封面图">
            <div class="img-row">
              <img v-if="form.mini.thumb" :src="form.mini.thumb" class="thumb" alt="卡片封面" />
              <el-button size="small" @click="openImgPicker((u) => (form.mini.thumb = u))">{{ form.mini.thumb ? '重新选择' : '选择图片' }}</el-button>
            </div>
          </el-form-item>
        </template>

        <el-form-item label="排序">
          <el-input-number v-model="form.sort" :min="0" :max="9999" />
        </el-form-item>
        <el-form-item label="状态">
          <el-switch v-model="form.status" :active-value="1" :inactive-value="0" active-text="启用" inactive-text="停用" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="editVisible = false">取消</el-button>
        <el-button type="primary" :loading="saving" @click="handleSave">保存</el-button>
      </template>
    </el-dialog>

    <ImgIconPicker v-model:visible="imgPickerVisible" default-tab="library" @pick="onImgPick" />
  </div>
</template>

<style scoped>
.toolbar-card {
  margin-bottom: 12px;
}

.img-row {
  display: flex;
  align-items: center;
  gap: 10px;
}

.thumb {
  width: 64px;
  height: 64px;
  object-fit: cover;
  border-radius: 4px;
  border: 1px solid #ebeef5;
}

.pagination {
  margin-top: 16px;
  justify-content: flex-end;
}

/* 紧凑列：收紧单元格左右内边距 */
:deep(.compact-col .cell) {
  padding-left: 6px;
  padding-right: 6px;
}
</style>
