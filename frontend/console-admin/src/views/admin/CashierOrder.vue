<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  cashierRefund,
  cashierWifiprint,
  deleteCashierOrders,
  exportCashierOrders,
  getCashierOrderDetail,
  getCashierOrderList,
} from '../../api/cashier'
import type { CashierOrderItem } from '../../api/cashier'
import { formatTime } from '../../utils/time'

/* ============ 状态口径（对齐点大） ============ */
// 0待支付(红) 1已支付(绿) 2挂单(橙) 4已关闭(灰) 10已退款(灰带￥refund_money)
const STATUS_OPTIONS = [
  { value: '', label: '全部' },
  { value: '0', label: '待支付' },
  { value: '1', label: '已支付' },
  { value: '2', label: '挂单' },
  { value: '10', label: '已退款' },
  { value: '4', label: '已关闭' },
]
const STATUS_COLOR: Record<number, string> = {
  0: '#f56c6c',
  1: '#67c23a',
  2: '#e6a23c',
  4: '#909399',
  10: '#909399',
}

/* ============ 列表 ============ */
const loading = ref(false)
const exporting = ref(false)
const tableData = ref<CashierOrderItem[]>([])
const total = ref(0)
const hasprint = ref(0)

const query = reactive({
  page: 1,
  pagesize: 20,
  mid: '' as number | '',
  ordernum: '',
  status: '',
  dateRange: null as [string, string] | null,
})

