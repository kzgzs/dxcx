<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  checkBookingOrder,
  closeBookingOrder,
  getBookingOrderDetail,
  getBookingOrderList,
  setBookingOrderRemark,
} from '../../api/restaurantBooking'
import type { BookingOrderDetailResult, BookingOrderItem } from '../../api/restaurantBooking'
import { formatTime } from '../../utils/time'

// ============ 列表与统计 ============
const loading = ref(false)
const tableData = ref<BookingOrderItem[]>([])
const total = ref(0)
const stats = ref({ today_count: 0, today_money: 0 })

// tabs：''全部 0待支付 1待审核 3已完成 4已关闭 refund退款
const activeTab = ref('')

const query = reactive({
  page: 1,
  pagesize: 10,
  ordernum: '',
  linkman: '',
  tel: '',
  table_name: '',
  dateRange: '' as [string, string] | '',
})

async function fetchList() {
  loading.value = true
  try {
    const data = await getBookingOrderList({
      page: query.page,
      pagesize: query.pagesize,
      st: activeTab.value || undefined,
      ordernum: query.ordernum || undefined,
      linkman: query.linkman || undefined,
      tel: query.tel || undefined,
      table_name: query.table_name || undefined,
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
  query.linkman = ''
  query.tel = ''
  query.table_name = ''
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
  return 'primary'
}

function checkTagType(check: number) {
  if (check === 1) return 'success'
  if (check === -1) return 'danger'
  return 'warning'
}

/** 待审核：已支付且 check_status=0 */
function isPendingCheck(row: BookingOrderItem) {
  return row.status === 1 && row.check_status === 0
}

// ============ 详情抽屉 ============
const drawerVisible = ref(false)
const drawerLoading = ref(false)
const detailData = ref<BookingOrderDetailResult | null>(null)

async function openDetail(row: BookingOrderItem) {
  drawerVisible.value = true
  drawerLoading.value = true
  detailData.value = null
  try {
    detailData.value = await getBookingOrderDetail(row.id)
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    drawerLoading.value = false
  }
}

// ============ 审核通过 / 驳回 ============
function handleAccess(row: BookingOrderItem) {
  ElMessageBox.confirm(`确定通过预定单「${row.ordernum}」的审核吗？通过后将以短信通知会员。`, '审核确认', {
    confirmButtonText: '通过',
    cancelButtonText: '取消',
    type: 'success',
  })
    .then(async () => {
      try {
        await checkBookingOrder(row.id, 'access')
        ElMessage.success('已通过审核')
        fetchList()
      } catch {
        // 错误提示已由 axios 拦截器统一弹出
      }
    })
    .catch(() => {})
}

function handleRefuse(row: BookingOrderItem) {
  const tip =
    row.status === 1 && Number(row.totalprice) > 0
      ? `驳回后已付定金 ￥${Number(row.totalprice).toFixed(2)} 将自动原路退款并关闭订单。`
      : '驳回后订单将关闭。'
  ElMessageBox.confirm(`确定驳回预定单「${row.ordernum}」吗？${tip}`, '驳回确认', {
    confirmButtonText: '驳回',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await checkBookingOrder(row.id, 'refuse')
        ElMessage.success('已驳回并处理完成')
        fetchList()
      } catch {
        // 错误提示已由 axios 拦截器统一弹出
      }
    })
    .catch(() => {})
}

// ============ 关单（仅未支付） ============
function handleClose(row: BookingOrderItem) {
  ElMessageBox.confirm(`确定要关闭预定单「${row.ordernum}」吗？关闭后不可恢复。`, '关单确认', {
    confirmButtonText: '关闭订单',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await closeBookingOrder(row.id)
        ElMessage.success('订单已关闭')
        fetchList()
      } catch {
        // 错误提示已由 axios 拦截器统一弹出
      }
    })
    .catch(() => {})
}

// ============ 备注 ============
const remarkVisible = ref(false)
const remarkRow = ref<BookingOrderItem | null>(null)
const remarkValue = ref('')

function openRemark(row: BookingOrderItem) {
  remarkRow.value = row
  remarkValue.value = row.remark || ''
  remarkVisible.value = true
}

async function handleRemarkSubmit() {
  if (!remarkRow.value) return
  const remark = (remarkValue.value || '').trim()
  if (!remark) {
    ElMessage.warning('请填写备注内容')
    return
  }
  try {
    await setBookingOrderRemark(remarkRow.value.id, remark)
    ElMessage.success('备注已保存')
    remarkVisible.value = false
    fetchList()
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
          <div class="stat-label">今日预定</div>
          <div class="stat-value">{{ stats.today_count }}</div>
        </div>
        <div class="stat-item">
          <div class="stat-label">今日定金成交</div>
          <div class="stat-value">￥{{ Number(stats.today_money).toFixed(2) }}</div>
        </div>
      </div>

      <!-- 状态 tabs -->
      <el-tabs v-model="activeTab" @tab-change="handleTabChange">
        <el-tab-pane label="全部" name="" />
        <el-tab-pane label="待支付" name="0" />
        <el-tab-pane label="待审核" name="1" />
        <el-tab-pane label="已完成" name="3" />
        <el-tab-pane label="已关闭" name="4" />
        <el-tab-pane label="退款" name="refund" />
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
        <el-form-item label="联系人">
          <el-input
            v-model="query.linkman"
            placeholder="联系人"
            clearable
            style="width: 120px"
            @keyup.enter="handleSearch"
          />
        </el-form-item>
        <el-form-item label="手机号">
          <el-input
            v-model="query.tel"
            placeholder="手机号"
            clearable
            style="width: 140px"
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
            <span>{{ row.member_nickname || (row.mid ? `会员${row.mid}` : '-') }}</span>
          </template>
        </el-table-column>
        <el-table-column prop="booking_time" label="预定时段" min-width="150" />
        <el-table-column prop="seat" label="人数" width="70" />
        <el-table-column label="定金" width="90">
          <template #default="{ row }">￥{{ Number(row.totalprice).toFixed(2) }}</template>
        </el-table-column>
        <el-table-column label="状态" width="130">
          <template #default="{ row }">
            <el-tag :type="statusTagType(row.status)" size="small">{{ row.status_text }}</el-tag>
            <div v-if="row.status !== 4" class="check-tip">
              <el-tag :type="checkTagType(row.check_status)" size="small">{{ row.check_text }}</el-tag>
            </div>
            <div v-if="row.refund_text" class="check-tip">
              <el-tag size="small" type="danger">{{ row.refund_text }}</el-tag>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="下单时间" width="160">
          <template #default="{ row }">{{ formatTime(row.createtime) }}</template>
        </el-table-column>
        <el-table-column label="操作" width="240" fixed="right">
          <template #default="{ row }">
            <el-button link type="primary" @click="openDetail(row)">详情</el-button>
            <template v-if="isPendingCheck(row)">
              <el-button link type="success" @click="handleAccess(row)">通过</el-button>
              <el-button link type="danger" @click="handleRefuse(row)">驳回</el-button>
            </template>
            <el-button v-if="row.status === 0" link type="danger" @click="handleClose(row)">关单</el-button>
            <el-button link type="primary" @click="openRemark(row)">备注</el-button>
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
    <el-drawer v-model="drawerVisible" title="预定详情" size="520px">
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
                type="danger"
                style="margin-left: 4px"
              >
                {{ detailData.order.refund_text }}
              </el-tag>
            </el-descriptions-item>
            <el-descriptions-item label="审核">
              <el-tag :type="checkTagType(detailData.order.check_status)" size="small">
                {{ detailData.order.check_text }}
              </el-tag>
            </el-descriptions-item>
            <el-descriptions-item label="桌台">
              {{ detailData.table ? detailData.table.name : '-' }}
            </el-descriptions-item>
            <el-descriptions-item label="预定时段">{{ detailData.order.booking_time || '-' }}</el-descriptions-item>
            <el-descriptions-item label="用餐人数">{{ detailData.order.seat }} 人</el-descriptions-item>
            <el-descriptions-item label="联系人">{{ detailData.order.linkman || '-' }}</el-descriptions-item>
            <el-descriptions-item label="手机号">{{ detailData.order.tel || '-' }}</el-descriptions-item>
            <el-descriptions-item label="会员">
              {{ detailData.member ? detailData.member.nickname || `会员${detailData.member.mid}` : '-' }}
            </el-descriptions-item>
            <el-descriptions-item label="定金金额">
              ￥{{ Number(detailData.order.totalprice).toFixed(2) }}
            </el-descriptions-item>
            <el-descriptions-item label="支付方式">{{ detailData.order.paytype || '-' }}</el-descriptions-item>
            <el-descriptions-item label="支付时间">{{ formatTime(detailData.order.pay_time) }}</el-descriptions-item>
            <el-descriptions-item label="下单时间" :span="2">{{ formatTime(detailData.order.createtime) }}</el-descriptions-item>
            <el-descriptions-item v-if="detailData.order.message" label="留言" :span="2">
              {{ detailData.order.message }}
            </el-descriptions-item>
            <el-descriptions-item v-if="detailData.order.remark" label="备注" :span="2">
              {{ detailData.order.remark }}
            </el-descriptions-item>
            <el-descriptions-item v-if="detailData.order.refund_reason" label="退款原因" :span="2">
              {{ detailData.order.refund_reason }}（￥{{ Number(detailData.order.refund_money).toFixed(2) }}）
            </el-descriptions-item>
          </el-descriptions>
        </template>
      </div>
    </el-drawer>

    <!-- 备注弹窗 -->
    <el-dialog v-model="remarkVisible" title="设置备注" width="420px">
      <el-form label-width="90px" label-position="left">
        <el-form-item label="订单号">
          <span>{{ remarkRow?.ordernum }}</span>
        </el-form-item>
        <el-form-item label="备注内容">
          <el-input
            v-model="remarkValue"
            type="textarea"
            :rows="3"
            maxlength="200"
            show-word-limit
            placeholder="请输入备注内容"
          />
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

.check-tip {
  margin-top: 4px;
}
</style>
