<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  deleteBusinessWithdrawLog,
  getBusinessWithdrawLogList,
  setBusinessWithdrawLogSt,
  type BusinessOption,
  type BusinessWithdrawLogItem,
} from '../../api/business'
import { formatTime } from '../../utils/time'

// 商户过滤视图预留（merchantViewOf 注入 bid='gt0'）
const props = withDefaults(defineProps<{ bid?: string }>(), { bid: '' })

const loading = ref(false)
const tableData = ref<BusinessWithdrawLogItem[]>([])
const total = ref(0)
const businessOptions = ref<BusinessOption[]>([])
const selectedIds = ref<number[]>([])

// status：0=审核中 1=已审核 2=已驳回 3=已打款
const STATUS_META: Record<number, { text: string; type: 'info' | 'warning' | 'danger' | 'success' | 'primary' }> = {
  0: { text: '审核中', type: 'warning' },
  1: { text: '已审核', type: 'primary' },
  2: { text: '已驳回', type: 'danger' },
  3: { text: '已打款', type: 'success' },
}

const query = reactive({
  page: 1,
  pagesize: 10,
  bid: '',
  name: '',
  status: '' as number | '',
  range: [] as string[],
})

async function fetchList() {
  loading.value = true
  try {
    const data = await getBusinessWithdrawLogList({
      page: query.page,
      pagesize: query.pagesize,
      bid: props.bid || query.bid || undefined,
      name: query.name || undefined,
      status: query.status === '' ? undefined : query.status,
      start: query.range?.[0] || undefined,
      end: query.range?.[1] || undefined,
    })
    tableData.value = data.list || []
    total.value = data.total || 0
    businessOptions.value = data.business_options || []
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
  query.bid = ''
  query.name = ''
  query.status = ''
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

function handleSelectionChange(rows: BusinessWithdrawLogItem[]) {
  selectedIds.value = rows.map((r) => r.id)
}

// ============ 审核（通过） ============
async function handleAudit(row: BusinessWithdrawLogItem) {
  try {
    await ElMessageBox.confirm(`确认审核通过该笔提现（¥${Number(row.txmoney).toFixed(2)}）吗？`, '审核确认', {
      confirmButtonText: '通过',
      cancelButtonText: '取消',
      type: 'warning',
    })
    await setBusinessWithdrawLogSt(row.id, 1)
    ElMessage.success('审核成功')
    fetchList()
  } catch {
    // 取消或错误提示已由拦截器统一弹出
  }
}

// ============ 驳回（返还余额，需填原因） ============
async function handleReject(row: BusinessWithdrawLogItem) {
  try {
    const { value } = await ElMessageBox.prompt('请输入驳回原因（提现金额将返还商户余额）', '驳回提现', {
      confirmButtonText: '驳回',
      cancelButtonText: '取消',
      inputPlaceholder: '驳回原因',
      inputValidator: (v) => (v && v.trim() ? true : '请填写驳回原因'),
      type: 'warning',
    })
    await setBusinessWithdrawLogSt(row.id, 2, String(value).trim())
    ElMessage.success('已驳回')
    fetchList()
  } catch {
    // 取消或错误提示已由拦截器统一弹出
  }
}

// ============ 标记打款 ============
async function handlePaid(row: BusinessWithdrawLogItem) {
  try {
    await ElMessageBox.confirm(`确认该笔提现（¥${Number(row.txmoney).toFixed(2)}）已完成打款吗？`, '标记打款', {
      confirmButtonText: '确认打款',
      cancelButtonText: '取消',
      type: 'warning',
    })
    await setBusinessWithdrawLogSt(row.id, 3)
    ElMessage.success('操作成功')
    fetchList()
  } catch {
    // 取消或错误提示已由拦截器统一弹出
  }
}

// ============ 删除 ============
function handleDelete(ids: number[]) {
  if (!ids.length) {
    ElMessage.warning('请选择记录')
    return
  }
  ElMessageBox.confirm(`确定要删除选中的 ${ids.length} 条提现记录吗？删除后不可恢复。`, '删除确认', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await deleteBusinessWithdrawLog(ids)
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
    <el-card shadow="never" class="toolbar-card">
      <el-form inline @submit.prevent>
        <el-form-item label="商户">
          <el-select v-model="query.bid" placeholder="全部" clearable filterable style="width: 160px">
            <el-option v-for="b in businessOptions" :key="b.id" :label="b.name" :value="String(b.id)" />
          </el-select>
        </el-form-item>
        <el-form-item label="商户名称">
          <el-input
            v-model="query.name"
            placeholder="商户名称关键词"
            clearable
            style="width: 160px"
            @keyup.enter="handleSearch"
          />
        </el-form-item>
        <el-form-item label="状态">
          <el-select v-model="query.status" placeholder="全部" clearable style="width: 120px">
            <el-option label="审核中" :value="0" />
            <el-option label="已审核" :value="1" />
            <el-option label="已驳回" :value="2" />
            <el-option label="已打款" :value="3" />
          </el-select>
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
          <el-button type="danger" @click="handleDelete(selectedIds)">批量删除</el-button>
        </el-form-item>
      </el-form>
    </el-card>

    <el-card shadow="never">
      <el-table
        v-loading="loading"
        :data="tableData"
        stripe
        @selection-change="handleSelectionChange"
      >
        <el-table-column type="selection" width="45" />
        <el-table-column prop="id" label="ID" width="70" />
        <el-table-column prop="name" label="商户" min-width="120" show-overflow-tooltip>
          <template #default="{ row }">{{ row.name || '-' }}</template>
        </el-table-column>
        <el-table-column label="提现金额" width="110" align="right">
          <template #default="{ row }">{{ Number(row.txmoney).toFixed(2) }}</template>
        </el-table-column>
        <el-table-column label="手续费" width="90" align="right">
          <template #default="{ row }">
            {{ Number((Number(row.money) - Number(row.txmoney)) || 0).toFixed(2) }}
          </template>
        </el-table-column>
        <el-table-column prop="paytype" label="提现方式" width="100">
          <template #default="{ row }">{{ row.paytype || '-' }}</template>
        </el-table-column>
        <el-table-column prop="account" label="收款账号" min-width="180" show-overflow-tooltip>
          <template #default="{ row }">{{ row.account || '-' }}</template>
        </el-table-column>
        <el-table-column label="状态" width="90">
          <template #default="{ row }">
            <el-tag :type="STATUS_META[row.status]?.type || 'info'" size="small">
              {{ STATUS_META[row.status]?.text || '未知' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="reason" label="驳回原因" min-width="130" show-overflow-tooltip>
          <template #default="{ row }">{{ row.reason || '-' }}</template>
        </el-table-column>
        <el-table-column label="申请时间" width="165">
          <template #default="{ row }">{{ formatTime(row.createtime) }}</template>
        </el-table-column>
        <el-table-column label="打款时间" width="165">
          <template #default="{ row }">{{ row.paytime ? formatTime(row.paytime) : '-' }}</template>
        </el-table-column>
        <el-table-column label="操作" width="180" fixed="right">
          <template #default="{ row }">
            <template v-if="row.status === 0">
              <el-button link type="success" @click="handleAudit(row)">通过</el-button>
              <el-button link type="danger" @click="handleReject(row)">驳回</el-button>
            </template>
            <el-button v-if="row.status === 1" link type="primary" @click="handlePaid(row)">标记打款</el-button>
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
        :page-sizes="[10, 20, 50, 100]"
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
