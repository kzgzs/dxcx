<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { getWxpayLogDetail, getWxpayLogList } from '../api/system'
import type { WxpayLogItem } from '../api/system'
import { formatTime } from '../utils/time'

// 微信支付记录（只读，数据来源 pay_transaction 支付流水表）
const loading = ref(false)
const tableData = ref<WxpayLogItem[]>([])
const total = ref(0)

// 筛选表单
const filter = reactive({
  ordernum: '',
  transaction_num: '',
  status: '' as number | '',
  paytypeid: '' as number | '',
  dateRange: null as [string, string] | null,
})

const query = reactive({
  page: 1,
  pagesize: 20,
})

// 状态：0待支付(橙) 1成功(绿) 2失败(红)
const STATUS_MAP: Record<number, { text: string; type: 'success' | 'danger' | 'warning' | 'info' }> = {
  0: { text: '待支付', type: 'warning' },
  1: { text: '成功', type: 'success' },
  2: { text: '失败', type: 'danger' },
}

// 支付方式（paytypeid）
const PAY_TYPE_OPTIONS: { label: string; value: number }[] = [
  { label: '微信支付', value: 2 },
  { label: '支付宝支付', value: 3 },
  { label: '余额支付', value: 1 },
  { label: '货到付款', value: 4 },
  { label: '转账汇款', value: 5 },
  { label: '百度小程序支付', value: 11 },
  { label: '头条小程序支付', value: 12 },
]

// 业务类型（type 字段）中文映射
const BIZ_TYPE_MAP: Record<string, string> = {
  shop: '商城订单',
  collage: '拼团',
  scoreshop: '积分商城',
  kanjia: '砍价',
  seckill: '秒杀',
  recharge: '充值',
  maidan: '买单',
  designerpage: '设计师页面',
  form: '表单',
}

function statusText(row: WxpayLogItem) {
  return STATUS_MAP[row.status]?.text || '未知'
}

function statusType(row: WxpayLogItem) {
  return STATUS_MAP[row.status]?.type || 'info'
}

function bizTypeText(row: WxpayLogItem) {
  if (!row.type) return '-'
  return BIZ_TYPE_MAP[row.type] || row.type
}

async function fetchList() {
  loading.value = true
  try {
    const data = await getWxpayLogList({
      page: query.page,
      pagesize: query.pagesize,
      ordernum: filter.ordernum.trim() || undefined,
      transaction_num: filter.transaction_num.trim() || undefined,
      status: filter.status === '' ? undefined : filter.status,
      paytypeid: filter.paytypeid === '' ? undefined : filter.paytypeid,
      start_date: filter.dateRange ? filter.dateRange[0] : undefined,
      end_date: filter.dateRange ? filter.dateRange[1] : undefined,
    })
    tableData.value = data.list || []
    total.value = data.total || 0
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
    tableData.value = []
    total.value = 0
  } finally {
    loading.value = false
  }
}

function handleSearch() {
  query.page = 1
  fetchList()
}

