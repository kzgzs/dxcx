<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { delWxurl, getWxurlList, saveWxurl } from '../../api/wxext'
import type { WxurlItem, WxurlSaveInfo } from '../../api/wxext'
import { formatTime } from '../../utils/time'

const loading = ref(false)
const tableData = ref<WxurlItem[]>([])
const total = ref(0)

const query = reactive({
  page: 1,
  pagesize: 10,
  name: '',
  path: '',
  url: '',
})

async function fetchList() {
  loading.value = true
  try {
    const data = await getWxurlList({
      page: query.page,
      pagesize: query.pagesize,
      name: query.name || undefined,
      path: query.path || undefined,
      url: query.url || undefined,
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
  query.name = ''
  query.path = ''
  query.url = ''
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

// ===== 落地链接 =====
function landingUrl(row: WxurlItem): string {
  if (!row.code) return ''
  return `${location.origin}/api/wxurl/go?code=${row.code}`
}

async function copyText(text: string) {
  if (!text) return
  try {
    await navigator.clipboard.writeText(text)
    ElMessage.success('已复制')
  } catch {
    const ta = document.createElement('textarea')
    ta.value = text
    document.body.appendChild(ta)
    ta.select()
    document.execCommand('copy')
    document.body.removeChild(ta)
    ElMessage.success('已复制')
  }
}

// ===== 新增/编辑 =====
const editVisible = ref(false)
const saving = ref(false)
const form = reactive<WxurlSaveInfo>({
  id: 0,
  name: '',
  path: '',
  url: '',
  days: 30,
})

function openAdd() {
  form.id = 0
  form.name = ''
  form.path = ''
  form.url = ''
  form.days = 30
  editVisible.value = true
}

function openEdit(row: WxurlItem) {
  form.id = row.id
  form.name = row.name
  form.path = row.path
  form.url = row.url
  form.days = row.days
  editVisible.value = true
}

async function handleSave() {
  if (!form.name.trim()) {
    ElMessage.warning('请输入链接名称')
    return
  }
  if (!form.path.trim()) {
    ElMessage.warning('请输入小程序路径')
    return
  }
  saving.value = true
  try {
    await saveWxurl({ ...form })
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
async function handleDel(row: WxurlItem) {
  try {
    await ElMessageBox.confirm(`确定删除外部链接「${row.name}」吗？`, '提示', { type: 'warning' })
  } catch {
    return
  }
  try {
    await delWxurl([row.id])
    ElMessage.success('删除成功')
    fetchList()
  } catch {
    // 拦截器已提示
  }
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
        <el-form-item label="名称">
          <el-input v-model="query.name" placeholder="链接名称" clearable style="width: 150px" @keyup.enter="handleSearch" />
        </el-form-item>
        <el-form-item label="路径">
          <el-input v-model="query.path" placeholder="小程序路径" clearable style="width: 170px" @keyup.enter="handleSearch" />
        </el-form-item>
        <el-form-item label="链接">
          <el-input v-model="query.url" placeholder="外部链接" clearable style="width: 170px" @keyup.enter="handleSearch" />
        </el-form-item>
        <el-form-item>
          <el-button type="primary" @click="handleSearch">搜索</el-button>
          <el-button @click="handleReset">重置</el-button>
          <el-button type="success" @click="openAdd">新增链接</el-button>
        </el-form-item>
      </el-form>
    </el-card>

    <!-- 列表 -->
    <el-card shadow="never">
      <el-table v-loading="loading" :data="tableData" stripe border size="small">
        <el-table-column prop="name" label="名称" min-width="120" show-overflow-tooltip />
        <el-table-column prop="path" label="小程序路径" min-width="160" show-overflow-tooltip />
        <el-table-column prop="url" label="外部链接" min-width="160" show-overflow-tooltip>
          <template #default="{ row }">{{ row.url || '-' }}</template>
        </el-table-column>
        <el-table-column label="落地链接" min-width="200">
          <template #default="{ row }">
            <div v-if="row.code" class="landing-cell">
              <span class="landing-text">{{ landingUrl(row) }}</span>
              <el-button type="primary" link size="small" @click="copyText(landingUrl(row))">复制</el-button>
            </div>
            <span v-else>-</span>
          </template>
        </el-table-column>
        <el-table-column label="有效天数" prop="days" width="80" class-name="compact-col" />
        <el-table-column label="到期时间" width="96" class-name="compact-col">
          <template #default="{ row }">
            <el-tooltip v-if="row.endtime" :content="formatTime(row.endtime)" placement="top">
              <span>{{ formatTime(row.endtime).slice(2, 10) }}</span>
            </el-tooltip>
            <span v-else>-</span>
          </template>
        </el-table-column>
        <el-table-column label="状态" width="80" class-name="compact-col">
          <template #default="{ row }">
            <el-tag v-if="row.expired === 1" type="danger" size="small">已过期</el-tag>
            <el-tag v-else type="success" size="small">有效</el-tag>
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
      :title="form.id ? '编辑外部链接' : '新增外部链接'"
      width="540px"
      :close-on-click-modal="false"
    >
      <el-form label-width="100px" @submit.prevent>
        <el-form-item label="链接名称" required>
          <el-input v-model="form.name" placeholder="例如：首页活动" maxlength="50" />
        </el-form-item>
        <el-form-item label="小程序路径" required>
          <el-input v-model="form.path" placeholder="例如：/pagesExt/article/detail?id=27" />
        </el-form-item>
        <el-form-item label="外部链接">
          <el-input v-model="form.url" placeholder="落地页 302 跳转目标地址（可留空）" />
        </el-form-item>
        <el-form-item label="有效天数" required>
          <el-input-number v-model="form.days" :min="1" :max="365" />
          <span class="form-tip">自创建时间起计算到期时间</span>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="editVisible = false">取消</el-button>
        <el-button type="primary" :loading="saving" @click="handleSave">保存</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped>
.toolbar-card {
  margin-bottom: 12px;
}

.landing-cell {
  display: flex;
  align-items: center;
  gap: 4px;
}

.landing-text {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  max-width: 220px;
  color: #409eff;
}

.form-tip {
  margin-left: 10px;
  color: #909399;
  font-size: 12px;
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
