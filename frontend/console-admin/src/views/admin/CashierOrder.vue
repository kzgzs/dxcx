<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  cashierRefundApply,
  cashierRefundAudit,
  deleteCashierOrders,
  getCashierOrderDetail,
  getCashierOrderList,
} from '../../api/cashier'
import type { CashierOrderItem } from '../../api/cashier'
import { formatTime } from '../../utils/time'

/* ============ 状态 tabs ============ */
// status：''全部 1已完成 2挂单 10已退款 0结算等待中
const tabs = [
  { name: '', label: '全部' },
  { name: '1', label: '已完成' },
  { name: '2', label: '挂单' },
  { name: '10', label: '已退款' },
  { name: '0', label: '结算等待中' },
]

const STATUS_TAG: Record<number, '' | 'success' | 'warning' | 'info' | 'danger'> = {
  0: 'info',
  1: 'success',
  2: 'warning',
  10: 'danger',
}

const REFUND_TAG: Record<number, '' | 'success' | 'warning' | 'info' | 'danger'> = {
  1: 'warning',
  2: 'success',
  3: 'danger',
}

/* ============ 列表 ============ */
const activeTab = ref('')
const loading = ref(false)
const tableData = ref<CashierOrderItem[]>([])
const total = ref(0)

const query = reactive({
  page: 1,
  pagesize: 20,
  ordernum: '',
  paytypeid: 0,
  refund_status: '' as string | number,
  dateRange: null as [string, string] | null,
})

async function fetchList() {
  loading.value = true
  try {
    const data = await getCashierOrderList({
      page: query.page,
      pagesize: query.pagesize,
      status: activeTab.value === '' ? undefined : activeTab.value,
      ordernum: query.ordernum || undefined,
      paytypeid: query.paytypeid || undefined,
      refund_status: query.refund_status === '' ? undefined : query.refund_status,
      starttime: query.dateRange?.[0] || undefined,
      endtime: query.dateRange?.[1] || undefined,
    })
    tableData.value = data?.list || []
    total.value = data?.total || 0
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    loading.value = false
  }
}

function handleTabChange() {
  query.page = 1
  fetchList()
}

function handleSearch() {
  query.page = 1
  fetchList()
}

function handleReset() {
  query.ordernum = ''
  query.paytypeid = 0
  query.refund_status = ''
  query.dateRange = null
  handleSearch()
}

/* ============ 详情 ============ */
const detailDialog = ref(false)
const detail = ref<CashierOrderItem | null>(null)

async function handleDetail(row: CashierOrderItem) {
  try {
    detail.value = await getCashierOrderDetail(row.id)
    detailDialog.value = true
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  }
}

/* ============ 退款 ============ */
async function handleRefundApply(row: CashierOrderItem) {
  try {
    const { value } = await ElMessageBox.prompt(
      `订单「${row.ordernum}」实收 ¥${Number(row.totalprice).toFixed(2)}，请输入退款金额`,
      '申请退款',
      {
        confirmButtonText: '提交申请',
        cancelButtonText: '取消',
        inputPattern: /^\d+(\.\d{1,2})?$/,
        inputErrorMessage: '金额格式不正确',
      },
    )
    const money = Number(value)
    const reasonBox = await ElMessageBox.prompt('请输入退款原因', '退款原因', {
      confirmButtonText: '提交',
      cancelButtonText: '取消',
      inputValidator: (v) => (v && v.trim() ? true : '请填写退款原因'),
    })
    await cashierRefundApply(row.id, money, reasonBox.value || '')
    ElMessage.success('退款申请已提交')
    fetchList()
  } catch {
    // 取消或接口错误
  }
}

async function handleRefundAudit(row: CashierOrderItem, agree: number) {
  const tip = agree === 1
    ? `同意退款将退回 ¥${Number(row.refund_money).toFixed(2)}（余额支付退会员余额）并回退库存，确认？`
    : '确认驳回该退款申请吗？'
  ElMessageBox.confirm(tip, '退款审核', {
    confirmButtonText: '确定',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await cashierRefundAudit(row.id, agree)
        ElMessage.success(agree === 1 ? '已同意退款' : '已驳回退款')
        fetchList()
      } catch {
        // 错误提示已由 axios 拦截器统一弹出
      }
    })
    .catch(() => {})
}

/* ============ 删除 ============ */
const selectedIds = ref<number[]>([])

