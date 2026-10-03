<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  deleteKechengChapter,
  getKechengChapterDetail,
  getKechengChapterList,
  getKechengCourseList,
  saveKechengChapter,
  setKechengChapterStatus,
} from '../../api/kecheng'
import type { KechengChapterItem, KechengCourseItem } from '../../api/kecheng'
import { formatTime } from '../../utils/time'
import { uploadFile } from '../../api/goods'
import { assetUrl } from '../../api/upload'
import ImgIconPicker from '../../components/ImgIconPicker.vue'
import GoodsDetailEditor from '../../components/GoodsDetailEditor.vue'
import type { DesignerModule } from '../../api/designer'
import { defaultModule } from './designerModules'

// ============ 跨片契约：嵌入课程列表时按课程过滤 + 返回头 ============
const props = defineProps<{ embeddedProid?: number | string }>()
const emit = defineEmits<{ (e: 'back'): void }>()

const embedded = computed(
  () => props.embeddedProid !== undefined && props.embeddedProid !== null && props.embeddedProid !== '',
)
const embeddedKcid = computed(() => (embedded.value ? Number(props.embeddedProid) : 0))

// ============ 课程下拉（独立菜单用） ============
const courses = ref<KechengCourseItem[]>([])
// 嵌入模式也需拉全量课程，供「所属课程」下拉改选其它课程（默认仍选中当前嵌入课程）
async function fetchCourses() {
  try {
    const data = await getKechengCourseList({ page: 1, pagesize: 500 })
    courses.value = data.list || []
  } catch {
    /* 拦截器已提示 */
  }
}

// ============ 筛选与列表 ============
const loading = ref(false)
const tableData = ref<KechengChapterItem[]>([])
const total = ref(0)
const page = ref(1)
const pagesize = ref(20)

const filterKcid = ref<number | undefined>(undefined)
const filterName = ref('')
const filterStatus = ref('')

async function fetchList() {
  loading.value = true
  try {
    const data = await getKechengChapterList({
      kcid: embedded.value ? embeddedKcid.value : filterKcid.value,
      name: embedded.value ? undefined : filterName.value || undefined,
      status: embedded.value ? undefined : filterStatus.value || undefined,
      page: page.value,
      pagesize: pagesize.value,
    })
    tableData.value = data.list || []
    total.value = data.total || 0
  } catch {
    /* 拦截器已提示 */
  } finally {
    loading.value = false
  }
}

function handleSearch() {
  page.value = 1
  fetchList()
}

// ============ 多选与批量 ============
const selectedRows = ref<KechengChapterItem[]>([])
const selectedIds = computed(() => selectedRows.value.map((r) => r.id))
function handleSelectionChange(rows: KechengChapterItem[]) {
  selectedRows.value = rows
}

async function handleBatchStatus(status: number) {
  if (!selectedIds.value.length) {
    ElMessage.warning('请先勾选章节')
    return
  }
  try {
    await setKechengChapterStatus(selectedIds.value, status)
    ElMessage.success('操作成功')
    fetchList()
  } catch {
    /* 拦截器已提示 */
  }
}

async function handleBatchDelete() {
  if (!selectedIds.value.length) {
    ElMessage.warning('请先勾选章节')
    return
  }
  try {
    await ElMessageBox.confirm(`确认删除选中的 ${selectedIds.value.length} 个章节？`, '提示', {
      type: 'warning',
    })
    await deleteKechengChapter(selectedIds.value)
    ElMessage.success('删除成功')
    fetchList()
  } catch {
    /* 取消或拦截器已提示 */
  }
}

async function handleToggleStatus(row: KechengChapterItem) {
  try {
    await setKechengChapterStatus([row.id], row.status === 1 ? 0 : 1)
    ElMessage.success('操作成功')
    fetchList()
  } catch {
    /* 拦截器已提示 */
  }
}

async function handleDelete(row: KechengChapterItem) {
  try {
    await ElMessageBox.confirm(`确定要删除章节「${row.name}」吗？`, '提示', { type: 'warning' })
    await deleteKechengChapter([row.id])
    ElMessage.success('删除成功')
    fetchList()
  } catch {
    /* 取消或拦截器已提示 */
  }
}

