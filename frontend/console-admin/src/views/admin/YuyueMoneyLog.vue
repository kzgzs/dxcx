<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  deleteYuyueMoneyLog,
  exportYuyueMoneyLog,
  getYuyueMoneyLogList,
} from '../../api/yuyue'
import type { YuyueMoneyLogItem } from '../../api/types'
import { formatTime } from '../../utils/time'

// 预约 - 提成明细（任务222，对照参考系统 YuyueMoney::moneylog）
const loading = ref(false)
const exporting = ref(false)
const tableData = ref<YuyueMoneyLogItem[]>([])
const total = ref(0)

const query = reactive({
  page: 1,
  pagesize: 10,
  realname: '',
  uid: '',
})

async function fetchList() {
  loading.value = true
  try {
    const data = await getYuyueMoneyLogList({
      page: query.page,
      pagesize: query.pagesize,
      realname: query.realname || undefined,
      uid: query.uid ? Number(query.uid) : undefined,
    })
    tableData.value = data.list || []
    total.value = data.total || 0
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
  query.realname = ''
  query.uid = ''
  query.page = 1
  fetchList()
}

// ============ 导出 CSV ============
function csvEscape(val: string): string {
  if (/[",\n]/.test(val)) return `"${val.replace(/"/g, '""')}"`
  return val
}

async function handleExport() {
  exporting.value = true
  try {
    const data = await exportYuyueMoneyLog({
      realname: query.realname || undefined,
      uid: query.uid ? Number(query.uid) : undefined,
    })
    if (!data.count) {
      ElMessage.warning('没有可导出的数据')
      return
    }
    const lines = [data.title, ...data.data].map((row) => row.map((c) => csvEscape(String(c))).join(','))
    const blob = new Blob(['\uFEFF' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8' })
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = `预约提成明细_${new Date().toISOString().slice(0, 10)}.csv`
    a.click()
    URL.revokeObjectURL(url)
    ElMessage.success(`已导出 ${data.count} 条记录`)
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    exporting.value = false
  }
}

// ============ 删除 ============
function handleDelete(row: YuyueMoneyLogItem) {
  ElMessageBox.confirm('确定要删除这条提成记录吗？', '删除确认', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await deleteYuyueMoneyLog([row.id])
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
    <!-- 搜索工具栏 -->
    <el-card shadow="never" class="toolbar-card">
      <el-form inline @submit.prevent>
        <el-form-item label="姓名">
          <el-input v-model="query.realname" placeholder="人员姓名" clearable style="width: 140px" @keyup.enter="handleSearch" />
        </el-form-item>
        <el-form-item label="人员ID">
          <el-input v-model="query.uid" placeholder="ID" clearable style="width: 100px" @keyup.enter="handleSearch" />
        </el-form-item>
        <el-form-item>
          <el-button type="primary" @click="handleSearch">搜索</el-button>
          <el-button @click="handleReset">重置</el-button>
        </el-form-item>
        <el-form-item style="float: right">
          <el-button type="success" :loading="exporting" @click="handleExport">导出</el-button>
        </el-form-item>
      </el-form>
    </el-card>

    <!-- 列表 -->
    <el-card shadow="never">
      <el-table v-loading="loading" :data="tableData" stripe>
        <el-table-column prop="id" label="ID" width="70" />
        <el-table-column label="人员" min-width="130" show-overflow-tooltip>
          <template #default="{ row }">
            {{ row.realname || `#${row.uid}` }}
            <span v-if="row.tel" class="sub-text">{{ row.tel }}</span>
          </template>
        </el-table-column>
        <el-table-column label="变动金额" width="110">
          <template #default="{ row }">
            <span :class="Number(row.money) >= 0 ? 'money-plus' : 'money-minus'">
              {{ Number(row.money) >= 0 ? '+' : '' }}{{ Number(row.money).toFixed(2) }}
            </span>
          </template>
        </el-table-column>
        <el-table-column label="变动后余额" width="110">
          <template #default="{ row }">￥{{ Number(row.after).toFixed(2) }}</template>
        </el-table-column>
        <el-table-column prop="remark" label="备注" min-width="180" show-overflow-tooltip>
          <template #default="{ row }">{{ row.remark || '-' }}</template>
        </el-table-column>
        <el-table-column label="时间" width="160">
          <template #default="{ row }">{{ formatTime(row.createtime) }}</template>
        </el-table-column>
        <el-table-column label="操作" width="90" fixed="right">
          <template #default="{ row }">
            <el-button link type="danger" @click="handleDelete(row)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>

      <el-pagination
        v-if="total > 0"
        class="pager"
        background
        layout="total, sizes, prev, pager, next"
        :total="total"
        v-model:current-page="query.page"
        v-model:page-size="query.pagesize"
        :page-sizes="[10, 20, 50]"
        @current-change="fetchList"
        @size-change="handleSearch"
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

.pager {
  margin-top: 16px;
  justify-content: flex-end;
}

.sub-text {
  color: #909399;
  font-size: 12px;
  margin-left: 4px;
}

.money-plus {
  color: #67c23a;
  font-weight: 600;
}

.money-minus {
  color: #f56c6c;
  font-weight: 600;
}
</style>
