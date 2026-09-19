<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { getRechargeList, refundRecharge } from '../../api/finance'
import type { RechargeItem, RechargeStat } from '../../api/finance'
import { formatTime } from '../../utils/time'

// 状态 0未支付 1已支付 2已退款
const STATUS_TEXT: Record<number, string> = { 0: '未支付', 1: '已支付', 2: '已退款' }
const STATUS_TAG: Record<number, 'warning' | 'success' | 'danger'> = {
  0: 'warning',
  1: 'success',
  2: 'danger',
}

const loading = ref(false)
const tableData = ref<RechargeItem[]>([])
const total = ref(0)
const stat = ref<RechargeStat>({ paid_total: 0, paid_count: 0, refund_count: 0 })

const query = reactive({
  page: 1,
  pagesize: 10,
  nickname: '',
  mid: '',
  status: '',
  ordernum: '',
  timeRange: null as [string, string] | null,
})

async function fetchList() {
  loading.value = true
  try {
    const data = await getRechargeList({
      page: query.page,
      pagesize: query.pagesize,
      nickname: query.nickname || undefined,
      mid: query.mid ? Number(query.mid) : undefined,
      status: query.status || undefined,
      ordernum: query.ordernum || undefined,
      start_date: query.timeRange?.[0] || undefined,
      end_date: query.timeRange?.[1] || undefined,
    })
    tableData.value = data.list || []
    total.value = data.total || 0
    stat.value = data.stat || { paid_total: 0, paid_count: 0, refund_count: 0 }
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
  query.nickname = ''
  query.mid = ''
  query.status = ''
  query.ordernum = ''
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

// 充值退款（事务：状态改退款 + 余额扣回 + 流水）
async function handleRefund(row: RechargeItem) {
  try {
    await ElMessageBox.confirm(
      `确认对「${row.nickname || '会员' + row.mid}」的充值单（￥${Number(row.money).toFixed(2)}）执行退款？退款将从会员余额中扣回该金额。`,
      '充值退款',
      { type: 'warning', confirmButtonText: '确认退款', cancelButtonText: '取消' },
    )
  } catch {
    return
  }
  try {
    await refundRecharge(row.id)
    ElMessage.success('已退款，充值金额已从会员余额扣回')
    fetchList()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  }
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
      <el-col :span="8">
        <el-card shadow="never" class="stat-card">
          <div class="stat-label">充值总额（已支付）</div>
          <div class="stat-value primary">￥{{ Number(stat.paid_total).toFixed(2) }}</div>
        </el-card>
      </el-col>
      <el-col :span="8">
        <el-card shadow="never" class="stat-card">
          <div class="stat-label">已支付笔数</div>
          <div class="stat-value success">{{ stat.paid_count }}</div>
        </el-card>
      </el-col>
      <el-col :span="8">
        <el-card shadow="never" class="stat-card">
          <div class="stat-label">已退款笔数</div>
          <div class="stat-value danger">{{ stat.refund_count }}</div>
        </el-card>
      </el-col>
    </el-row>

    <!-- 搜索工具栏 -->
    <el-card shadow="never" class="toolbar-card">
      <el-form inline @submit.prevent>
        <el-form-item label="状态">
          <el-select v-model="query.status" placeholder="全部" clearable style="width: 110px">
            <el-option label="未支付" value="0" />
            <el-option label="已支付" value="1" />
            <el-option label="已退款" value="2" />
          </el-select>
        </el-form-item>
        <el-form-item label="订单号">
          <el-input v-model="query.ordernum" placeholder="订单号" clearable style="width: 150px" @keyup.enter="handleSearch" />
        </el-form-item>
        <el-form-item label="昵称">
          <el-input v-model="query.nickname" placeholder="会员昵称" clearable style="width: 130px" @keyup.enter="handleSearch" />
        </el-form-item>
        <el-form-item label="会员ID">
          <el-input v-model="query.mid" placeholder="ID" clearable style="width: 90px" @keyup.enter="handleSearch" />
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
        <el-table-column label="会员" min-width="120" show-overflow-tooltip>
          <template #default="{ row }">
            {{ row.nickname || `会员${row.mid}` }}
            <span v-if="row.tel" class="sub-text">{{ row.tel }}</span>
          </template>
        </el-table-column>
        <el-table-column label="充值金额" width="92" class-name="compact-col">
          <template #default="{ row }">￥{{ Number(row.money).toFixed(2) }}</template>
        </el-table-column>
        <el-table-column prop="paytype_text" label="支付方式" width="86" class-name="compact-col" show-overflow-tooltip />
        <el-table-column prop="paynum" label="支付单号" min-width="130" show-overflow-tooltip>
          <template #default="{ row }">{{ row.paynum || '-' }}</template>
        </el-table-column>
        <el-table-column label="支付时间" width="92" class-name="compact-col">
          <template #default="{ row }">
            <el-tooltip v-if="row.paytime" :content="formatTime(row.paytime)" placement="top">
              <span>{{ shortDate(row.paytime) }}</span>
            </el-tooltip>
            <span v-else>-</span>
          </template>
        </el-table-column>
        <el-table-column label="下单时间" width="92" class-name="compact-col">
          <template #default="{ row }">
            <el-tooltip v-if="row.createtime" :content="formatTime(row.createtime)" placement="top">
              <span>{{ shortDate(row.createtime) }}</span>
            </el-tooltip>
            <span v-else>-</span>
          </template>
        </el-table-column>
        <el-table-column label="状态" width="76" class-name="compact-col">
          <template #default="{ row }">
            <el-tag :type="STATUS_TAG[row.status] || 'info'" size="small">
              {{ STATUS_TEXT[row.status] || '未知' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="84" fixed="right" class-name="compact-col">
          <template #default="{ row }">
            <el-button
              v-if="row.status === 1"
              link
              type="danger"
              @click="handleRefund(row)"
            >
              退款
            </el-button>
            <span v-else class="sub-text">-</span>
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

.stat-value.danger {
  color: #f56c6c;
}

.stat-value.success {
  color: #67c23a;
}

.toolbar-card {
  margin-bottom: 12px;
}

.sub-text {
  color: #909399;
  font-size: 12px;
  margin-left: 4px;
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
