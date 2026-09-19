<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import type { FormInstance, FormRules } from 'element-plus'
import {
  deleteKechengChapter,
  getKechengChapterList,
  getKechengCourseList,
  saveKechengChapter,
} from '../../api/kecheng'
import type { KechengChapterItem, KechengCourseItem } from '../../api/kecheng'
import { formatTime } from '../../utils/time'

// ============ 课程选择 ============
const courses = ref<KechengCourseItem[]>([])
const currentKcid = ref<number | undefined>(undefined)

const currentCourse = computed(
  () => courses.value.find((v) => v.id === currentKcid.value) || null,
)

async function fetchCourses() {
  try {
    const data = await getKechengCourseList({ page: 1, pagesize: 500 })
    courses.value = data.list || []
    if (!currentKcid.value && courses.value.length > 0) {
      currentKcid.value = courses.value[0].id
      fetchList()
    }
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  }
}

// ============ 列表 ============
const loading = ref(false)
const tableData = ref<KechengChapterItem[]>([])
const total = ref(0)
const page = ref(1)
const pagesize = ref(20)

async function fetchList() {
  if (!currentKcid.value) return
  loading.value = true
  try {
    const data = await getKechengChapterList({
      kcid: currentKcid.value,
      page: page.value,
      pagesize: pagesize.value,
    })
    tableData.value = data.list || []
    total.value = data.total || 0
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    loading.value = false
  }
}

function handleCourseChange() {
  page.value = 1
  fetchList()
}

const kctypeText: Record<number, string> = { 1: '图文', 2: '音频', 3: '视频' }

// ============ 删除 ============
function handleDelete(row: KechengChapterItem) {
  ElMessageBox.confirm(`确定要删除章节「${row.name}」吗？`, '删除确认', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await deleteKechengChapter(row.id)
        ElMessage.success('删除成功')
        fetchList()
      } catch {
        // 错误提示已由 axios 拦截器统一弹出
      }
    })
    .catch(() => {})
}

// ============ 新增 / 编辑弹窗 ============
const dialogVisible = ref(false)
const isEdit = ref(false)
const formRef = ref<FormInstance>()
const submitting = ref(false)

const form = reactive({
  id: 0,
  kcid: 0,
  parent_id: 0,
  name: '',
  pic: '',
  kctype: 1,
  detail: '',
  voice_url: '',
  video_url: '',
  video_duration: '',
  ismianfei: 2,
  isjinzhi: 0,
  jumpurl: '',
  give_score: 0,
  sort: 0,
  status: 1,
})

const rules: FormRules = {
  name: [{ required: true, message: '请输入章节名称', trigger: 'blur' }],
}

const parentOptions = computed(() =>
  [{ id: 0, name: '顶级章节' } as { id: number; name: string }].concat(
    tableData.value.map((v) => ({ id: v.id, name: v.name })),
  ),
)

function openAdd() {
  if (!currentKcid.value) {
    ElMessage.warning('请先选择课程')
    return
  }
  isEdit.value = false
  Object.assign(form, {
    id: 0,
    kcid: currentKcid.value,
    parent_id: 0,
    name: '',
    pic: '',
    kctype: 1,
    detail: '',
    voice_url: '',
    video_url: '',
    video_duration: '',
    ismianfei: 2,
    isjinzhi: 0,
    jumpurl: '',
    give_score: 0,
    sort: 0,
    status: 1,
  })
  dialogVisible.value = true
}

function openEdit(row: KechengChapterItem) {
  isEdit.value = true
  Object.assign(form, {
    id: row.id,
    kcid: row.kcid,
    parent_id: row.parent_id,
    name: row.name,
    pic: row.pic,
    kctype: row.kctype,
    detail: row.detail,
    voice_url: row.voice_url,
    video_url: row.video_url,
    video_duration: row.video_duration,
    ismianfei: row.ismianfei,
    isjinzhi: row.isjinzhi,
    jumpurl: row.jumpurl,
    give_score: row.give_score,
    sort: row.sort,
    status: row.status,
  })
  dialogVisible.value = true
}