// ============ 查看链接 ============
const linkVisible = ref(false)
const linkText = ref('')
function handleViewLink(row: KechengChapterItem) {
  linkText.value = `activity/kecheng/mldetail?id=${row.id}&kcid=${row.kcid}`
  linkVisible.value = true
}
function copyLink() {
  navigator.clipboard?.writeText(linkText.value)
  ElMessage.success('链接已复制')
}

// ============ 平铺增改面板（+右上X） ============
const editing = ref(false)
const editingId = ref(0)
const activeTab = ref<'info' | 'detail'>('info')
const submitting = ref(false)

const form = reactive({
  id: 0,
  kcid: 0,
  name: '',
  pic: '',
  kctype: 1,
  detailModules: [defaultModule('richtext')] as DesignerModule[],
  voice_url: '',
  video_url: '',
  video_duration: '',
  ismianfei: 2,
  jumpurl: '',
  sort: 0,
  status: 1,
})

// detail 列（模块数组 JSON）还原为 DIY 模块数组；兼容旧富文本 HTML 包裹成富文本模块
function parseDetailModules(raw: unknown): DesignerModule[] {
  const str = typeof raw === 'string' ? raw.trim() : ''
  if (str) {
    try {
      const parsed = JSON.parse(str)
      if (Array.isArray(parsed)) return parsed as DesignerModule[]
    } catch {
      /* 落到包裹 */
    }
    const m = defaultModule('richtext')
    ;(m as Record<string, unknown>).content = str
    return [m]
  }
  return [defaultModule('richtext')]
}

function resetForm() {
  Object.assign(form, {
    id: 0,
    kcid: embedded.value ? embeddedKcid.value : filterKcid.value || 0,
    name: '',
    pic: '',
    kctype: 1,
    detailModules: [defaultModule('richtext')] as DesignerModule[],
    voice_url: '',
    video_url: '',
    video_duration: '',
    ismianfei: 2,
    jumpurl: '',
    sort: 0,
    status: 1,
  })
}

function openAdd() {
  resetForm()
  activeTab.value = 'info'
  editingId.value = 0
  editing.value = true
}

async function openEdit(row: KechengChapterItem) {
  editingId.value = row.id
  activeTab.value = 'info'
  try {
    const d = await getKechengChapterDetail(row.id)
    Object.assign(form, {
      id: d.id,
      kcid: d.kcid,
      name: d.name,
      pic: d.pic,
      kctype: d.kctype,
      detailModules: parseDetailModules(d.detail),
      voice_url: d.voice_url,
      video_url: d.video_url,
      video_duration: d.video_duration,
      ismianfei: d.ismianfei,
      jumpurl: d.jumpurl,
      sort: d.sort,
      status: d.status,
    })
    editing.value = true
  } catch {
    /* 拦截器已提示 */
  }
}

function closeEdit() {
  editing.value = false
}

// 图片选择
const picPickerVisible = ref(false)
function onPickPic(urls: string[]) {
  if (urls.length) form.pic = urls[0]
  picPickerVisible.value = false
}

// 视频/音频直传
const videoFileRef = ref<HTMLInputElement | null>(null)
const audioFileRef = ref<HTMLInputElement | null>(null)
const uploadingMedia = ref(false)

function triggerVideo() {
  videoFileRef.value?.click()
}
function triggerAudio() {
  audioFileRef.value?.click()
}
async function onMediaChange(e: Event, target: 'video' | 'audio') {
  const input = e.target as HTMLInputElement
  const file = input.files?.[0]
  input.value = ''
  if (!file) return
  uploadingMedia.value = true
  try {
    const res = await uploadFile(file)
    if (target === 'video') form.video_url = res.url
    else form.voice_url = res.url
    ElMessage.success('上传成功')
  } catch {
    /* 拦截器已提示 */
  } finally {
    uploadingMedia.value = false
  }
}