async function fetchList() {
  loading.value = true
  try {
    const data = await getCashierOrderList({
      page: query.page,
      pagesize: query.pagesize,
      status: query.status === '' ? undefined : query.status,
      mid: query.mid === '' ? undefined : Number(query.mid),
      ordernum: query.ordernum || undefined,
      starttime: query.dateRange?.[0] || undefined,
      endtime: query.dateRange?.[1] || undefined,
    })
    tableData.value = data?.list || []
    total.value = data?.total || 0
    hasprint.value = Number((data as unknown as { hasprint?: number })?.hasprint ?? 0)
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

// 导出EXCEL（后端返回 title+data，前端生成带 BOM 的 CSV 下载）
function csvEscape(val: string): string {
  if (/[",\n]/.test(val)) return `"${val.replace(/"/g, '""')}"`
  return val
}
async function handleExport() {
  exporting.value = true
  try {
    const data = await exportCashierOrders({
      status: query.status === '' ? undefined : query.status,
      mid: query.mid === '' ? undefined : Number(query.mid),
      ordernum: query.ordernum || undefined,
      starttime: query.dateRange?.[0] || undefined,
      endtime: query.dateRange?.[1] || undefined,
    })
    if (!data.count) {
      ElMessage.warning('没有可导出的数据')
      return
    }
    const lines = [data.title, ...data.data].map((row) => row.map((c) => csvEscape(String(c))).join(','))
    const blob = new Blob(['﻿' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8' })
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = `收银订单_${new Date().toISOString().slice(0, 10)}.csv`
    a.click()
    URL.revokeObjectURL(url)
    ElMessage.success(`已导出 ${data.count} 条记录`)
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    exporting.value = false
  }
}

/* ============ 详情（平铺主区域，右上 X 关闭） ============ */
const detailView = ref(false)
const detail = ref<CashierOrderItem | null>(null)

async function handleDetail(row: CashierOrderItem) {
  try {
    detail.value = await getCashierOrderDetail(row.id)
    detailView.value = true
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  }
}
function closeDetail() {
  detailView.value = false
  detail.value = null
}

/* ============ 退款（对齐点大：直接退款弹窗） ============ */
const refundDialog = ref(false)
const refundSaving = ref(false)
const refundRow = ref<CashierOrderItem | null>(null)
const refundForm = reactive({ refund_money: 0, remark: '' })

function handleRefund(row: CashierOrderItem) {
  refundRow.value = row
  refundForm.refund_money = Number(row.totalprice)
  refundForm.remark = ''
  refundDialog.value = true
}
async function submitRefund() {
  if (!refundRow.value) return
  if (refundForm.refund_money <= 0) {
    ElMessage.warning('退款金额有误')
    return
  }
  refundSaving.value = true
  try {
    await cashierRefund(refundRow.value.id, Number(refundForm.refund_money), refundForm.remark)
    ElMessage.success('退款成功')
    refundDialog.value = false
    fetchList()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    refundSaving.value = false
  }
}

/* ============ 打印小票（confirm 确认后提交） ============ */
function handlePrint(row: CashierOrderItem) {
  ElMessageBox.confirm('确定要打印小票吗?', '打印确认', {
    confirmButtonText: '确定',
    cancelButtonText: '取消',
    type: 'info',
  })
    .then(async () => {
      try {
        await cashierWifiprint(row.id)
        ElMessage.success('成功打印小票')
      } catch {
        // 错误提示已由 axios 拦截器统一弹出
      }
    })
    .catch(() => {})
}

/* ============ 删除 ============ */
function handleDelete(row: CashierOrderItem) {
  ElMessageBox.confirm('确定要删除吗？删除后无法恢复！', '删除确认', {
    confirmButtonText: '确定',
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
    <!-- 列表视图 -->
    <template v-if="!detailView">
      <el-card shadow="never" class="toolbar">
        <div class="toolbar-inner">
          <div class="toolbar-left">
            <el-button :loading="exporting" @click="handleExport">导出EXCEL</el-button>
          </div>
          <div class="toolbar-right">
            <span class="tf-label">会员ID</span>
            <el-input v-model="query.mid" placeholder="" style="width: 110px" @keyup.enter="handleSearch" />
            <span class="tf-label">订单号</span>
            <el-input v-model="query.ordernum" placeholder="" style="width: 180px" @keyup.enter="handleSearch" />
            <span class="tf-label">下单时间</span>
            <el-date-picker
              v-model="query.dateRange"
              type="daterange"
              range-separator="~"
              start-placeholder="开始"
              end-placeholder="结束"
              value-format="YYYY-MM-DD"
              style="width: 200px"
            />
            <span class="tf-label">状态</span>
            <el-select v-model="query.status" style="width: 100px">
              <el-option v-for="o in STATUS_OPTIONS" :key="o.value" :value="o.value" :label="o.label" />
            </el-select>
            <button class="search-btn" title="搜索" @click="handleSearch">
              <svg viewBox="0 0 16 16" width="14" height="14" fill="currentColor"><path d="M11.4 10.2a6 6 0 1 0-1.2 1.2l3.4 3.4 1.2-1.2-3.4-3.4zM7 11a4 4 0 1 1 0-8 4 4 0 0 1 0 8z"/></svg>
            </button>
          </div>
        </div>
      </el-card>

      <el-card shadow="never">
        <el-table v-loading="loading" :data="tableData" border>
          <el-table-column prop="id" label="ID" width="70" />
          <el-table-column prop="ordernum" label="订单编号" width="190" />
          <el-table-column label="会员信息" width="170">
            <template #default="{ row }">
              <div v-if="row.mid > 0" class="member-cell">
                <img :src="row.headimg || 'data:image/svg+xml;utf8,<svg xmlns=%22http://www.w3.org/2000/svg%22 width=%2250%22 height=%2250%22><rect width=%2250%22 height=%2250%22 fill=%22%23ddd%22/></svg>'" class="member-avatar" />
                <span>{{ row.nickname || `#${row.mid}` }}</span>
              </div>
              <span v-else class="none">暂无</span>
            </template>
          </el-table-column>
          <el-table-column label="实付金额" width="90">
            <template #default="{ row }">{{ Number(row.totalprice).toFixed(2) }}</template>
          </el-table-column>
          <el-table-column label="创建时间" width="160">
            <template #default="{ row }">{{ formatTime(row.createtime) }}</template>
          </el-table-column>
          <el-table-column label="付款时间" width="160">
            <template #default="{ row }">{{ row.paytime ? formatTime(row.paytime) : '' }}</template>
          </el-table-column>
          <el-table-column prop="paytype" label="支付方式" min-width="110">
            <template #default="{ row }">{{ row.paytype || '-' }}</template>
          </el-table-column>
          <el-table-column prop="admin_user" label="收银员" width="100" />
          <el-table-column label="状态" width="110">
            <template #default="{ row }">
              <span :style="{ color: STATUS_COLOR[row.status] || '#606266' }">
                {{ row.status_desc }}
                <template v-if="row.status === 10 && Number(row.refund_money) > 0">（￥{{ Number(row.refund_money).toFixed(2) }}）</template>
              </span>
            </template>
          </el-table-column>
          <el-table-column prop="remark" label="备注" min-width="100" show-overflow-tooltip>
            <template #default="{ row }">{{ row.remark || '' }}</template>
          </el-table-column>
          <el-table-column label="操作" width="200" fixed="right">
            <template #default="{ row }">
              <div class="op-cell">
                <el-button link type="primary" @click="handleDetail(row)">详情</el-button>
                <el-button
                  v-if="row.status === 1 && [0, 3].includes(row.refund_status)"
                  link type="primary" @click="handleRefund(row)"
                >退款</el-button>
                <el-button v-if="hasprint === 1" link type="primary" @click="handlePrint(row)">打印小票</el-button>
                <el-button link type="danger" @click="handleDelete(row)">删除</el-button>
              </div>
            </template>
          </el-table-column>
        </el-table>

        <el-pagination
          class="pager" background layout="total, sizes, prev, pager, next, jumper"
          :total="total" v-model:current-page="query.page" v-model:page-size="query.pagesize"
          :page-sizes="[10, 20, 50]" @current-change="fetchList" @size-change="handleSearch"
        />
      </el-card>

      <!-- 退款弹窗（对齐点大 500x380） -->
      <el-dialog v-model="refundDialog" title="订单退款" width="500px">
        <template v-if="refundRow">
          <el-form label-width="90px">
            <el-form-item label="实际支付：">
              <span class="red">￥{{ Number(refundRow.totalprice).toFixed(2) }}</span>
            </el-form-item>
            <el-form-item label="付款方式：">{{ refundRow.paytype || '-' }}</el-form-item>
            <el-form-item label="退款金额：">
              <el-input-number v-model="refundForm.refund_money" :min="0.01" :precision="2" style="width: 180px" />
            </el-form-item>
            <el-form-item label="退款备注：">
              <el-input v-model="refundForm.remark" type="textarea" :rows="2" maxlength="200" />
            </el-form-item>
          </el-form>
        </template>
        <template #footer>
          <el-button @click="refundDialog = false">取消</el-button>
          <el-button type="primary" :loading="refundSaving" @click="submitRefund">确定退款</el-button>
        </template>
      </el-dialog>
    </template>

    <!-- 订单详情：平铺当前主区域，右上角 X 关闭（对齐点大订单详情） -->
    <el-card v-else shadow="never" class="detail-card">
      <template #header>
        <div class="detail-header">
          <span>订单详情</span>
          <button class="close-x" title="关闭" @click="closeDetail">✕</button>
        </div>
      </template>
      <template v-if="detail">
        <div class="goods-list">
          <div v-for="g in detail.prolist || []" :key="g.id" class="goods-row">
            <img :src="g.propic" class="goods-pic" />
            <div class="goods-info">
              <div class="g-name">{{ g.proname }}</div>
              <div v-if="g.ggname" class="g-gg">{{ g.ggname }}</div>
              <div class="g-price">￥{{ Number(g.sell_price).toFixed(2) }}</div>
            </div>
            <div class="g-num">×{{ g.num }}</div>
          </div>
          <div v-if="!(detail.prolist || []).length" class="none">暂无商品</div>
        </div>
        <div class="order-info">
          <div class="item"><span class="t1">订单总价：</span><span class="t2 red">￥{{ Number(detail.pre_totalprice).toFixed(2) }}</span></div>
          <div class="item"><span class="t1">实际支付：</span><span class="t2 red">￥{{ Number(detail.totalprice).toFixed(2) }}</span></div>
          <div v-if="Number(detail.coupon_money) > 0" class="item"><span class="t1">优惠券抵扣：</span><span class="t2 red">￥{{ Number(detail.coupon_money).toFixed(2) }}</span></div>
          <div v-if="Number(detail.scoredk_money) > 0" class="item"><span class="t1">积分抵扣：</span><span class="t2 red">￥{{ Number(detail.scoredk_money).toFixed(2) }}</span></div>
          <div v-if="Number(detail.leveldk_money) > 0" class="item"><span class="t1">会员折扣：</span><span class="t2 red">￥{{ Number(detail.leveldk_money).toFixed(2) }}</span></div>
          <div v-if="Number(detail.moling_money) > 0" class="item"><span class="t1">订单抹零：</span><span class="t2 red">￥{{ Number(detail.moling_money).toFixed(2) }}</span></div>
          <div class="item"><span class="t1">订单编号：</span><span class="t2">{{ detail.ordernum }}</span></div>
          <div class="item"><span class="t1">下单时间：</span><span class="t2">{{ formatTime(detail.createtime) }}</span></div>
          <div class="item"><span class="t1">支付时间：</span><span class="t2">{{ detail.paytime ? formatTime(detail.paytime) : '暂无' }}</span></div>
          <div class="item"><span class="t1">支付方式：</span><span class="t2">{{ detail.paytype || '暂无' }}</span></div>
          <div class="item"><span class="t1">订单备注：</span><span class="t2">{{ detail.remark || '暂无' }}</span></div>
        </div>
        <div class="detail-footer">
          <el-button type="primary" @click="closeDetail">关闭</el-button>
        </div>
      </template>
    </el-card>
  </div>
</template>

<style scoped>
.toolbar { margin-bottom: 3px; }
.toolbar-inner { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: nowrap; }
.toolbar-left { display: flex; gap: 8px; }
.toolbar-right { display: flex; align-items: center; gap: 6px; flex-wrap: nowrap; }
.tf-label { font-size: 13px; color: #606266; white-space: nowrap; }
.search-btn { width: 30px; height: 30px; border: none; background: #67c23a; color: #fff; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; border-radius: 3px; }
.el-card { border-radius: 0; }
.el-table :deep(th) { background: #e6e6e6; text-align: left; }
.el-table :deep(td .cell) { text-align: left; }
.pager { margin-top: 12px; justify-content: flex-end; }
.member-cell { display: flex; align-items: center; gap: 6px; }
.member-avatar { width: 40px; height: 40px; border-radius: 4px; object-fit: cover; }
.none { color: #909399; }
.red { color: #f56c6c; }
.op-cell { display: flex; flex-wrap: wrap; gap: 2px 8px; }
/* 详情平铺 */
.detail-header { display: flex; align-items: center; justify-content: space-between; }
.close-x { width: 26px; height: 26px; border: 1px solid #dcdfe6; background: #fff; color: #606266; cursor: pointer; border-radius: 3px; }
.goods-list { border-bottom: 1px dashed #e5e5e5; padding-bottom: 6px; }
.goods-row { display: flex; align-items: center; padding: 6px 0; border-bottom: 1px dashed #e5e5e5; }
.goods-row:last-child { border-bottom: 0; }
.goods-pic { width: 60px; height: 60px; border-radius: 4px; object-fit: cover; }
.goods-info { flex: 1; margin-left: 8px; }
.g-name { color: #000; }
.g-gg { color: #999; font-size: 12px; }
.g-price { color: #ff4246; }
.g-num { width: 50px; text-align: right; color: #606266; }
.order-info { margin-top: 6px; }
.order-info .item { display: flex; padding: 6px 0; border-bottom: 1px dashed #ededed; }
.order-info .item:last-child { border-bottom: 0; }
.order-info .t1 { width: 100px; color: #606266; }
.order-info .t2 { flex: 1; }
.detail-footer { margin-top: 12px; }
</style>