async function handleSubmit() {
  if (!formRef.value) return
  await formRef.value.validate(async (valid) => {
    if (!valid) return
    submitting.value = true
    try {
      await saveKechengChapter({ ...form })
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

onMounted(fetchCourses)
</script>

<template>
  <div>
    <!-- 工具栏 -->
    <el-card shadow="never" class="toolbar-card">
      <el-form inline @submit.prevent>
        <el-form-item label="所属课程">
          <el-select
            v-model="currentKcid"
            placeholder="请选择课程"
            filterable
            style="width: 260px"
            @change="handleCourseChange"
          >
            <el-option v-for="c in courses" :key="c.id" :label="c.name" :value="c.id" />
          </el-select>
        </el-form-item>
        <el-form-item style="float: right">
          <el-button type="success" @click="openAdd">新增章节</el-button>
        </el-form-item>
      </el-form>
    </el-card>

    <!-- 章节列表 -->
    <el-card shadow="never">
      <el-table v-loading="loading" :data="tableData" stripe>
        <el-table-column prop="id" label="ID" width="70" />
        <el-table-column prop="name" label="章节名称" min-width="180" show-overflow-tooltip />
        <el-table-column label="类型" width="80">
          <template #default="{ row }">
            <el-tag size="small">{{ kctypeText[row.kctype] || '-' }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="免费试看" width="90">
          <template #default="{ row }">
            <el-tag :type="row.ismianfei === 1 ? 'success' : 'info'" size="small">
              {{ row.ismianfei === 1 ? '免费' : '付费' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="禁快进" width="80">
          <template #default="{ row }">{{ row.isjinzhi === 1 ? '是' : '否' }}</template>
        </el-table-column>
        <el-table-column label="学习送积分" width="100">
          <template #default="{ row }">{{ row.give_score || 0 }}</template>
        </el-table-column>
        <el-table-column prop="sort" label="排序" width="80" />
        <el-table-column label="状态" width="80">
          <template #default="{ row }">
            <el-tag :type="row.status === 1 ? 'success' : 'info'" size="small">
              {{ row.status === 1 ? '显示' : '隐藏' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="创建时间" width="160">
          <template #default="{ row }">{{ formatTime(row.createtime) }}</template>
        </el-table-column>
        <el-table-column label="操作" width="130" fixed="right">
          <template #default="{ row }">
            <el-button link type="primary" @click="openEdit(row)">编辑</el-button>
            <el-button link type="danger" @click="handleDelete(row)">删除</el-button>
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
      />
    </el-card>

    <!-- 新增 / 编辑弹窗 -->
    <el-dialog v-model="dialogVisible" :title="isEdit ? '编辑章节' : '新增章节'" width="620px" top="6vh">
      <el-form ref="formRef" :model="form" :rules="rules" label-width="110px" label-position="left">
        <el-form-item label="所属课程">
          <el-input :model-value="currentCourse?.name || ''" disabled />
        </el-form-item>
        <el-form-item label="上级章节">
          <el-select v-model="form.parent_id" style="width: 100%">
            <el-option
              v-for="p in parentOptions"
              :key="p.id"
              :label="p.name"
              :value="p.id"
              :disabled="p.id === form.id"
            />
          </el-select>
        </el-form-item>
        <el-form-item label="章节名称" prop="name">
          <el-input v-model="form.name" placeholder="请输入章节名称" />
        </el-form-item>
        <el-form-item label="章节类型">
          <el-radio-group v-model="form.kctype">
            <el-radio :value="1">图文</el-radio>
            <el-radio :value="2">音频</el-radio>
            <el-radio :value="3">视频</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item v-if="form.kctype === 1" label="图文内容">
          <el-input v-model="form.detail" type="textarea" :rows="5" placeholder="支持富文本 HTML" />
        </el-form-item>
        <el-form-item v-if="form.kctype === 2" label="音频地址">
          <el-input v-model="form.voice_url" placeholder="音频文件地址" />
        </el-form-item>
        <el-form-item v-if="form.kctype === 3" label="视频地址">
          <el-input v-model="form.video_url" placeholder="视频文件地址" />
        </el-form-item>
        <el-form-item v-if="form.kctype === 3" label="视频时长(秒)">
          <el-input v-model="form.video_duration" placeholder="用于计算学习进度" />
        </el-form-item>
        <el-form-item label="封面图">
          <el-input v-model="form.pic" placeholder="图片地址（可选）" />
        </el-form-item>
        <el-form-item label="免费试看">
          <el-switch v-model="form.ismianfei" :active-value="1" :inactive-value="2" />
          <span class="form-tip">开启后未购买也可观看本章节</span>
        </el-form-item>
        <el-form-item label="禁止快进">
          <el-switch v-model="form.isjinzhi" :active-value="1" :inactive-value="0" />
        </el-form-item>
        <el-form-item label="学习送积分">
          <el-input-number v-model="form.give_score" :min="0" :precision="0" :step="1" />
        </el-form-item>
        <el-form-item label="跳转链接">
          <el-input v-model="form.jumpurl" placeholder="自定义跳转链接（可选）" />
        </el-form-item>
        <el-form-item label="排序">
          <el-input-number v-model="form.sort" :min="0" :precision="0" :step="1" />
        </el-form-item>
        <el-form-item label="状态">
          <el-switch v-model="form.status" :active-value="1" :inactive-value="0" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="submitting" @click="handleSubmit">确定</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped>
.toolbar-card {
  margin-bottom: 16px;
}

.toolbar-card :deep(.el-card__body) {
  padding-bottom: 2px;
}

.pager {
  margin-top: 16px;
  justify-content: flex-end;
}

.form-tip {
  margin-left: 12px;
  color: #909399;
  font-size: 12px;
}
</style>
