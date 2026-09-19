<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  deleteBusinessMoneyLog,
  getBusinessMoneyLogList,
  type BusinessMoneyLogItem,
  type BusinessOption,
} from '../../api/business'
import { formatTime } from '../../utils/time'

// 商户过滤视图预留（merchantViewOf 注入 bid='gt0'）
const props = withDefaults(defineProps<{ bid?: string }>(), { bid: '' })

const loading = ref(false)
const tableData = ref<BusinessMoneyLogItem[]>([])
const total = ref(0)
const businessOptions = ref<BusinessOption[]>([])
const selectedIds = ref<number[]>([])

const query = reactive({
  page: 1,
  pagesize: 10,
  bid: '',
  name: '',
  remark: '',
  range: [] as string[],
})

async function fetchList() {
  loading.value = true
  try {
    const data = await getBusinessMoneyLogList({
      page: query.page,
      pagesize: query.pagesize,
      bid: props.bid || query.bid || undefined,
      name: query.name || undefined,
      remark: query.remark || undefined,
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

function handleSelectionChange(rows: BusinessMoneyLogItem[]) {
  selectedIds.value = rows.map((r) => r.id)
}

function handleDelete(ids: number[]) {
  if (!ids.length) {
    ElMessage.warning('请选择记录')
    return
  }
  ElMessageBox.confirm(`确定要删除选中的 ${ids.length} 条明细吗？删除后不可恢复。`, '删除确认', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await deleteBusinessMoneyLog(ids)
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
        <el-form-item label="备注">
          <el-input
            v-model="query.remark"
            placeholder="备注关键词"
            clearable
            style="width: 160px"
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
        <el-table-column prop="name" label="商户" min-width="130" show-overflow-tooltip>
          <template #default="{ row }">{{ row.name || '-' }}</template>
        </el-table-column>
        <el-table-column label="变动金额" width="120" align="right">
          <template #default="{ row }">
            <span :class="Number(row.money) >= 0 ? 'money-plus' : 'money-minus'">
              {{ Number(row.money) >= 0 ? '+' : '' }}{{ Number(row.money).toFixed(2) }}
            </span>
          </template>
        </el-table-column>
        <el-table-column label="变动后余额" width="120" align="right">
          <template #default="{ row }">{{ Number(row.after).toFixed(2) }}</template>
        </el-table-column>
        <el-table-column prop="type" label="类型" width="110" show-overflow-tooltip>
          <template #default="{ row }">{{ row.type || '-' }}</template>
        </el-table-column>
        <el-table-column prop="ordernum" label="订单号" min-width="150" show-overflow-tooltip>
          <template #default="{ row }">{{ row.ordernum || '-' }}</template>
        </el-table-column>
        <el-table-column prop="remark" label="备注" min-width="160" show-overflow-tooltip>
          <template #default="{ row }">{{ row.remark || '-' }}</template>
        </el-table-column>
        <el-table-column label="时间" width="165">
          <template #default="{ row }">{{ formatTime(row.createtime) }}</template>
        </el-table-column>
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

.money-plus {
  color: #67c23a;
}

.money-minus {
  color: #f56c6c;
}
</style>
