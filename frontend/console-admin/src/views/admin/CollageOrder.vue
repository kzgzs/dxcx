<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import type { FormInstance, FormRules } from 'element-plus'
import {
  changeCollageOrderPrice,
  checkCollageOrderRefund,
  closeCollageOrder,
  getCollageOrderList,
  sendCollageOrderExpress,
} from '../../api/collage'
import type { CollageOrderItem } from '../../api/types'
import { formatTime } from '../../utils/time'

// ============ 列表 ============
const loading = ref(false)
const tableData = ref<CollageOrderItem[]>([])
const total = ref(0)

// tabs：''全部 0-4 订单状态 r1-r3 退款态
const activeTab = ref('')

const query = reactive({
  page: 1,
  pagesize: 10,
  keyword: '',
  buytype: '' as number | '',
})

async function fetchList() {
  loading.value = true
  try {
    const data = await getCollageOrderList({
      page: query.page,
      pagesize: query.pagesize,
      status: activeTab.value || undefined,
      buytype: query.buytype === '' ? undefined : query.buytype,
      keyword: query.keyword || undefined,
    })
    tableData.value = data.list || []
    total.value = data.total || 0
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
  query.keyword = ''
  query.buytype = ''
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

// 订单状态标签配色
function statusTagType(status: number) {
  if (status === 0) return 'info'
  if (status === 4) return 'danger'
  return 'primary'
}

// ============ 改价（仅未支付） ============
const priceVisible = ref(false)
const priceRow = ref<CollageOrderItem | null>(null)
const priceValue = ref<string>('')

function openPrice(row: CollageOrderItem) {
  priceRow.value = row
  priceValue.value = String(row.totalprice)
  priceVisible.value = true
}

async function handlePriceSubmit() {
  if (!priceRow.value) return
  const price = Number(priceValue.value)
  if (!(price >= 0)) {
    ElMessage.warning('请输入有效金额')
    return
  }
  try {
    await changeCollageOrderPrice(priceRow.value.id, price)
    ElMessage.success('改价成功')
    priceVisible.value = false
    fetchList()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  }
}

// ============ 关单（仅未支付） ============
function handleClose(row: CollageOrderItem) {
  ElMessageBox.confirm(`确定要关闭订单「${row.ordernum}」吗？关闭后不可恢复。`, '关单确认', {
    confirmButtonText: '关闭订单',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await closeCollageOrder(row.id)
        ElMessage.success('订单已关闭')
        fetchList()
      } catch {
        // 错误提示已由 axios 拦截器统一弹出
      }
    })
    .catch(() => {})
}

// ============ 退款审核 ============
function handleRefundPass(row: CollageOrderItem) {
  ElMessageBox.confirm(
    `同意退款「${row.ordernum}」将原路退回 ￥${Number(row.totalprice).toFixed(2)}，` +
      '并同步退回抵扣积分与优惠券，确定吗？',
    '退款确认',
    {
      confirmButtonText: '同意退款',
      cancelButtonText: '取消',
      type: 'warning',
    },
  )
    .then(async () => {
      try {
        await checkCollageOrderRefund(row.id, 1)
        ElMessage.success('已同意并退款')
        fetchList()
      } catch {
        // 错误提示已由 axios 拦截器统一弹出
      }
    })
    .catch(() => {})
}

function handleRefundReject(row: CollageOrderItem) {
  ElMessageBox.prompt('请输入驳回原因', '驳回退款', {
    confirmButtonText: '驳回',
    cancelButtonText: '取消',
    inputType: 'textarea',
    inputPlaceholder: '请填写驳回原因',
    type: 'warning',
  })
    .then(async ({ value }) => {
      try {
        await checkCollageOrderRefund(row.id, 2, (value || '').trim())
        ElMessage.success('已驳回')
        fetchList()
      } catch {
        // 错误提示已由 axios 拦截器统一弹出
      }
    })
    .catch(() => {})
}

// ============ 发货 ============
const shipVisible = ref(false)
const shipRow = ref<CollageOrderItem | null>(null)
const shipFormRef = ref<FormInstance>()
const shipSubmitting = ref(false)
const shipForm = reactive({
  express_com: '',
  express_no: '',
})

const shipRules: FormRules = {
  express_com: [{ required: true, message: '请输入快递公司', trigger: 'blur' }],
  express_no: [{ required: true, message: '请输入快递单号', trigger: 'blur' }],
}

function openShip(row: CollageOrderItem) {
  shipRow.value = row
  shipForm.express_com = row.express_com
  shipForm.express_no = row.express_no
  shipVisible.value = true
}

async function handleShipSubmit() {
  if (!shipFormRef.value || !shipRow.value) return
  await shipFormRef.value.validate(async (valid) => {
    if (!valid) return
    shipSubmitting.value = true
    try {
      await sendCollageOrderExpress(shipRow.value!.id, shipForm.express_com, shipForm.express_no)
      ElMessage.success('发货成功')
      shipVisible.value = false
      fetchList()
    } catch {
      // 错误提示已由 axios 拦截器统一弹出
    } finally {
      shipSubmitting.value = false
    }
  })
}

onMounted(fetchList)
</script>

<template>
  <div>
    <el-card shadow="never">
      <!-- 状态 tabs：订单状态 + 退款态 -->
      <el-tabs v-model="activeTab" @tab-change="handleTabChange">
        <el-tab-pane label="全部" name="" />
        <el-tab-pane label="未支付" name="0" />
        <el-tab-pane label="已支付" name="1" />
        <el-tab-pane label="已发货" name="2" />
        <el-tab-pane label="已收货" name="3" />
        <el-tab-pane label="已关闭" name="4" />
        <el-tab-pane label="退款审核中" name="r1" />
        <el-tab-pane label="已退款" name="r2" />
        <el-tab-pane label="退款驳回" name="r3" />
      </el-tabs>

      <!-- 搜索工具栏 -->
      <el-form inline @submit.prevent>
        <el-form-item label="关键词">
          <el-input
            v-model="query.keyword"
            placeholder="订单号/商品名/收货人/手机号"
            clearable
            style="width: 240px"
            @keyup.enter="handleSearch"
          />
        </el-form-item>
        <el-form-item label="购买方式">
          <el-select v-model="query.buytype" placeholder="全部" clearable style="width: 120px">
            <el-option label="单买" :value="1" />
            <el-option label="发团" :value="2" />
            <el-option label="参团" :value="3" />
          </el-select>
        </el-form-item>
        <el-form-item>
          <el-button type="primary" @click="handleSearch">搜索</el-button>
          <el-button @click="handleReset">重置</el-button>
        </el-form-item>
      </el-form>

      <!-- 订单列表 -->
      <el-table v-loading="loading" :data="tableData" stripe>
        <el-table-column prop="ordernum" label="订单号" width="190" />
        <el-table-column label="购买方式" width="90">
          <template #default="{ row }">
            <el-tag
              :type="row.buytype === 2 ? 'warning' : row.buytype === 3 ? 'success' : 'info'"
              size="small"
            >
              {{ row.buytype_text }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="商品" min-width="180">
          <template #default="{ row }">
            <div class="order-product">
              <el-image
                v-if="row.propic"
                :src="row.propic"
                fit="cover"
                style="width: 36px; height: 36px; border-radius: 4px"
              />
              <div>
                <div class="product-name">{{ row.proname }}</div>
                <div v-if="row.ggname" class="text-muted">{{ row.ggname }} × {{ row.num }}</div>
              </div>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="买家" min-width="110">
          <template #default="{ row }">
            <span :class="{ 'text-muted': row.buyer.isjiqiren === 1 }">
              {{ row.buyer.nickname || `会员${row.buyer.mid}` }}
            </span>
          </template>
        </el-table-column>
        <el-table-column label="金额" width="100">
          <template #default="{ row }">￥{{ Number(row.totalprice).toFixed(2) }}</template>
        </el-table-column>
        <el-table-column label="团" width="110">
          <template #default="{ row }">
            <span v-if="row.team">
              {{ row.team.num }}/{{ row.team.teamnum }}
              <el-tag size="small" :type="row.team.status === 2 ? 'success' : row.team.status === 3 ? 'danger' : 'warning'">
                {{ row.team.status_text }}
              </el-tag>
            </span>
            <span v-else class="text-muted">-</span>
          </template>
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
        <el-table-column label="操作" width="190" fixed="right">
          <template #default="{ row }">
            <template v-if="row.status === 0">
              <el-button link type="primary" @click="openPrice(row)">改价</el-button>
              <el-button link type="danger" @click="handleClose(row)">关单</el-button>
            </template>
            <template v-if="row.refund_status === 1">
              <el-button link type="success" @click="handleRefundPass(row)">同意退款</el-button>
              <el-button link type="warning" @click="handleRefundReject(row)">驳回</el-button>
            </template>
            <template v-if="row.status === 1">
              <el-button link type="primary" @click="openShip(row)">发货</el-button>
            </template>
            <template v-if="row.status === 2">
              <el-button link type="primary" @click="openShip(row)">改物流</el-button>
            </template>
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

    <!-- 改价弹窗 -->
    <el-dialog v-model="priceVisible" title="订单改价" width="400px">
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

    <!-- 发货弹窗 -->
    <el-dialog
      v-model="shipVisible"
      :title="shipRow?.status === 2 ? '修改物流' : '订单发货'"
      width="420px"
    >
      <el-form
        ref="shipFormRef"
        :model="shipForm"
        :rules="shipRules"
        label-width="90px"
        label-position="left"
      >
        <el-form-item label="订单号">
          <span>{{ shipRow?.ordernum }}</span>
        </el-form-item>
        <el-form-item label="快递公司" prop="express_com">
          <el-input v-model="shipForm.express_com" placeholder="如：顺丰速运" />
        </el-form-item>
        <el-form-item label="快递单号" prop="express_no">
          <el-input v-model="shipForm.express_no" placeholder="请输入快递单号" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="shipVisible = false">取消</el-button>
        <el-button type="primary" :loading="shipSubmitting" @click="handleShipSubmit">
          确定
        </el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped>
.pagination {
  margin-top: 16px;
  justify-content: flex-end;
}

.order-product {
  display: flex;
  align-items: center;
  gap: 8px;
}

.product-name {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  max-width: 200px;
}

.text-muted {
  color: #909399;
  font-size: 12px;
}

.refund-tip {
  margin-top: 4px;
}
</style>
