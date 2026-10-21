<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  deletePlog,
  exportPlog,
  getPlogList,
  type PlogItem,
} from '../../api/system'
import { formatTime } from '../../utils/time'

// ============ 列表 ============
const loading = ref(false)
const tableData = ref<PlogItem[]>([])
const total = ref(0)
const userOptions = ref<{ id: number; un: string }[]>([])
const canFilter = ref(false)
const selectedIds = ref<number[]>([])

const query = reactive({
  page: 1,
  pagesize: 20,
  uid: '' as number | '',
  remark: '',
  range: [] as string[],
})

async function fetchList() {
  loading.value = true
  try {
    const data = await getPlogList({
      page: query.page,
      pagesize: query.pagesize,
      uid: query.uid === '' ? undefined : query.uid,
      remark: query.remark || undefined,
      start: query.range?.[0] || undefined,
      end: query.range?.[1] || undefined,
    })
    tableData.value = data.list || []
    total.value = data.total || 0
    userOptions.value = data.user_options || []
    canFilter.value = data.can_filter === 1
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

function handleReset() {
  query.uid = ''
  query.remark = ''
  query.range = []
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

function handleSelectionChange(rows: PlogItem[]) {
  selectedIds.value = rows.map((r) => r.id)
}

// ============ 删除（批量） ============
function handleDelete(ids: number[]) {
  if (!ids.length) {
    ElMessage.warning('请选择日志')
    return
  }
  ElMessageBox.confirm(`确定要删除选中的 ${ids.length} 条日志吗？删除后不可恢复。`, '删除确认', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await deletePlog(ids)
        ElMessage.success('删除成功')
        fetchList()
      } catch {
        // 错误提示已由 axios 拦截器统一弹出
      }
    })
    .catch(() => {})
}

// ============ 导出 CSV ============
const exporting = ref(false)
async function handleExport() {
  exporting.value = true
  try {
    const blob = await exportPlog({
      uid: query.uid === '' ? undefined : query.uid,
      remark: query.remark || undefined,
      start: query.range?.[0] || undefined,
      end: query.range?.[1] || undefined,
    })
    const url = URL.createObjectURL(blob instanceof Blob ? blob : new Blob([blob as unknown as BlobPart]))
    const a = document.createElement('a')
    a.href = url
    a.download = `操作日志_${new Date().toISOString().slice(0, 10)}.csv`
    a.click()
    URL.revokeObjectURL(url)
    ElMessage.success('导出成功')
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    exporting.value = false
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
        <el-form-item v-if="canFilter" label="账号">
          <el-select v-model="query.uid" placeholder="全部" clearable filterable style="width: 160px">
            <el-option
              v-for="u in userOptions"
              :key="u.id"
              :label="u.un"
              :value="u.id"
            />
          </el-select>
        </el-form-item>
        <el-form-item label="操作内容">
          <el-input
            v-model="query.remark"
            placeholder="操作内容关键词"
            clearable
            style="width: 200px"
            @keyup.enter="handleSearch"
          />
        </el-form-item>
        <el-form-item label="时间">
          <el-date-picker
            v-model="query.range"
            type="daterange"
            value-format="YYYY-MM-DD"
            range-separator="至"
            start-placeholder="开始日期"
            end-placeholder="结束日期"
            style="width: 240px"
          />
        </el-form-item>
        <el-form-item>
          <el-button type="primary" @click="handleSearch">搜索</el-button>
          <el-button @click="handleReset">重置</el-button>
        </el-form-item>
        <el-form-item style="float: right">
          <el-button :loading="exporting" @click="handleExport">导出</el-button>
          <el-button type="danger" @click="handleDelete(selectedIds)">批量删除</el-button>
        </el-form-item>
      </el-form>
    </el-card>

    <!-- 日志列表 -->
    <el-card shadow="never">
      <el-table
        v-loading="loading"
        :data="tableData"
        stripe
        @selection-change="handleSelectionChange"
      >
        <el-table-column type="selection" width="45" />
        <el-table-column prop="id" label="ID" width="80" />
        <el-table-column prop="un" label="账号" width="140" show-overflow-tooltip />
        <el-table-column label="操作时间" width="180">
          <template #default="{ row }">{{ formatTime(row.createtime) }}</template>
        </el-table-column>
        <el-table-column prop="remark" label="操作内容" min-width="240" show-overflow-tooltip />
        <el-table-column prop="ip" label="IP" width="140" />
        <el-table-column label="操作" width="90" fixed="right">
          <template #default="{ row }">
            <el-button link type="danger" @click="handleDelete([row.id])">删除</el-button>
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
        :page-sizes="[20, 50, 100]"
        @current-change="handlePageChange"
        @size-change="handleSizeChange"
      />
    </el-card>
  </div>
</template>

<style scoped>
.toolbar-card {
  margin-bottom: 16px;
}

.toolbar-card :deep(.el-card__body) {
  padding-bottom: 2px;
}

.pagination {
  margin-top: 16px;
  justify-content: flex-end;
}
</style>