async function handleSubmit() {
  if (!form.name.trim()) {
    ElMessage.warning('请输入章节名称')
    return
  }
  if (!form.kcid) {
    ElMessage.warning('请选择所属课程')
    return
  }
  submitting.value = true
  try {
    await saveKechengChapter({
      id: form.id || undefined,
      kcid: form.kcid,
      name: form.name,
      pic: form.pic,
      kctype: form.kctype,
      detail: JSON.stringify(form.detailModules || []),
      voice_url: form.kctype === 2 ? form.voice_url : '',
      video_url: form.kctype === 3 ? form.video_url : '',
      video_duration: form.kctype === 1 ? '' : form.video_duration,
      ismianfei: form.ismianfei,
      jumpurl: form.jumpurl,
      sort: form.sort,
      status: form.status,
    })
    ElMessage.success('保存成功')
    editing.value = false
    fetchList()
  } catch {
    /* 拦截器已提示 */
  } finally {
    submitting.value = false
  }
}

onMounted(() => {
  fetchCourses()
  fetchList()
})
</script>

<template>
  <div>
    <!-- 嵌入返回头 -->
    <div v-if="embedded" class="back-bar" @click="emit('back')">← 返回课程列表</div>

    <!-- 平铺增改面板 -->
    <el-card v-if="editing" shadow="never" class="edit-card">
      <div class="edit-head">
        <span class="edit-title">✚ {{ editingId ? '编辑章节内容' : '添加章节内容' }}</span>
        <span class="edit-close" @click="closeEdit">✕</span>
      </div>
      <el-tabs v-model="activeTab" class="edit-tabs">
        <el-tab-pane label="章节信息" name="info">
          <el-form label-width="90px" label-position="right" class="edit-form">
            <el-form-item label="章节名称">
              <el-input v-model="form.name" placeholder="" style="max-width: 380px" />
            </el-form-item>
            <el-form-item label="所属课程">
              <el-select
                v-model="form.kcid"
                placeholder="-请选择-"
                style="max-width: 380px"
              >
                <el-option v-for="c in courses" :key="c.id" :label="c.name" :value="c.id" />
              </el-select>
            </el-form-item>
            <el-form-item label="章节类型">
              <el-radio-group v-model="form.kctype">
                <el-radio :value="1">图文</el-radio>
                <el-radio :value="2">音频</el-radio>
                <el-radio :value="3">视频</el-radio>
              </el-radio-group>
            </el-form-item>
            <el-form-item label="章节图片">
              <el-button @click="picPickerVisible = true">上传图片</el-button>
              <span class="form-tip">建议尺寸：640x640像素</span>
              <div v-if="form.pic" class="pic-preview">
                <img :src="assetUrl(form.pic)" alt="封面" />
              </div>
            </el-form-item>
            <el-form-item v-if="form.kctype === 3" label="视频">
              <div class="media-row">
                <el-input v-model="form.video_url" placeholder="填写视频链接" style="width: 320px" />
                <el-button :loading="uploadingMedia" @click="triggerVideo">上传视频</el-button>
              </div>
              <div class="form-tip tip-block">
                填写视频链接或上传视频，视频链接必须为mp4格式的源链接，视频大小须在50MB以内
              </div>
              <video v-if="form.video_url" :src="assetUrl(form.video_url)" controls class="media-preview" />
            </el-form-item>
            <el-form-item v-if="form.kctype === 2" label="音频">
              <div class="media-row">
                <el-input v-model="form.voice_url" placeholder="填写音频链接" style="width: 320px" />
                <el-button :loading="uploadingMedia" @click="triggerAudio">上传音频</el-button>
              </div>
              <div class="form-tip tip-block">请上传mp3格式的音频，不大于2M</div>
              <audio v-if="form.voice_url" :src="assetUrl(form.voice_url)" controls class="audio-preview" />
            </el-form-item>
            <el-form-item v-if="form.kctype === 3" label="视频时长">
              <el-input v-model="form.video_duration" placeholder="" style="width: 160px" />
              <span class="form-tip">秒</span>
            </el-form-item>
            <el-form-item v-if="form.kctype === 2" label="音频时长">
              <el-input v-model="form.video_duration" placeholder="" style="width: 160px" />
              <span class="form-tip">秒</span>
            </el-form-item>
            <el-form-item label="免费试看">
              <el-radio-group v-model="form.ismianfei">
                <el-radio :value="1">开启</el-radio>
                <el-radio :value="2">关闭</el-radio>
              </el-radio-group>
              <span class="form-tip">开启后可免费试看此章节</span>
            </el-form-item>
            <el-form-item label="跳转链接">
              <el-input v-model="form.jumpurl" placeholder="" style="max-width: 380px" />
              <span class="form-tip">设置后学习时会跳转</span>
            </el-form-item>
            <el-form-item label="序号">
              <el-input v-model="form.sort" placeholder="0" style="width: 160px" />
              <span class="form-tip">用于排序，越大越靠前</span>
            </el-form-item>
            <el-form-item label="状态">
              <el-radio-group v-model="form.status">
                <el-radio :value="1">已上架</el-radio>
                <el-radio :value="0">未上架</el-radio>
              </el-radio-group>
            </el-form-item>
            <el-form-item>
              <el-button @click="activeTab = 'detail'">下一步-编辑章节详情</el-button>
              <el-button type="primary" :loading="submitting" @click="handleSubmit">提交</el-button>
            </el-form-item>
          </el-form>
        </el-tab-pane>
        <el-tab-pane label="详情" name="detail">
          <GoodsDetailEditor v-model="form.detailModules" title="章节详情" />
          <div class="edit-actions">
            <el-button @click="activeTab = 'info'">上一步-编辑章节信息</el-button>
            <el-button type="primary" :loading="submitting" @click="handleSubmit">提交</el-button>
          </div>
        </el-tab-pane>
      </el-tabs>
    </el-card>

    <!-- 列表 -->
    <template v-else>
      <el-card shadow="never">
        <div class="toolbar-row">
          <div class="tb-left">
            <el-button type="primary" @click="openAdd">添加</el-button>
            <el-button @click="handleBatchDelete">删除</el-button>
            <el-button @click="handleBatchStatus(1)">开启</el-button>
            <el-button @click="handleBatchStatus(0)">关闭</el-button>
          </div>
          <div class="tb-right">
            <template v-if="!embedded">
              <span class="tb-label">所属课程</span>
              <el-select v-model="filterKcid" placeholder="全部" clearable style="width: 150px" @change="handleSearch">
                <el-option v-for="c in courses" :key="c.id" :label="c.name" :value="c.id" />
              </el-select>
              <span class="tb-label">章节名称</span>
              <el-input v-model="filterName" style="width: 150px" @keyup.enter="handleSearch" />
              <span class="tb-label">状态</span>
              <el-select v-model="filterStatus" placeholder="全部" clearable style="width: 100px" @change="handleSearch">
                <el-option label="已上架" value="1" />
                <el-option label="未上架" value="0" />
              </el-select>
            </template>
            <el-button type="primary" @click="handleSearch">🔍</el-button>
          </div>
        </div>

        <el-table v-loading="loading" :data="tableData" @selection-change="handleSelectionChange">
          <el-table-column type="selection" width="40" />
          <el-table-column prop="id" label="ID" width="70" sortable />
          <el-table-column label="课程信息" min-width="220">
            <template #default="{ row }">
              <div class="course-cell">
                <img v-if="row.pic" :src="assetUrl(row.pic)" class="course-thumb" alt="" />
                <span class="course-name">{{ row.name }}</span>
              </div>
            </template>
          </el-table-column>
          <el-table-column prop="kcname" label="所属课程" min-width="140" show-overflow-tooltip />
          <el-table-column prop="zjtype" label="章节类型" width="90" />
          <el-table-column prop="sort" label="序号" width="80" sortable />
          <el-table-column label="创建时间" width="150">
            <template #default="{ row }">{{ formatTime(row.createtime) }}</template>
          </el-table-column>
          <el-table-column label="状态" width="90">
            <template #default="{ row }">
              <span :class="row.status === 1 ? 'st-on' : 'st-off'">{{ row.status === 1 ? '已上架' : '未上架' }}</span>
            </template>
          </el-table-column>
          <el-table-column label="操作" width="220">
            <template #default="{ row }">
              <div class="row-ops">
                <el-button link type="primary" @click="handleViewLink(row)">查看链接</el-button>
                <el-button link type="primary" @click="openEdit(row)">编辑</el-button>
                <el-button link type="primary" @click="handleDelete(row)">删除</el-button>
                <el-button link type="primary" @click="handleToggleStatus(row)">
                  {{ row.status === 1 ? '关闭' : '开启' }}
                </el-button>
              </div>
            </template>
          </el-table-column>
        </el-table>

        <el-pagination
          class="pager"
          v-model:current-page="page"
          v-model:page-size="pagesize"
          :total="total"
          :page-sizes="[20, 50, 100]"
          layout="total, sizes, prev, pager, next"
          @current-change="fetchList"
          @size-change="handleSearch"
        />
      </el-card>
    </template>

    <!-- 图片选择 -->
    <ImgIconPicker v-model:visible="picPickerVisible" @pick="onPickPic" />

    <!-- 查看链接 -->
    <el-dialog v-model="linkVisible" title="链接地址" width="480px" append-to-body>
      <div style="word-break: break-all">{{ linkText }}</div>
      <template #footer>
        <el-button type="primary" @click="copyLink">复制</el-button>
      </template>
    </el-dialog>

    <!-- 隐藏文件选择 -->
    <input ref="videoFileRef" type="file" accept="video/*" style="display: none" @change="onMediaChange($event, 'video')" />
    <input ref="audioFileRef" type="file" accept="audio/mp3,audio/*" style="display: none" @change="onMediaChange($event, 'audio')" />
  </div>