function handleSelectionChange(rows: CashierOrderItem[]) {
  selectedIds.value = rows.map((r) => r.id)
}

function handleBatchDelete() {
  if (!selectedIds.value.length) {
    ElMessage.warning('请选择要删除的订单')
    return
  }
  ElMessageBox.confirm(`确认删除选中的 ${selectedIds.value.length} 个订单吗？删除后不可恢复`, '操作确认', {
    confirmButtonText: '确定删除',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await deleteCashierOrders(selectedIds.value)
        ElMessage.success('删除成功')
        fetchList()
      } catch {
        // 错误提示已由 axios 拦截器统一弹出
      }
    })
    .catch(() => {})
}

function handleDelete(row: CashierOrderItem) {
  ElMessageBox.confirm(`确认删除订单「${row.ordernum}」吗？`, '操作确认', {
    confirmButtonText: '确定删除',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await deleteCashierOrders([row.id])
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
      <el-tabs v-model="activeTab" class="order-tabs" @tab-change="handleTabChange">
        <el-tab-pane v-for="t in tabs" :key="t.name" :label="t.label" :name="t.name" />
      </el-tabs>

      <!-- 搜索工具栏 -->
      <el-form inline @submit.prevent>
        <el-form-item label="订单号">
          <el-input
            v-model="query.ordernum"
            placeholder="订单号"
            clearable
            style="width: 180px"
            @keyup.enter="handleSearch"
          />
        </el-form-item>
        <el-form-item label="支付方式">
          <el-select v-model="query.paytypeid" clearable style="width: 140px" placeholder="全部">
            <el-option :value="1" label="微信支付" />
            <el-option :value="3" label="现金" />
            <el-option :value="4" label="余额" />
          </el-select>
        </el-form-item>
        <el-form-item label="退款状态">
          <el-select v-model="query.refund_status" clearable style="width: 140px" placeholder="全部">
            <el-option value="1" label="退款审核中" />
            <el-option value="2" label="已同意退款" />
            <el-option value="3" label="已驳回退款" />
          </el-select>
        </el-form-item>
        <el-form-item label="下单时间">
          <el-date-picker
            v-model="query.dateRange"
            type="daterange"
            range-separator="至"
            start-placeholder="开始日期"
            end-placeholder="结束日期"
            value-format="YYYY-MM-DD"
            style="width: 240px"
          />
        </el-form-item>
        <el-form-item>
          <el-button type="primary" @click="handleSearch">搜索</el-button>
          <el-button @click="handleReset">重置</el-button>
          <el-button type="danger" plain :disabled="!selectedIds.length" @click="handleBatchDelete">
            批量删除
          </el-button>
        </el-form-item>
      </el-form>

      <el-table v-loading="loading" :data="tableData" stripe @selection-change="handleSelectionChange">
        <el-table-column type="selection" width="45" />
        <el-table-column prop="ordernum" label="订单号" min-width="160" show-overflow-tooltip />
        <el-table-column label="金额" width="100">
          <template #default="{ row }">¥{{ Number(row.totalprice).toFixed(2) }}</template>
        </el-table-column>
        <el-table-column prop="paytype" label="支付方式" width="110">
          <template #default="{ row }">{{ row.paytype || '-' }}</template>
        </el-table-column>
        <el-table-column prop="nickname" label="会员" width="110">
          <template #default="{ row }">{{ row.nickname || '-' }}</template>
        </el-table-column>
        <el-table-column prop="admin_user" label="收银员" width="100" />
        <el-table-column label="状态" width="90">
          <template #default="{ row }">
            <el-tag :type="STATUS_TAG[row.status] || 'info'" size="small">
              {{ row.status_desc }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="退款" width="110">
          <template #default="{ row }">
            <el-tag
              v-if="row.refund_status > 0"
              :type="REFUND_TAG[row.refund_status] || 'info'"
              size="small"
            >
              {{ row.refund_status_desc }}
            </el-tag>
            <span v-else>-</span>
          </template>
        </el-table-column>
        <el-table-column label="下单时间" width="160">
          <template #default="{ row }">{{ formatTime(row.createtime) }}</template>
        </el-table-column>
        <el-table-column label="操作" width="230" fixed="right">
          <template #default="{ row }">
            <el-button link type="primary" @click="handleDetail(row)">详情</el-button>
            <el-button
              v-if="row.status === 1 && (row.refund_status === 0 || row.refund_status === 3)"
              link
              type="warning"
              @click="handleRefundApply(row)"
            >
              申请退款
            </el-button>
            <template v-if="row.refund_status === 1">
              <el-button link type="success" @click="handleRefundAudit(row, 1)">同意退款</el-button>
              <el-button link type="danger" @click="handleRefundAudit(row, 0)">驳回</el-button>
            </template>
            <el-button
              v-if="row.status !== 1 || row.refund_status !== 1"
              link
              type="danger"
              @click="handleDelete(row)"
            >
              删除
            </el-button>
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
        @current-change="(p: number) => { query.page = p; fetchList() }"
        @size-change="(s: number) => { query.pagesize = s; query.page = 1; fetchList() }"
      />
    </el-card>

    <!-- 详情弹窗 -->
    <el-dialog v-model="detailDialog" title="订单详情" width="640px">
      <template v-if="detail">
        <el-descriptions :column="2" border size="small">
          <el-descriptions-item label="订单号">{{ detail.ordernum }}</el-descriptions-item>
          <el-descriptions-item label="状态">{{ detail.status_desc }}</el-descriptions-item>
          <el-descriptions-item label="原价">
            ¥{{ Number(detail.pre_totalprice).toFixed(2) }}
          </el-descriptions-item>
          <el-descriptions-item label="实收">
            ¥{{ Number(detail.totalprice).toFixed(2) }}
          </el-descriptions-item>
          <el-descriptions-item label="会员折扣">
            -¥{{ Number(detail.leveldk_money).toFixed(2) }}
          </el-descriptions-item>
          <el-descriptions-item label="抹零">
            -¥{{ Number(detail.moling_money).toFixed(2) }}
          </el-descriptions-item>
          <el-descriptions-item label="支付方式">{{ detail.paytype || '-' }}</el-descriptions-item>
          <el-descriptions-item label="支付单号">{{ detail.paynum || '-' }}</el-descriptions-item>
          <el-descriptions-item label="会员">{{ detail.nickname || '-' }}</el-descriptions-item>
          <el-descriptions-item label="收银员">{{ detail.admin_user }}</el-descriptions-item>
          <el-descriptions-item label="下单时间">{{ formatTime(detail.createtime) }}</el-descriptions-item>
          <el-descriptions-item label="支付时间">
            {{ detail.paytime ? formatTime(detail.paytime) : '-' }}
          </el-descriptions-item>
          <el-descriptions-item v-if="detail.remark" label="备注" :span="2">
            {{ detail.remark }}
          </el-descriptions-item>
          <el-descriptions-item v-if="detail.refund_status > 0" label="退款状态" :span="2">
            {{ detail.refund_status_desc }}（¥{{ Number(detail.refund_money).toFixed(2) }}）
            <span v-if="detail.refund_reason">原因：{{ detail.refund_reason }}</span>
            <span v-if="detail.refund_checkremark">；审核备注：{{ detail.refund_checkremark }}</span>
          </el-descriptions-item>
        </el-descriptions>

        <div class="detail-goods-title">商品明细</div>
        <el-table :data="detail.prolist || []" size="small" border>
          <el-table-column prop="proname" label="商品" min-width="160">
            <template #default="{ row }">
              {{ row.proname }}
              <span v-if="row.ggname">({{ row.ggname }})</span>
              <el-tag v-if="row.is_gj === 1" size="small" type="warning">改价</el-tag>
            </template>
          </el-table-column>
          <el-table-column label="单价" width="90">
            <template #default="{ row }">¥{{ Number(row.sell_price).toFixed(2) }}</template>
          </el-table-column>
          <el-table-column prop="num" label="数量" width="70" />
          <el-table-column label="小计" width="90">
            <template #default="{ row }">¥{{ Number(row.totalprice).toFixed(2) }}</template>
          </el-table-column>
          <el-table-column label="实收分摊" width="100">
            <template #default="{ row }">¥{{ Number(row.real_totalprice).toFixed(2) }}</template>
          </el-table-column>
        </el-table>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped>
.order-tabs {
  margin-bottom: 8px;
}

.pagination {
  margin-top: 16px;
  justify-content: flex-end;
}

.detail-goods-title {
  margin: 16px 0 8px;
  font-weight: 600;
}
</style>