function handleReset() {
  filter.ordernum = ''
  filter.transaction_num = ''
  filter.status = ''
  filter.paytypeid = ''
  filter.dateRange = null
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

// ============ 查看详情弹窗（当页展示，不跳转） ============
const detailVisible = ref(false)
const detailLoading = ref(false)
const detail = ref<WxpayLogItem | null>(null)
const showJson = ref(false)

async function handleDetail(row: WxpayLogItem) {
  detailVisible.value = true
  detailLoading.value = true
  detail.value = row
  showJson.value = false
  try {
    const data = await getWxpayLogDetail(row.id)
    detail.value = data
  } catch {
    // 错误提示已由 axios 拦截器统一弹出（弹窗内保留行数据兜底展示）
  } finally {
    detailLoading.value = false
  }
}

function detailJson() {
  if (!detail.value) return ''
  return JSON.stringify(detail.value, null, 2)
}

onMounted(fetchList)
</script>

<template>
  <el-card shadow="never">
    <template #header>
      <span>微信支付记录（只读，仅展示，不可修改）</span>
    </template>

    <!-- 筛选行 -->
    <div class="toolbar-filter">
      <span class="filter-label">商户订单号</span>
      <el-input
        v-model="filter.ordernum"
        placeholder="请输入商户订单号"
        style="width: 180px"
        clearable
        @keyup.enter="handleSearch"
      />
      <span class="filter-label">微信交易号</span>
      <el-input
        v-model="filter.transaction_num"
        placeholder="请输入微信交易号"
        style="width: 180px"
        clearable
        @keyup.enter="handleSearch"
      />
      <span class="filter-label">状态</span>
      <el-select v-model="filter.status" style="width: 110px" clearable placeholder="全部">
        <el-option label="全部" value="" />
        <el-option label="成功" :value="1" />
        <el-option label="失败" :value="2" />
        <el-option label="待支付" :value="0" />
      </el-select>
      <span class="filter-label">支付类型</span>
      <el-select v-model="filter.paytypeid" style="width: 150px" clearable placeholder="全部">
        <el-option label="全部" value="" />
        <el-option
          v-for="opt in PAY_TYPE_OPTIONS"
          :key="opt.value"
          :label="opt.label"
          :value="opt.value"
        />
      </el-select>
      <el-date-picker
        v-model="filter.dateRange"
        type="daterange"
        value-format="YYYY-MM-DD"
        range-separator="至"
        start-placeholder="开始日期"
        end-placeholder="结束日期"
        style="width: 260px"
      />
      <el-button class="btn-teal" @click="handleSearch">搜索</el-button>
      <el-button @click="handleReset">清空</el-button>
    </div>

    <!-- 流水列表（border 竖线，与通知公告统一） -->
    <el-table v-loading="loading" :data="tableData" stripe border>
      <el-table-column prop="id" label="ID" width="60" />
      <el-table-column label="商户订单号" min-width="160" show-overflow-tooltip>
        <template #default="{ row }">
          <span class="mono">{{ row.ordernum || '-' }}</span>
        </template>
      </el-table-column>
      <el-table-column label="微信交易号" min-width="180" show-overflow-tooltip>
        <template #default="{ row }">
          <span class="mono">{{ row.transaction_num || '-' }}</span>
        </template>
      </el-table-column>
      <el-table-column prop="tenant" label="租户" min-width="110" show-overflow-tooltip />
      <el-table-column label="金额" width="90" align="right">
        <template #default="{ row }">
          ¥ {{ row.money }}
        </template>
      </el-table-column>
      <el-table-column label="手续费" width="80" align="right">
        <template #default>
          <span class="muted">—</span>
        </template>
      </el-table-column>
      <el-table-column label="类型" width="100">
        <template #default="{ row }">
          {{ bizTypeText(row) }}
        </template>
      </el-table-column>
      <el-table-column label="状态" width="90">
        <template #default="{ row }">
          <el-tag :type="statusType(row)" size="small">{{ statusText(row) }}</el-tag>
        </template>
      </el-table-column>
      <el-table-column label="创建时间" width="150">
        <template #default="{ row }">
          {{ formatTime(row.createtime) }}
        </template>
      </el-table-column>
      <el-table-column label="操作" width="90" fixed="right">
        <template #default="{ row }">
          <el-button link type="primary" @click="handleDetail(row)">查看详情</el-button>
        </template>
      </el-table-column>

      <template #empty>
        <el-empty description="暂无支付记录" :image-size="90" />
      </template>
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

  <!-- 详情弹窗（当页展示，不跳转） -->
  <el-dialog v-model="detailVisible" title="支付流水详情" width="720px">
    <div v-loading="detailLoading">
      <el-descriptions
        v-if="detail"
        :column="2"
        border
        size="small"
        label-class-name="detail-label"
      >
        <el-descriptions-item label="ID">{{ detail.id }}</el-descriptions-item>
        <el-descriptions-item label="状态">
          <el-tag :type="statusType(detail)" size="small">{{ statusText(detail) }}</el-tag>
        </el-descriptions-item>
        <el-descriptions-item label="商户订单号">
          <span class="mono">{{ detail.ordernum || '-' }}</span>
        </el-descriptions-item>
        <el-descriptions-item label="微信交易号">
          <span class="mono">{{ detail.transaction_num || '-' }}</span>
        </el-descriptions-item>
        <el-descriptions-item label="支付回执单号">
          <span class="mono">{{ detail.paynum || '-' }}</span>
        </el-descriptions-item>
        <el-descriptions-item label="租户">{{ detail.tenant }}</el-descriptions-item>
        <el-descriptions-item label="订单标题" :span="2">
          {{ detail.title || '-' }}
        </el-descriptions-item>
        <el-descriptions-item label="金额">¥ {{ detail.money }}</el-descriptions-item>
        <el-descriptions-item label="积分">
          {{ detail.score ? detail.score : '-' }}
        </el-descriptions-item>
        <el-descriptions-item label="支付方式">
          {{ detail.paytype_text }}（paytypeid={{ detail.paytypeid }}）
        </el-descriptions-item>
        <el-descriptions-item label="业务类型">{{ bizTypeText(detail) }}</el-descriptions-item>
        <el-descriptions-item label="下单时间">{{ formatTime(detail.createtime) }}</el-descriptions-item>
        <el-descriptions-item label="支付时间">{{ formatTime(detail.paytime) }}</el-descriptions-item>
        <el-descriptions-item label="退款金额">
          {{ Number(detail.refund_money) > 0 ? `¥ ${detail.refund_money}` : '-' }}
        </el-descriptions-item>
        <el-descriptions-item label="退款时间">{{ formatTime(detail.refund_time) }}</el-descriptions-item>
        <el-descriptions-item label="来源平台">{{ detail.platform || '-' }}</el-descriptions-item>
        <el-descriptions-item label="支付渠道描述">{{ detail.paytype || '-' }}</el-descriptions-item>
      </el-descriptions>
      <el-empty v-else-if="!detailLoading" description="暂无数据" :image-size="80" />

      <div v-if="detail" class="json-block">
        <el-button size="small" @click="showJson = !showJson">
          {{ showJson ? '收起 JSON' : '查看原始 JSON' }}
        </el-button>
        <pre v-if="showJson" class="json-pre">{{ detailJson() }}</pre>
      </div>
    </div>
    <template #footer>
      <el-button type="primary" @click="detailVisible = false">关闭</el-button>
    </template>
  </el-dialog>
</template>

<style scoped>
.toolbar-filter {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
  margin-bottom: 12px;
}

.filter-label {
  color: #606266;
  font-size: 13px;
}

/* 青绿色主操作按钮（搜索，与租户页对齐） */
.btn-teal {
  --el-button-bg-color: #13c2c2;
  --el-button-border-color: #13c2c2;
  --el-button-hover-bg-color: #36cfc9;
  --el-button-hover-border-color: #36cfc9;
  --el-button-active-bg-color: #0ea9a9;
  --el-button-active-border-color: #0ea9a9;
  --el-button-text-color: #ffffff;
  color: #ffffff;
  background-color: #13c2c2;
  border-color: #13c2c2;
}

.btn-teal:hover,
.btn-teal:focus {
  color: #ffffff;
  background-color: #36cfc9;
  border-color: #36cfc9;
}

.mono {
  font-family: Consolas, Monaco, monospace;
  font-size: 13px;
}

.muted {
  color: #909399;
}

.pagination {
  margin-top: 16px;
  justify-content: flex-end;
}

.json-block {
  margin-top: 12px;
}

.json-pre {
  margin: 10px 0 0;
  padding: 12px;
  max-height: 280px;
  overflow: auto;
  background-color: #f5f7fa;
  border: 1px solid #ebeef5;
  border-radius: 4px;
  font-family: Consolas, Monaco, monospace;
  font-size: 12px;
  line-height: 1.6;
  color: #303133;
  white-space: pre-wrap;
  word-break: break-all;
}
</style>
