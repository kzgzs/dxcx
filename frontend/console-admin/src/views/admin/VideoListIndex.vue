<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import type { FormInstance, FormRules } from 'element-plus'
import { deleteVideo, getVideoList, saveVideo } from '../../api/videolist'
import type { VideoListItem } from '../../api/videolist'
import ImgIconPicker from '../../components/ImgIconPicker.vue'

const TYPE_MAP: Record<number, string> = { 0: '本地视频', 1: '微信视频号' }

// ============ 列表 ============
const loading = ref(false)
const list = ref<VideoListItem[]>([])
const total = ref(0)
const query = reactive({ page: 1, pagesize: 10, name: '', type: '' })

async function fetchList() {
  loading.value = true
  try {
    const res = await getVideoList({
      page: query.page,
      pagesize: query.pagesize,
      name: query.name,
      type: query.type,
    })
    list.value = res.list || []
    total.value = res.total || 0
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    loading.value = false
  }
}

function handleSearch() {
  query.page = 1
  fetchList()
}

function formatTime(ts: number) {
  if (!ts) return '-'
  const d = new Date(ts * 1000)
  const p = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}`
}

// ============ 新增 / 编辑弹窗 ============
const dialogVisible = ref(false)
const isEdit = ref(false)
const formRef = ref<FormInstance>()
const submitting = ref(false)

const form = reactive({
  id: 0,
  name: '',
  pic: '',
  type: 0,
  video_url: '',
  video_duration: '',
  status: 1,
  sort: 0,
  feedtype: '',
  finderuser: '',
  feedid: '',
  feedtoken: '',
})

const rules: FormRules = {
  name: [{ required: true, message: '请输入视频名称', trigger: 'blur' }],
}

function resetForm() {
  Object.assign(form, {
    id: 0, name: '', pic: '', type: 0, video_url: '', video_duration: '',
    status: 1, sort: 0, feedtype: '', finderuser: '', feedid: '', feedtoken: '',
  })
}

function openAdd() {
  isEdit.value = false
  resetForm()
  dialogVisible.value = true
}

function openEdit(row: VideoListItem) {
  isEdit.value = true
  resetForm()
  Object.assign(form, {
    id: row.id,
    name: row.name,
    pic: row.pic || '',
    type: Number(row.type),
    video_url: row.video_url || '',
    video_duration: row.video_duration || '',
    status: Number(row.status),
    sort: Number(row.sort) || 0,
    feedtype: row.ext?.feedtype || '',
    finderuser: row.ext?.finderuser || '',
    feedid: row.ext?.feedid || '',
    feedtoken: row.ext?.feedtoken || '',
  })
  dialogVisible.value = true
}

// ============ 封面图片选择（任务294：ImgIconPicker，默认素材库） ============
const imgPickerVisible = ref(false)

function openImgPicker() {
  imgPickerVisible.value = true
}

function onImgPick(urls: string[]) {
  if (urls.length) form.pic = urls[0]
}

async function handleSubmit() {
  if (!formRef.value) return
  await formRef.value.validate(async (valid) => {
    if (!valid) return
    submitting.value = true
    try {
      await saveVideo({
        id: form.id || undefined,
        name: form.name,
        pic: form.pic,
        type: form.type,
        video_url: form.video_url,
        video_duration: form.video_duration,
        status: form.status,
        sort: form.sort,
        ext: form.type === 1
          ? { feedtype: form.feedtype, finderuser: form.finderuser, feedid: form.feedid, feedtoken: form.feedtoken }
          : {},
      })
      ElMessage.success('保存成功')
      dialogVisible.value = false
      fetchList()
    } catch {
      // 错误提示已由 axios 拦截器统一弹出
    } finally {
      submitting.value = false
    }
  })
}

function handleDelete(row: VideoListItem) {
  ElMessageBox.confirm(`确定要删除视频「${row.name}」吗？`, '删除确认', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await deleteVideo([row.id])
        ElMessage.success('删除成功')
        fetchList()
      } catch {
        // 错误提示已由 axios 拦截器统一弹出
      }
    })
    .catch(() => {})
}

onMounted(fetchList)
</script>

<template>
  <div>
    <el-card shadow="never">
      <template #header>
        <div class="card-header">
          <span>视频管理</span>
          <div>
            <el-input
              v-model="query.name"
              placeholder="名称"
              clearable
              style="width: 160px; margin-right: 8px"
              @keyup.enter="handleSearch"
              @clear="handleSearch"
            />
            <el-select v-model="query.type" placeholder="类型" clearable style="width: 130px; margin-right: 8px" @change="handleSearch">
              <el-option value="0" label="本地视频" />
              <el-option value="1" label="微信视频号" />
            </el-select>
            <el-button @click="handleSearch">搜索</el-button>
            <el-button type="success" @click="openAdd">新增视频</el-button>
          </div>
        </div>
      </template>

      <el-table v-loading="loading" :data="list" stripe row-key="id">
        <el-table-column prop="id" label="ID" width="70" />
        <el-table-column label="封面" width="110">
          <template #default="{ row }">
            <el-image v-if="row.pic" :src="row.pic" class="cover-img" fit="cover" :preview-src-list="[row.pic]" />
            <span v-else class="text-muted">-</span>
          </template>
        </el-table-column>
        <el-table-column prop="name" label="名称" min-width="180" />
        <el-table-column label="类型" width="110">
          <template #default="{ row }">
            <el-tag :type="Number(row.type) === 0 ? 'info' : 'success'" size="small">{{ TYPE_MAP[Number(row.type)] || '-' }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="状态" width="90">
          <template #default="{ row }">
            <el-tag :type="Number(row.status) === 1 ? 'success' : 'info'" size="small">
              {{ Number(row.status) === 1 ? '显示' : '隐藏' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="sort" label="排序" width="70" />
        <el-table-column label="创建时间" width="150">
          <template #default="{ row }">{{ formatTime(row.createtime) }}</template>
        </el-table-column>
        <el-table-column label="操作" width="150" fixed="right">
          <template #default="{ row }">
            <el-button link type="primary" @click="openEdit(row)">编辑</el-button>
            <el-button link type="danger" @click="handleDelete(row)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>

      <el-pagination
        v-if="total > 0"
        class="pager"
        v-model:current-page="query.page"
        v-model:page-size="query.pagesize"
        :total="total"
        layout="total, prev, pager, next"
        @current-change="fetchList"
      />
    </el-card>

    <!-- 新增 / 编辑弹窗 -->
    <el-dialog v-model="dialogVisible" :title="isEdit ? '编辑视频' : '新增视频'" width="600px">
      <el-form ref="formRef" :model="form" :rules="rules" label-width="100px" label-position="left">
        <el-form-item label="名称" prop="name">
          <el-input v-model="form.name" placeholder="请输入视频名称" maxlength="50" />
        </el-form-item>
        <el-form-item label="封面">
          <div class="img-pick-row">
            <el-image v-if="form.pic" :src="form.pic" class="img-pick-thumb" fit="cover" />
            <el-button size="small" @click="openImgPicker">{{ form.pic ? '更换' : '选择图片' }}</el-button>
            <el-button v-if="form.pic" size="small" link type="danger" @click="form.pic = ''">删除</el-button>
          </div>
        </el-form-item>
        <el-form-item label="类型">
          <el-radio-group v-model="form.type">
            <el-radio :value="0">本地视频</el-radio>
            <el-radio :value="1">微信视频号</el-radio>
          </el-radio-group>
        </el-form-item>

        <template v-if="form.type === 0">
          <el-form-item label="视频链接">
            <el-input v-model="form.video_url" placeholder="mp4 视频链接" />
          </el-form-item>
          <el-form-item label="时长(秒)">
            <el-input v-model="form.video_duration" placeholder="视频时长（秒）" style="width: 200px" />
          </el-form-item>
        </template>

        <template v-else>
          <el-form-item label="视频号类型">
            <el-input v-model="form.feedtype" placeholder="feedtype" />
          </el-form-item>
          <el-form-item label="视频号用户">
            <el-input v-model="form.finderuser" placeholder="finderuser" />
          </el-form-item>
          <el-form-item label="视频ID">
            <el-input v-model="form.feedid" placeholder="feedid" />
          </el-form-item>
          <el-form-item label="视频Token">
            <el-input v-model="form.feedtoken" placeholder="feedtoken" />
          </el-form-item>
        </template>

        <el-form-item label="状态">
          <el-switch v-model="form.status" :active-value="1" :inactive-value="0" />
        </el-form-item>
        <el-form-item label="排序">
          <el-input-number v-model="form.sort" :min="0" :precision="0" :step="1" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="submitting" @click="handleSubmit">保存</el-button>
      </template>
    </el-dialog>

    <!-- 选图弹窗（任务294） -->
    <ImgIconPicker
      v-model:visible="imgPickerVisible"
      default-tab="library"
      @pick="(urls: string[]) => onImgPick(urls)"
    />
  </div>
</template>

<style scoped>
.card-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.pager {
  margin-top: 12px;
  justify-content: flex-end;
}

.cover-img {
  width: 90px;
  height: 60px;
  border-radius: 4px;
}

.img-pick-row {
  display: flex;
  align-items: center;
  gap: 10px;
}

.img-pick-thumb {
  width: 90px;
  height: 60px;
  border-radius: 4px;
  border: 1px solid #ebeef5;
}

.text-muted {
  color: #909399;
}
</style>