</template>

<style scoped>
.back-bar {
  cursor: pointer;
  color: #409eff;
  font-size: 14px;
  padding: 6px 2px;
  margin-bottom: 3px;
}

.back-bar:hover {
  text-decoration: underline;
}

.toolbar-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: nowrap;
  gap: 8px;
}

.tb-left,
.tb-right {
  display: flex;
  align-items: center;
  gap: 6px;
  flex-wrap: nowrap;
}

.tb-label {
  font-size: 13px;
  color: #606266;
  white-space: nowrap;
}

.pager {
  margin-top: 6px;
  justify-content: flex-end;
}

.course-cell {
  display: flex;
  align-items: center;
  gap: 8px;
}

.course-thumb {
  width: 40px;
  height: 40px;
  object-fit: cover;
  border-radius: 4px;
  background: #f5f7fa;
}

.course-name {
  font-size: 13px;
}

.st-on {
  color: #67c23a;
}

.st-off {
  color: #f56c6c;
}

/* 平铺编辑面板 */
.edit-card {
  position: relative;
}

.edit-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding-bottom: 8px;
  border-bottom: 1px solid #ebeef5;
  margin-bottom: 8px;
}

.edit-title {
  font-size: 15px;
  font-weight: 600;
  color: #303133;
}

.edit-close {
  cursor: pointer;
  font-size: 18px;
  color: #909399;
}

.edit-close:hover {
  color: #f56c6c;
}

.edit-form :deep(.el-form-item) {
  margin-bottom: 14px;
}

.media-row {
  display: flex;
  align-items: center;
  gap: 8px;
}

.form-tip {
  margin-left: 8px;
  color: #909399;
  font-size: 12px;
}

.tip-block {
  margin-left: 0;
  margin-top: 4px;
}

.pic-preview {
  margin-top: 8px;
}

.pic-preview img {
  width: 90px;
  height: 90px;
  object-fit: cover;
  border: 1px solid #ebeef5;
  border-radius: 4px;
}

.media-preview {
  margin-top: 8px;
  width: 240px;
  max-height: 160px;
  background: #000;
}

.audio-preview {
  margin-top: 8px;
  width: 320px;
}

.edit-actions {
  margin-top: 16px;
}
</style>
