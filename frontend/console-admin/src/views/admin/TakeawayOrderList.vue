<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import type { FormInstance, FormRules } from 'element-plus'
import {
  acceptTakeawayOrder,
  closeTakeawayOrder,
  getTakeawayOrderDetail,
  getTakeawayOrderList,
  sendTakeawayOrderExpress,
} from '../../api/restaurantOrder'
import type { TakeawayOrderDetailResult, TakeawayOrderItem } from '../../api/restaurantOrder'
import { formatTime } from '../../utils/time'

// ============ 列表 ============
const loading = ref(false)
const tableData = ref<TakeawayOrderItem[]>([])
const total = ref(0)

// tabs：''全部 0未支付 1已支付 12接单 2发货 3完成 4关闭
const activeTab = ref('')

const query = reactive({
  page: 1,
  pagesize: 10,
  keyword: '',
  mid: '' as number | '',
  dateRange: '' as [string, string] | '',
})

async function fetchList() {
  loading.value = true
  try {
    const data = await getTakeawayOrderList({
      page: query.page,
      pagesize: query.pagesize,
      status: activeTab.value || undefined,
      keyword: query.keyword || undefined,
      mid: query.mid === '' ? undefined : query.mid,
      start_time: Array.isArray(query.dateRange) ? query.dateRange[0] : undefined,
      end_time: Array.isArray(query.dateRange) ? query.dateRange[1] : undefined,
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
  if (status === 12) return 'warning'
  if (status === 3) return 'success'
  return 'primary'
}

// ============ 详情抽屉 ============
const drawerVisible = ref(false)
const drawerLoading = ref(false)
const detailData = ref<TakeawayOrderDetailResult | null>(null)

async function openDetail(row: TakeawayOrderItem) {
  drawerVisible.value = true
  drawerLoading.value = true
  detailData.value = null
  try {
    detailData.value = await getTakeawayOrderDetail(row.id)
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    drawerLoading.value = false
  }
}

// ============ 接单（已支付 → 12） ============
function handleAccept(row: TakeawayOrderItem) {
  ElMessageBox.confirm(`确认接下订单「${row.ordernum}」吗？`, '接单确认', {
    confirmButtonText: '接单',
    cancelButtonText: '取消',
    type: 'info',
  })
    .then(async () => {
      try {
        await acceptTakeawayOrder(row.id)
        ElMessage.success('已接单')
        fetchList()
      } catch {
        // 错误提示已由 axios 拦截器统一弹出
      }
    })
    .catch(() => {})
}

// ============ 关单（仅未支付） ============
function handleClose(row: TakeawayOrderItem) {
  ElMessageBox.confirm(`确定要关闭订单「${row.ordernum}」吗？关闭后不可恢复。`, '关单确认', {
    confirmButtonText: '关闭订单',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await closeTakeawayOrder(row.id)
        ElMessage.success('订单已关闭')
        fetchList()
      } catch {
        // 错误提示已由 axios 拦截器统一弹出
      }
    })
    .catch(() => {})
}

// ============ 发货（二期桩：表单同构，后端返回待接入提示） ============
const shipVisible = ref(false)
const shipRow = ref<TakeawayOrderItem | null>(null)
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

function openShip(row: TakeawayOrderItem) {
  shipRow.value = row
  shipForm.express_com = row.express_com || ''
  shipForm.express_no = row.express_no || ''
  shipVisible.value = true
}

async function handleShipSubmit() {
  if (!shipFormRef.value || !shipRow.value) return
  await shipFormRef.value.validate(async (valid) => {
    if (!valid) return
    shipSubmitting.value = true
    try {
      await sendTakeawayOrderExpress(shipRow.value!.id, shipForm.express_com, shipForm.express_no)
      ElMessage.success('发货成功')
      shipVisible.value = false
      fetchList()
    } catch {
      // 一期桩：后端返回「二期待接入」提示，由 axios 拦截器统一弹出
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
      <!-- 状态 tabs（含 12 接单态） -->
      <el-tabs v-model="activeTab" @tab-change="handleTabChange">
        <el-tab-pane label="全部" name="" />
        <el-tab-pane label="未支付" name="0" />
        <el-tab-pane label="已支付" name="1" />
        <el-tab-pane label="已接单" name="12" />
        <el-tab-pane label="已发货" name="2" />
        <el-tab-pane label="已完成" name="3" />
        <el-tab-pane label="已关闭" name="4" />
      </el-tabs>

      <!-- 搜索工具栏 -->
      <el-form inline @submit.prevent>
        <el-form-item label="关键词">
          <el-input
            v-model="query.keyword"
            placeholder="订单号/收货人/手机号"
            clearable
            style="width: 220px"
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
        <el-table-column label="收货人" min-width="130">
          <template #default="{ row }">
            <div>{{ row.linkman || '-' }}</div>
            <div class="text-muted">{{ row.tel }}</div>
          </template>
        </el-table-column>
        <el-table-column prop="address" label="收货地址" min-width="180" show-overflow-tooltip />
        <el-table-column label="会员" min-width="100">
          <template #default="{ row }">
            <span>{{ row.member_nickname || (row.mid ? `会员${row.mid}` : '散客') }}</span>
          </template>
        </el-table-column>
        <el-table-column label="金额" width="100">
          <template #default="{ row }">￥{{ Number(row.totalprice).toFixed(2) }}</template>
        </el-table-column>
        <el-table-column label="状态" width="110">
          <template #default="{ row }">
            <el-tag :type="statusTagType(row.status)" size="small">{{ row.status_text }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="下单时间" width="160">
          <template #default="{ row }">{{ formatTime(row.createtime) }}</template>
        </el-table-column>
        <el-table-column label="操作" width="200" fixed="right">
          <template #default="{ row }">
            <el-button link type="primary" @click="openDetail(row)">详情</el-button>
            <el-button v-if="row.status === 1" link type="warning" @click="handleAccept(row)">接单</el-button>
            <el-button v-if="row.status === 0" link type="danger" @click="handleClose(row)">关单</el-button>
            <el-button v-if="row.status === 1 || row.status === 12" link type="primary" @click="openShip(row)">
              发货
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
        @current-change="handlePageChange"
        @size-change="handleSizeChange"
      />
    </el-card>

    <!-- 详情抽屉 -->
    <el-drawer v-model="drawerVisible" title="外卖订单详情" size="520px">
      <div v-loading="drawerLoading">
        <template v-if="detailData">
          <el-descriptions :column="2" border size="small">
            <el-descriptions-item label="订单号" :span="2">{{ detailData.order.ordernum }}</el-descriptions-item>
            <el-descriptions-item label="状态">
              <el-tag :type="statusTagType(detailData.order.status)" size="small">
                {{ detailData.order.status_text }}
              </el-tag>
            </el-descriptions-item>
            <el-descriptions-item label="会员">
              {{ detailData.member ? detailData.member.nickname || `会员${detailData.member.mid}` : '散客' }}
            </el-descriptions-item>
            <el-descriptions-item label="收货人">{{ detailData.order.linkman || '-' }}</el-descriptions-item>
            <el-descriptions-item label="手机号">{{ detailData.order.tel || '-' }}</el-descriptions-item>
            <el-descriptions-item label="收货地址" :span="2">{{ detailData.order.address || '-' }}</el-descriptions-item>
            <el-descriptions-item label="商品总额">
              ￥{{ Number(detailData.order.product_price).toFixed(2) }}
            </el-descriptions-item>
            <el-descriptions-item label="打包费">
              ￥{{ Number(detailData.order.pack_fee).toFixed(2) }}
            </el-descriptions-item>
            <el-descriptions-item label="配送费">
              ￥{{ Number(detailData.order.freight_price).toFixed(2) }}
            </el-descriptions-item>
            <el-descriptions-item label="应付金额">
              ￥{{ Number(detailData.order.totalprice).toFixed(2) }}
            </el-descriptions-item>
            <el-descriptions-item label="支付方式">{{ detailData.order.paytype || '-' }}</el-descriptions-item>
            <el-descriptions-item label="支付时间">{{ formatTime(detailData.order.pay_time) }}</el-descriptions-item>
            <el-descriptions-item label="下单时间" :span="2">{{ formatTime(detailData.order.createtime) }}</el-descriptions-item>
            <el-descriptions-item v-if="detailData.order.message" label="买家留言" :span="2">
              {{ detailData.order.message }}
            </el-descriptions-item>
            <el-descriptions-item v-if="detailData.order.remark" label="商家备注" :span="2">
              {{ detailData.order.remark }}
            </el-descriptions-item>
          </el-descriptions>

          <el-divider content-position="left">商品明细</el-divider>
          <el-table :data="detailData.goods" size="small" border>
            <el-table-column prop="name" label="菜品" min-width="120" />
            <el-table-column prop="ggname" label="规格" width="90" />
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

    <!-- 发货弹窗（二期桩：提交后端返回待接入提示） -->
    <el-dialog v-model="shipVisible" title="订单发货" width="420px">
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

.text-muted {
  color: #909399;
  font-size: 12px;
}
</style>
