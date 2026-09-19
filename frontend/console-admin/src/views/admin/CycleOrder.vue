<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import type { FormInstance, FormRules } from 'element-plus'
import {
  checkCycleOrderRefund,
  collectCycleOrderStage,
  getCycleOrderDetail,
  getCycleOrderList,
  remarkCycleOrder,
  shipCycleOrderStage,
} from '../../api/cycle'
import type { CycleOrderDetail, CycleOrderItem, CycleStageItem } from '../../api/types'
import { formatTime } from '../../utils/time'

// ============ 订单列表 ============
const loading = ref(false)
const tableData = ref<CycleOrderItem[]>([])
const total = ref(0)

// tabs：''全部 0-4 订单状态 r1-r3 退款态
const activeTab = ref('')

const query = reactive({
  page: 1,
  pagesize: 10,
  ordernum: '',
  proname: '',
  linkman: '',
  tel: '',
})

async function fetchList() {
  loading.value = true
  try {
    const data = await getCycleOrderList({
      page: query.page,
      pagesize: query.pagesize,
      status: activeTab.value || undefined,
      ordernum: query.ordernum || undefined,
      proname: query.proname || undefined,
      linkman: query.linkman || undefined,
      tel: query.tel || undefined,
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
  query.ordernum = ''
  query.proname = ''
  query.linkman = ''
  query.tel = ''
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

function orderTagType(status: number) {
  if (status === 0) return 'info'
  if (status === 4) return 'danger'
  return 'primary'
}

function stageTagType(status: number) {
  if (status === 3) return 'success'
  if (status === 2) return 'primary'
  if (status === 1) return 'warning'
  return 'info'
}

// ============ 详情抽屉（含分期计划） ============
const drawerVisible = ref(false)
const detailLoading = ref(false)
const detail = ref<CycleOrderDetail | null>(null)

async function openDetail(row: CycleOrderItem) {
  drawerVisible.value = true
  detailLoading.value = true
  detail.value = null
  try {
    detail.value = await getCycleOrderDetail(row.id)
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    detailLoading.value = false
  }
}

async function refreshDetail() {
  if (!detail.value) return
  try {
    detail.value = await getCycleOrderDetail(detail.value.id)
    fetchList()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  }
}

// ============ 按期发货 ============
const shipVisible = ref(false)
const shipStage = ref<CycleStageItem | null>(null)
const shipOrderNo = ref('')
const shipFormRef = ref<FormInstance>()
const shipSubmitting = ref(false)
const shipForm = reactive({ express_com: '', express_no: '' })

const shipRules: FormRules = {
  express_com: [{ required: true, message: '请输入快递公司', trigger: 'blur' }],
  express_no: [{ required: true, message: '请输入快递单号', trigger: 'blur' }],
}

function openShip(stage: CycleStageItem) {
  shipStage.value = stage
  shipOrderNo.value = detail.value?.ordernum || ''
  shipForm.express_com = stage.express_com || ''
  shipForm.express_no = stage.express_no || ''
  shipVisible.value = true
}

async function handleShipSubmit() {
  if (!shipFormRef.value || !shipStage.value) return
  await shipFormRef.value.validate(async (valid) => {
    if (!valid) return
    shipSubmitting.value = true
    try {
      await shipCycleOrderStage(shipStage.value!.id, shipForm.express_com, shipForm.express_no)
      ElMessage.success('发货成功')
      shipVisible.value = false
      refreshDetail()
    } catch {
      // 错误提示已由 axios 拦截器统一弹出
    } finally {
      shipSubmitting.value = false
    }
  })
}

// 分期确认收货
function handleStageCollect(stage: CycleStageItem) {
  ElMessageBox.confirm(`确定第 ${stage.cycle_number} 期已由买家确认收货吗？`, '确认收货', {
    confirmButtonText: '确认',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await collectCycleOrderStage(stage.id)
        ElMessage.success('已确认收货')
        refreshDetail()
      } catch {
        // 错误提示已由 axios 拦截器统一弹出
      }
    })
    .catch(() => {})
}

// ============ 退款审核 ============
function handleRefundPass(row: CycleOrderItem) {
  ElMessageBox.confirm(
    `同意退款「${row.ordernum}」将按未完成分期折算原路退回，确定吗？`,
    '退款确认',
    { confirmButtonText: '同意退款', cancelButtonText: '取消', type: 'warning' },
  )
    .then(async () => {
      try {
        await checkCycleOrderRefund(row.id, 1)
        ElMessage.success('已同意并退款')
        fetchList()
        if (detail.value && detail.value.id === row.id) refreshDetail()
      } catch {
        // 错误提示已由 axios 拦截器统一弹出
      }
    })
    .catch(() => {})
}

function handleRefundReject(row: CycleOrderItem) {
  ElMessageBox.prompt('请输入驳回原因', '驳回退款', {
    confirmButtonText: '驳回',
    cancelButtonText: '取消',
    inputType: 'textarea',
    inputPlaceholder: '请填写驳回原因',
    type: 'warning',
  })
    .then(async ({ value }) => {
      try {
        await checkCycleOrderRefund(row.id, 2, (value || '').trim())
        ElMessage.success('已驳回')
        fetchList()
        if (detail.value && detail.value.id === row.id) refreshDetail()
      } catch {
        // 错误提示已由 axios 拦截器统一弹出
      }
    })
    .catch(() => {})
}

// ============ 备注 ============
function handleRemark(row: CycleOrderItem) {
  ElMessageBox.prompt('请输入订单备注', '订单备注', {
    confirmButtonText: '保存',
    cancelButtonText: '取消',
    inputValue: row.remark,
    inputType: 'textarea',
    inputPlaceholder: '请填写备注',
  })
    .then(async ({ value }) => {
      try {
        await remarkCycleOrder(row.id, (value || '').trim())
        ElMessage.success('备注已保存')
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
        <el-form-item label="商品名">
          <el-input
            v-model="query.proname"
            placeholder="商品名称"
            clearable
            style="width: 180px"
            @keyup.enter="handleSearch"
          />
        </el-form-item>
        <el-form-item label="收货人">
          <el-input
            v-model="query.linkman"
            placeholder="收货人"
            clearable
            style="width: 140px"
            @keyup.enter="handleSearch"
          />
        </el-form-item>
        <el-form-item label="手机号">
          <el-input
            v-model="query.tel"
            placeholder="手机号"
            clearable
            style="width: 150px"
            @keyup.enter="handleSearch"
          />
        </el-form-item>
        <el-form-item>
          <el-button type="primary" @click="handleSearch">搜索</el-button>
          <el-button @click="handleReset">重置</el-button>
        </el-form-item>
      </el-form>

      <el-table v-loading="loading" :data="tableData" stripe>
        <el-table-column prop="ordernum" label="订单号" width="180" />
        <el-table-column label="商品" min-width="180">
          <template #default="{ row }">
            <div class="product-cell">
              <el-image
                v-if="row.propic"
                :src="row.propic"
                fit="cover"
                style="width: 36px; height: 36px; border-radius: 4px"
              />
              <div>
                <div class="product-name">{{ row.proname }}</div>
                <div class="text-muted">{{ row.buytype_text }}</div>
              </div>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="买家" min-width="100">
          <template #default="{ row }">
            {{ row.nickname || `会员${row.mid}` }}
          </template>
        </el-table-column>
        <el-table-column label="实付" width="100">
          <template #default="{ row }">￥{{ Number(row.totalprice).toFixed(2) }}</template>
        </el-table-column>
        <el-table-column label="分期进度" width="130">
          <template #default="{ row }">
            <div>
              {{ row.stage_progress.done }}/{{ row.stage_progress.total }} 期完成
            </div>
            <div class="text-muted">{{ row.stage_progress.shipped }} 期在途</div>
          </template>
        </el-table-column>
        <el-table-column label="状态" width="120">
          <template #default="{ row }">
            <el-tag :type="orderTagType(row.status)" size="small">{{ row.status_text }}</el-tag>
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
        <el-table-column label="收货人" width="120">
          <template #default="{ row }">
            <div>{{ row.linkman }}</div>
            <div class="text-muted">{{ row.tel }}</div>
          </template>
        </el-table-column>
        <el-table-column label="下单时间" width="160">
          <template #default="{ row }">{{ formatTime(row.createtime) }}</template>
        </el-table-column>
        <el-table-column label="操作" width="210" fixed="right">
          <template #default="{ row }">
            <el-button link type="primary" @click="openDetail(row)">详情</el-button>
            <template v-if="row.refund_status === 1">
              <el-button link type="success" @click="handleRefundPass(row)">同意退款</el-button>
              <el-button link type="warning" @click="handleRefundReject(row)">驳回</el-button>
            </template>
            <el-button link @click="handleRemark(row)">备注</el-button>
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

    <!-- 详情抽屉（含分期发货计划） -->
    <el-drawer v-model="drawerVisible" title="周期购订单详情" size="640px">
      <div v-loading="detailLoading">
        <template v-if="detail">
          <el-descriptions :column="2" border size="small">
            <el-descriptions-item label="订单号">{{ detail.ordernum }}</el-descriptions-item>
            <el-descriptions-item label="订单状态">
              <el-tag :type="orderTagType(detail.status)" size="small">{{ detail.status_text }}</el-tag>
              <el-tag
                v-if="detail.refund_text"
                size="small"
                style="margin-left: 6px"
                :type="detail.refund_status === 1 ? 'warning' : detail.refund_status === 2 ? 'danger' : 'info'"
              >
                {{ detail.refund_text }}
              </el-tag>
            </el-descriptions-item>
            <el-descriptions-item label="商品">{{ detail.proname }}</el-descriptions-item>
            <el-descriptions-item label="购买方式">{{ detail.buytype_text }}</el-descriptions-item>
            <el-descriptions-item label="实付金额">￥{{ Number(detail.totalprice).toFixed(2) }}</el-descriptions-item>
            <el-descriptions-item label="可退金额">￥{{ Number(detail.refund_money).toFixed(2) }}</el-descriptions-item>
            <el-descriptions-item label="买家">{{ detail.member?.nickname || `会员${detail.mid}` }}</el-descriptions-item>
            <el-descriptions-item label="收货人">{{ detail.linkman }}（{{ detail.tel }}）</el-descriptions-item>
            <el-descriptions-item label="收货地址" :span="2">{{ detail.area }} {{ detail.address }}</el-descriptions-item>
            <el-descriptions-item label="下单时间">{{ formatTime(detail.createtime) }}</el-descriptions-item>
            <el-descriptions-item label="支付时间">
              {{ detail.paytime ? formatTime(detail.paytime) : '-' }}
            </el-descriptions-item>
            <el-descriptions-item v-if="detail.refund_reason" label="退款原因" :span="2">
              {{ detail.refund_reason }}
            </el-descriptions-item>
            <el-descriptions-item v-if="detail.remark" label="备注" :span="2">{{ detail.remark }}</el-descriptions-item>
          </el-descriptions>

          <div class="stage-title">分期发货计划（{{ detail.stage_list.length }} 期）</div>
          <el-table :data="detail.stage_list" border size="small">
            <el-table-column label="期数" width="70">
              <template #default="{ row }">第 {{ row.cycle_number }} 期</template>
            </el-table-column>
            <el-table-column label="状态" width="90">
              <template #default="{ row }">
                <el-tag :type="stageTagType(row.status)" size="small">{{ row.status_text }}</el-tag>
              </template>
            </el-table-column>
            <el-table-column label="物流" min-width="160">
              <template #default="{ row }">
                <template v-if="row.express_no">
                  <div>{{ row.express_com }}</div>
                  <div class="text-muted">{{ row.express_no }}</div>
                </template>
                <span v-else class="text-muted">-</span>
              </template>
            </el-table-column>
            <el-table-column label="发货时间" width="150">
              <template #default="{ row }">
                {{ row.send_time ? formatTime(row.send_time) : '-' }}
              </template>
            </el-table-column>
            <el-table-column label="操作" width="150" fixed="right">
              <template #default="{ row }">
                <template v-if="row.status === 1">
                  <el-button link type="primary" size="small" @click="openShip(row)">发货</el-button>
                </template>
                <template v-if="row.status === 2">
                  <el-button link size="small" @click="openShip(row)">改物流</el-button>
                  <el-button link type="success" size="small" @click="handleStageCollect(row)">确认收货</el-button>
                </template>
              </template>
            </el-table-column>
          </el-table>

          <div v-if="detail.refund_status === 1" class="refund-actions">
            <el-button type="success" @click="handleRefundPass(detail)">同意退款</el-button>
            <el-button type="warning" @click="handleRefundReject(detail)">驳回退款</el-button>
          </div>
        </template>
      </div>
    </el-drawer>

    <!-- 按期发货弹窗 -->
    <el-dialog
      v-model="shipVisible"
      :title="shipStage?.status === 2 ? '修改物流' : `第 ${shipStage?.cycle_number} 期发货`"
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
          <span>{{ shipOrderNo }}</span>
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
        <el-button type="primary" :loading="shipSubmitting" @click="handleShipSubmit">确定</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped>
.pagination {
  margin-top: 16px;
  justify-content: flex-end;
}

.product-cell {
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

.stage-title {
  margin: 16px 0 8px;
  font-weight: 600;
}

.refund-actions {
  margin-top: 16px;
  display: flex;
  gap: 8px;
}
</style>
