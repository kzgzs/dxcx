<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  changeShopOrderPrice,
  checkShopOrderRefund,
  closeShopOrder,
  getRemarkList,
  getShopOrderDetail,
  getShopOrderList,
  printShopOrder,
  setShopOrderRemark,
  shopOrderIspay,
} from '../../api/restaurantOrder'
import type { ShopOrderDetailResult, ShopOrderItem } from '../../api/restaurantOrder'
import { formatTime } from '../../utils/time'

// ============ 列表与统计 ============
const loading = ref(false)
const tableData = ref<ShopOrderItem[]>([])
const total = ref(0)
const stats = ref({ today_count: 0, today_money: 0 })

// tabs：''全部 0未支付 1已支付 3已完成 4已关闭 r1退款审核中
const activeTab = ref('')

const query = reactive({
  page: 1,
  pagesize: 10,
  ordernum: '',
  table_name: '',
  mid: '' as number | '',
  dateRange: '' as [string, string] | '',
})

async function fetchList() {
  loading.value = true
  try {
    const data = await getShopOrderList({
      page: query.page,
      pagesize: query.pagesize,
      status: activeTab.value.startsWith('r') ? undefined : activeTab.value || undefined,
      refund_status: activeTab.value === 'r1' ? 1 : undefined,
      ordernum: query.ordernum || undefined,
      table_name: query.table_name || undefined,
      mid: query.mid === '' ? undefined : query.mid,
      start_time: Array.isArray(query.dateRange) ? query.dateRange[0] : undefined,
      end_time: Array.isArray(query.dateRange) ? query.dateRange[1] : undefined,
    })
    tableData.value = data.list || []
    total.value = data.total || 0
    stats.value = data.stats || { today_count: 0, today_money: 0 }
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
  query.table_name = ''
  query.mid = ''
  query.dateRange = ''
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

function statusTagType(status: number) {
  if (status === 0) return 'info'
  if (status === 4) return 'danger'
  if (status === 3) return 'success'
  return 'primary'
}

// ============ 详情抽屉 ============
const drawerVisible = ref(false)
const drawerLoading = ref(false)
const detailData = ref<ShopOrderDetailResult | null>(null)

async function openDetail(row: ShopOrderItem) {
  drawerVisible.value = true
  drawerLoading.value = true
  detailData.value = null
  try {
    detailData.value = await getShopOrderDetail(row.id)
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    drawerLoading.value = false
  }
}

// ============ 改价（仅未支付） ============
const priceVisible = ref(false)
const priceRow = ref<ShopOrderItem | null>(null)
const priceValue = ref('')

function openPrice(row: ShopOrderItem) {
  priceRow.value = row
  priceValue.value = String(row.totalprice)
  priceVisible.value = true
}

async function handlePriceSubmit() {
  if (!priceRow.value) return
  const price = Number(priceValue.value)
  if (!(price > 0)) {
    ElMessage.warning('请输入大于0的有效金额')
    return
  }
  try {
    await changeShopOrderPrice(priceRow.value.id, price)
    ElMessage.success('改价成功，订单号已更新')
    priceVisible.value = false
    fetchList()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  }
}

// ============ 关单（仅未支付） ============
function handleClose(row: ShopOrderItem) {
  ElMessageBox.confirm(`确定要关闭订单「${row.ordernum}」吗？关闭后不可恢复。`, '关单确认', {
    confirmButtonText: '关闭订单',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await closeShopOrder(row.id)
        ElMessage.success('订单已关闭')
        fetchList()
      } catch {
        // 错误提示已由 axios 拦截器统一弹出
      }
    })
    .catch(() => {})
}

// ============ 代收款（仅未支付） ============
function handleIspay(row: ShopOrderItem) {
  ElMessageBox.confirm(
    `确认已线下收到订单「${row.ordernum}」款项 ￥${Number(row.totalprice).toFixed(2)}？确认后将置为已支付。`,
    '代收款确认',
    { confirmButtonText: '确认收款', cancelButtonText: '取消', type: 'warning' },
  )
    .then(async () => {
      try {
        await shopOrderIspay(row.id)
        ElMessage.success('代收款成功')
        fetchList()
      } catch {
        // 错误提示已由 axios 拦截器统一弹出
      }
    })
    .catch(() => {})
}

// ============ 备注（常用备注选取或手填） ============
const remarkVisible = ref(false)
const remarkRow = ref<ShopOrderItem | null>(null)
const remarkValue = ref('')
const remarkOptions = ref<string[]>([])

async function openRemark(row: ShopOrderItem) {
  remarkRow.value = row
  remarkValue.value = row.remark || ''
  remarkVisible.value = true
  try {
    const data = await getRemarkList({ page: 0 })
    remarkOptions.value = (data.list || []).filter((r) => r.status === 1).map((r) => r.content)
  } catch {
    remarkOptions.value = []
  }
}

async function handleRemarkSubmit() {
  if (!remarkRow.value) return
  const remark = (remarkValue.value || '').trim()
  if (!remark) {
    ElMessage.warning('请填写或选择备注内容')
    return
  }
  try {
    await setShopOrderRemark(remarkRow.value.id, remark)
    ElMessage.success('备注已保存')
    remarkVisible.value = false
    fetchList()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  }
}

// ============ 退款审核 ============
function handleRefundPass(row: ShopOrderItem) {
  const money = Number(row.refund_money) > 0 ? row.refund_money : row.totalprice
  ElMessageBox.confirm(
    `同意退款「${row.ordernum}」将原路退回 ￥${Number(money).toFixed(2)}，` +
      '并同步退回抵扣积分，确定吗？',
    '退款确认',
    { confirmButtonText: '同意退款', cancelButtonText: '取消', type: 'warning' },
  )
    .then(async () => {
      try {
        await checkShopOrderRefund(row.id, 1)
        ElMessage.success('已同意并退款')
        fetchList()
      } catch {
        // 错误提示已由 axios 拦截器统一弹出
      }
    })
    .catch(() => {})
}

function handleRefundReject(row: ShopOrderItem) {
  ElMessageBox.prompt('请输入驳回原因', '驳回退款', {
    confirmButtonText: '驳回',
    cancelButtonText: '取消',
    inputType: 'textarea',
    inputPlaceholder: '请填写驳回原因',
    type: 'warning',
  })
    .then(async ({ value }) => {
      try {
        await checkShopOrderRefund(row.id, 2, (value || '').trim())
        ElMessage.success('已驳回')
        fetchList()
      } catch {
        // 错误提示已由 axios 拦截器统一弹出
      }
    })
    .catch(() => {})
}

// ============ 补打小票（一期桩） ============
async function handlePrint(row: ShopOrderItem) {
  try {
    await printShopOrder(row.id)
    ElMessage.info('打印链路待接入')
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  }
}

onMounted(fetchList)
</script>

<template>
  <div>
    <el-card shadow="never">
      <!-- 今日统计 -->
      <div class="stat-bar">
        <div class="stat-item">
          <div class="stat-label">今日单数</div>
          <div class="stat-value">{{ stats.today_count }}</div>
        </div>
        <div class="stat-item">
          <div class="stat-label">今日成交额</div>
          <div class="stat-value">￥{{ Number(stats.today_money).toFixed(2) }}</div>
        </div>
      </div>

      <!-- 状态 tabs -->
      <el-tabs v-model="activeTab" @tab-change="handleTabChange">
        <el-tab-pane label="全部" name="" />
        <el-tab-pane label="未支付" name="0" />
        <el-tab-pane label="已支付" name="1" />
        <el-tab-pane label="已完成" name="3" />
        <el-tab-pane label="已关闭" name="4" />
        <el-tab-pane label="退款审核中" name="r1" />
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
        <el-form-item label="桌号">
          <el-input
            v-model="query.table_name"
            placeholder="桌号"
            clearable
            style="width: 120px"
            @keyup.enter="handleSearch"
          />
        </el-form-item>
        <el-form-item label="会员ID">
          <el-input
            v-model.number="query.mid"
            placeholder="会员ID"
            clearable
            style="width: 110px"
            @keyup.enter="handleSearch"
          />
        </el-form-item>
        <el-form-item label="下单时间">
          <el-date-picker
            v-model="query.dateRange"
            type="daterange"
            range-separator="~"
            start-placeholder="开始日期"
            end-placeholder="结束日期"
            value-format="YYYY-MM-DD"
            style="width: 240px"
          />
        </el-form-item>
        <el-form-item>
          <el-button type="primary" @click="handleSearch">搜索</el-button>
          <el-button @click="handleReset">重置</el-button>
        </el-form-item>
      </el-form>

      <!-- 订单列表 -->
      <el-table v-loading="loading" :data="tableData" stripe>
        <el-table-column prop="ordernum" label="订单号" width="190" />
        <el-table-column prop="table_name" label="桌号" width="90" />
        <el-table-column label="会员" min-width="110">
          <template #default="{ row }">
            <span>{{ row.member_nickname || (row.mid ? `会员${row.mid}` : '散客') }}</span>
          </template>
        </el-table-column>
        <el-table-column label="金额" width="100">
          <template #default="{ row }">￥{{ Number(row.totalprice).toFixed(2) }}</template>
        </el-table-column>
        <el-table-column label="状态" width="120">
          <template #default="{ row }">
            <el-tag :type="statusTagType(row.status)" size="small">{{ row.status_text }}</el-tag>
            <div v-if="row.refund_text" class="refund-tip">
              <el-tag
                size="small"
                :type="row.refund_status === 1 ? 'warning' : row.refund_status === 2 ? 'danger' : 'info'"
              >
                {{ row.refund_text }}
              </el-tag>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="下单时间" width="160">
          <template #default="{ row }">{{ formatTime(row.createtime) }}</template>
        </el-table-column>
        <el-table-column label="操作" width="260" fixed="right">
          <template #default="{ row }">
            <el-button link type="primary" @click="openDetail(row)">详情</el-button>
            <template v-if="row.status === 0">
              <el-button link type="primary" @click="openPrice(row)">改价</el-button>
              <el-button link type="success" @click="handleIspay(row)">代收款</el-button>
              <el-button link type="danger" @click="handleClose(row)">关单</el-button>
            </template>
            <template v-if="row.refund_status === 1">
              <el-button link type="success" @click="handleRefundPass(row)">同意退款</el-button>
              <el-button link type="warning" @click="handleRefundReject(row)">驳回</el-button>
            </template>
            <el-button link type="primary" @click="openRemark(row)">备注</el-button>
            <el-button v-if="row.status !== 0" link type="info" @click="handlePrint(row)">补打</el-button>
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

    <!-- 详情抽屉 -->
    <el-drawer v-model="drawerVisible" title="订单详情" size="520px">
      <div v-loading="drawerLoading">
        <template v-if="detailData">
          <el-descriptions :column="2" border size="small">
            <el-descriptions-item label="订单号" :span="2">{{ detailData.order.ordernum }}</el-descriptions-item>
            <el-descriptions-item label="状态">
              <el-tag :type="statusTagType(detailData.order.status)" size="small">
                {{ detailData.order.status_text }}
              </el-tag>
              <el-tag
                v-if="detailData.order.refund_text"
                size="small"
                type="warning"
                style="margin-left: 4px"
              >
                {{ detailData.order.refund_text }}
              </el-tag>
            </el-descriptions-item>
            <el-descriptions-item label="桌台">
              {{ detailData.table ? `${detailData.table.area_name || ''} ${detailData.table.name}` : '-' }}
            </el-descriptions-item>
            <el-descriptions-item label="会员">
              {{ detailData.member ? detailData.member.nickname || `会员${detailData.member.mid}` : '散客' }}
            </el-descriptions-item>
            <el-descriptions-item label="手机号">{{ detailData.member?.tel || '-' }}</el-descriptions-item>
            <el-descriptions-item label="商品总额">
              ￥{{ Number(detailData.order.product_price).toFixed(2) }}
            </el-descriptions-item>
            <el-descriptions-item label="应付金额">
              ￥{{ Number(detailData.order.totalprice).toFixed(2) }}
            </el-descriptions-item>
            <el-descriptions-item label="优惠券抵扣">
              ￥{{ Number(detailData.order.coupon_money).toFixed(2) }}
            </el-descriptions-item>
            <el-descriptions-item label="积分抵扣">
              {{ detailData.order.use_score }} 分（￥{{ Number(detailData.order.score_money).toFixed(2) }}）
            </el-descriptions-item>
            <el-descriptions-item label="支付方式">{{ detailData.order.paytype || '-' }}</el-descriptions-item>
            <el-descriptions-item label="支付时间">{{ formatTime(detailData.order.pay_time) }}</el-descriptions-item>
            <el-descriptions-item label="下单时间" :span="2">{{ formatTime(detailData.order.createtime) }}</el-descriptions-item>
            <el-descriptions-item v-if="detailData.order.remark" label="备注" :span="2">
              {{ detailData.order.remark }}
            </el-descriptions-item>
            <el-descriptions-item v-if="detailData.order.refund_reason" label="退款原因" :span="2">
              {{ detailData.order.refund_reason }}
            </el-descriptions-item>
          </el-descriptions>

          <el-divider content-position="left">商品明细</el-divider>
          <el-table :data="detailData.goods" size="small" border>
            <el-table-column prop="name" label="菜品" min-width="120" />
            <el-table-column prop="num" label="数量" width="70" />
            <el-table-column label="单价" width="90">
              <template #default="{ row }">￥{{ Number(row.price).toFixed(2) }}</template>
            </el-table-column>
            <el-table-column label="小计" width="90">
              <template #default="{ row }">￥{{ (Number(row.price) * row.num).toFixed(2) }}</template>
            </el-table-column>
          </el-table>
        </template>
      </div>
    </el-drawer>

    <!-- 改价弹窗 -->
    <el-dialog v-model="priceVisible" title="订单改价" width="420px">
      <el-alert type="warning" :closable="false" style="margin-bottom: 12px">
        改价后将重新生成订单号并同步支付单，原支付单号作废。
      </el-alert>
      <el-form label-width="90px" label-position="left">
        <el-form-item label="订单号">
          <span>{{ priceRow?.ordernum }}</span>
        </el-form-item>
        <el-form-item label="新金额">
          <el-input v-model="priceValue" placeholder="请输入新金额">
            <template #prepend>￥</template>
          </el-input>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="priceVisible = false">取消</el-button>
        <el-button type="primary" @click="handlePriceSubmit">确定</el-button>
      </template>
    </el-dialog>

    <!-- 备注弹窗 -->
    <el-dialog v-model="remarkVisible" title="设置备注" width="420px">
      <el-form label-width="90px" label-position="left">
        <el-form-item label="订单号">
          <span>{{ remarkRow?.ordernum }}</span>
        </el-form-item>
        <el-form-item label="备注内容">
          <el-select
            v-model="remarkValue"
            filterable
            allow-create
            default-first-option
            placeholder="选择常用备注或手动输入"
            style="width: 100%"
          >
            <el-option v-for="item in remarkOptions" :key="item" :label="item" :value="item" />
          </el-select>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="remarkVisible = false">取消</el-button>
        <el-button type="primary" @click="handleRemarkSubmit">确定</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped>
.stat-bar {
  display: flex;
  gap: 32px;
  margin-bottom: 8px;
}

.stat-item {
  min-width: 120px;
}

.stat-label {
  color: #909399;
  font-size: 12px;
}

.stat-value {
  font-size: 20px;
  font-weight: 600;
  color: #303133;
}

.pagination {
  margin-top: 16px;
  justify-content: flex-end;
}

.refund-tip {
  margin-top: 4px;
}
</style>
