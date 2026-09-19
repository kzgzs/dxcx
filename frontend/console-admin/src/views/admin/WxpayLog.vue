<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { getFinWxpayLogList } from '../../api/finance'
import type { WxpayLogFinItem } from '../../api/finance'
import { formatTime } from '../../utils/time'

const loading = ref(false)
const tableData = ref<WxpayLogFinItem[]>([])
const total = ref(0)
const stat = ref({ count: 0, total: 0 })

const query = reactive({
  page: 1,
  pagesize: 10,
  ordernum: '',
  transaction_id: '',
  isfenzhang: '',
  timeRange: null as [string, string] | null,
})

async function fetchList() {
  loading.value = true
  try {
    const data = await getFinWxpayLogList({
      page: query.page,
      pagesize: query.pagesize,
      ordernum: query.ordernum || undefined,
      transaction_id: query.transaction_id || undefined,
      isfenzhang: query.isfenzhang || undefined,
      start_date: query.timeRange?.[0] || undefined,
      end_date: query.timeRange?.[1] || undefined,
    })
    tableData.value = data.list || []
    total.value = data.total || 0
    stat.value = data.stat || { count: 0, total: 0 }
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
  query.ordernum = ''
  query.transaction_id = ''
  query.isfenzhang = ''
  query.timeRange = null
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

function shortDate(ts: number): string {
  if (!ts) return '-'
  const full = formatTime(ts)
  return full.length >= 10 ? full.slice(2, 10) : full
}

onMounted(() => {
  fetchList()
})
</script>

<template>
  <div>
    <!-- 顶部统计卡片 -->
    <el-row :gutter="12" class="stat-row">
      <el-col :span="12">
        <el-card shadow="never" class="stat-card">
          <div class="stat-label">流水笔数</div>
          <div class="stat-value primary">{{ stat.count }}</div>
        </el-card>
      </el-col>
      <el-col :span="12">
        <el-card shadow="never" class="stat-card">
          <div class="stat-label">支付总额</div>
          <div class="stat-value success">￥{{ Number(stat.total).toFixed(2) }}</div>
        </el-card>
      </el-col>
    </el-row>

    <!-- 搜索工具栏 -->
    <el-card shadow="never" class="toolbar-card">
      <el-form inline @submit.prevent>
        <el-form-item label="订单号">
          <el-input v-model="query.ordernum" placeholder="商户订单号" clearable style="width: 160px" @keyup.enter="handleSearch" />
        </el-form-item>
        <el-form-item label="交易号">
          <el-input v-model="query.transaction_id" placeholder="微信交易号" clearable style="width: 180px" @keyup.enter="handleSearch" />
        </el-form-item>
        <el-form-item label="分账">
          <el-select v-model="query.isfenzhang" placeholder="全部" clearable style="width: 110px">
            <el-option label="未分账" value="0" />
            <el-option label="已分账" value="1" />
          </el-select>
        </el-form-item>
        <el-form-item label="时间">
          <el-date-picker
            v-model="query.timeRange"
            type="daterange"
            range-separator="至"
            start-placeholder="开始"
            end-placeholder="结束"
            value-format="YYYY-MM-DD"
            style="width: 220px"
          />
        </el-form-item>
        <el-form-item>
          <el-button type="primary" @click="handleSearch">搜索</el-button>
          <el-button @click="handleReset">重置</el-button>
        </el-form-item>
      </el-form>
    </el-card>

    <!-- 列表（border 竖线 + 紧凑列宽，1366 无横向滚动） -->
    <el-card shadow="never">
      <el-table v-loading="loading" :data="tableData" stripe border size="small">
        <el-table-column prop="ordernum" label="订单号" min-width="150" show-overflow-tooltip />
        <el-table-column prop="transaction_id" label="微信交易号" min-width="160" show-overflow-tooltip>
          <template #default="{ row }">{{ row.transaction_id || '-' }}</template>
        </el-table-column>
        <el-table-column prop="mch_id" label="商户号" min-width="100" show-overflow-tooltip>
          <template #default="{ row }">{{ row.mch_id || '-' }}</template>
        </el-table-column>
        <el-table-column prop="sub_mchid" label="子商户号" min-width="100" show-overflow-tooltip>
          <template #default="{ row }">{{ row.sub_mchid || '-' }}</template>
        </el-table-column>
        <el-table-column label="支付金额" width="86" class-name="compact-col">
          <template #default="{ row }">￥{{ Number(row.total_fee).toFixed(2) }}</template>
        </el-table-column>
        <el-table-column label="分账金额" width="86" class-name="compact-col">
          <template #default="{ row }">
            <span v-if="Number(row.fenzhangmoney) > 0">￥{{ Number(row.fenzhangmoney).toFixed(2) }}</span>
            <span v-else>-</span>
          </template>
        </el-table-column>
        <el-table-column label="已分账" width="72" class-name="compact-col">
          <template #default="{ row }">
            <el-tag v-if="row.isfenzhang === 1" type="success" size="small">是</el-tag>
            <el-tag v-else type="info" size="small">否</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="退款额" width="80" class-name="compact-col">
          <template #default="{ row }">
            <span v-if="Number(row.refund_money) > 0" class="danger-text">￥{{ Number(row.refund_money).toFixed(2) }}</span>
            <span v-else>-</span>
          </template>
        </el-table-column>
        <el-table-column label="会员" min-width="96" show-overflow-tooltip>
          <template #default="{ row }">
            <span v-if="row.mid > 0">{{ row.nickname || `会员${row.mid}` }}</span>
            <span v-else>-</span>
          </template>
        </el-table-column>
        <el-table-column label="时间" width="92" class-name="compact-col">
          <template #default="{ row }">
            <el-tooltip v-if="row.createtime" :content="formatTime(row.createtime)" placement="top">
              <span>{{ shortDate(row.createtime) }}</span>
            </el-tooltip>
            <span v-else>-</span>
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
.stat-row {
  margin-bottom: 12px;
}

.stat-card .stat-label {
  color: #909399;
  font-size: 13px;
}

.stat-card .stat-value {
  font-size: 22px;
  font-weight: 700;
  margin-top: 4px;
}

.stat-value.primary {
  color: #409eff;
}

.stat-value.success {
  color: #67c23a;
}

.toolbar-card {
  margin-bottom: 12px;
}

.danger-text {
  color: #f56c6c;
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
