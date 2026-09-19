<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import type { FormInstance, FormRules } from 'element-plus'
import {
  checkKanjiaOrderRefund,
  closeKanjiaOrder,
  deleteKanjiaJoin,
  getKanjiaCodelist,
  getKanjiaHelpList,
  getKanjiaJoinList,
  getKanjiaOrderList,
  hexiaoKanjia,
  shipKanjiaOrder,
} from '../../api/kanjia'
import type {
  KanjiaCodelistItem,
  KanjiaHelpItem,
  KanjiaJoinItem,
  KanjiaOrderItem,
} from '../../api/types'
import { formatTime } from '../../utils/time'

// ============ 页签：发起记录 / 订单 / 核销码 ============
const mainTab = ref('join')

function handleMainTabChange() {
  if (mainTab.value === 'join') fetchJoinList()
  if (mainTab.value === 'order') fetchOrderList()
  if (mainTab.value === 'code') fetchCodeList()
}

// 秒数 → hh:mm:ss 倒计时
function formatLeft(second: number): string {
  if (!second || second <= 0) return '已结束'
  const h = Math.floor(second / 3600)
  const m = Math.floor((second % 3600) / 60)
  const s = second % 60
  return `${h}小时${m}分${s}秒`
}

/* ================= 发起记录 ================= */
const joinLoading = ref(false)
const joinData = ref<KanjiaJoinItem[]>([])
const joinTotal = ref(0)

const joinQuery = reactive({
  page: 1,
  pagesize: 10,
  keyword: '',
  status: '' as number | '',
})

async function fetchJoinList() {
  joinLoading.value = true
  try {
    const data = await getKanjiaJoinList({
      page: joinQuery.page,
      pagesize: joinQuery.pagesize,
      keyword: joinQuery.keyword || undefined,
      status: joinQuery.status === '' ? undefined : joinQuery.status,
    })
    joinData.value = data.list || []
    joinTotal.value = data.total || 0
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    joinLoading.value = false
  }
}

function handleJoinSearch() {
  joinQuery.page = 1
  fetchJoinList()
}

function handleJoinPageChange(page: number) {
  joinQuery.page = page
  fetchJoinList()
}

function handleJoinSizeChange(size: number) {
  joinQuery.pagesize = size
  joinQuery.page = 1
  fetchJoinList()
}

function joinTagType(status: number) {
  if (status === 1) return 'success'
  if (status === 2) return 'danger'
  return 'warning'
}

// 帮砍明细弹窗
const helpVisible = ref(false)
const helpLoading = ref(false)
const helpJoin = ref<KanjiaJoinItem | null>(null)
const helpData = ref<KanjiaHelpItem[]>([])

async function openHelp(row: KanjiaJoinItem) {
  helpJoin.value = row
  helpVisible.value = true
  helpLoading.value = true
  try {
    helpData.value = await getKanjiaHelpList(row.id)
  } catch {
    helpData.value = []
  } finally {
    helpLoading.value = false
  }
}

function handleJoinDelete(row: KanjiaJoinItem) {
  ElMessageBox.confirm(
    `确定要删除「${row.nickname || '会员' + row.mid}」发起的砍价记录吗？帮砍明细将一并删除。`,
    '删除确认',
    { confirmButtonText: '删除', cancelButtonText: '取消', type: 'warning' },
  )
    .then(async () => {
      try {
        await deleteKanjiaJoin(row.id)
        ElMessage.success('删除成功')
        fetchJoinList()
      } catch {
        // 错误提示已由 axios 拦截器统一弹出
      }
    })
    .catch(() => {})
}

/* ================= 砍价订单 ================= */
const orderLoading = ref(false)
const orderData = ref<KanjiaOrderItem[]>([])
const orderTotal = ref(0)

// tabs：''全部 0-4 订单状态 r1-r3 退款态
const activeTab = ref('')

const orderQuery = reactive({
  page: 1,
  pagesize: 10,
  ordernum: '',
  proname: '',
})

async function fetchOrderList() {
  orderLoading.value = true
  try {
    const data = await getKanjiaOrderList({
      page: orderQuery.page,
      pagesize: orderQuery.pagesize,
      status: activeTab.value || undefined,
      ordernum: orderQuery.ordernum || undefined,
      proname: orderQuery.proname || undefined,
    })
    orderData.value = data.list || []
    orderTotal.value = data.total || 0
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    orderLoading.value = false
  }
}

function handleOrderTabChange() {
  orderQuery.page = 1
  fetchOrderList()
}

function handleOrderSearch() {
  orderQuery.page = 1
  fetchOrderList()
}

function handleOrderReset() {
  orderQuery.ordernum = ''
  orderQuery.proname = ''
  orderQuery.page = 1
  fetchOrderList()
}

function handleOrderPageChange(page: number) {
  orderQuery.page = page
  fetchOrderList()
}

function handleOrderSizeChange(size: number) {
  orderQuery.pagesize = size
  orderQuery.page = 1
  fetchOrderList()
}

function orderTagType(status: number) {
  if (status === 0) return 'info'
  if (status === 4) return 'danger'
  return 'primary'
}

// 发货弹窗
const shipVisible = ref(false)
const shipRow = ref<KanjiaOrderItem | null>(null)
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

function openShip(row: KanjiaOrderItem) {
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
      await shipKanjiaOrder(shipRow.value!.id, shipForm.express_com, shipForm.express_no)
      ElMessage.success('发货成功')
      shipVisible.value = false
      fetchOrderList()
    } catch {
      // 错误提示已由 axios 拦截器统一弹出
    } finally {
      shipSubmitting.value = false
    }
  })
}

// 退款审核
function handleRefundPass(row: KanjiaOrderItem) {
  const money = Number(row.refund_money || row.totalprice).toFixed(2)
  ElMessageBox.confirm(
    `同意退款「${row.ordernum}」将原路退回 ￥${money}，确定吗？`,
    '退款确认',
    { confirmButtonText: '同意退款', cancelButtonText: '取消', type: 'warning' },
  )
    .then(async () => {
      try {
        await checkKanjiaOrderRefund(row.id, 1)
        ElMessage.success('已同意并退款')
        fetchOrderList()
      } catch {
        // 错误提示已由 axios 拦截器统一弹出
      }
    })
    .catch(() => {})
}

function handleRefundReject(row: KanjiaOrderItem) {
  ElMessageBox.prompt('请输入驳回原因', '驳回退款', {
    confirmButtonText: '驳回',
    cancelButtonText: '取消',
    inputType: 'textarea',
    inputPlaceholder: '请填写驳回原因',
    type: 'warning',
  })
    .then(async ({ value }) => {
      try {
        await checkKanjiaOrderRefund(row.id, 2, (value || '').trim())
        ElMessage.success('已驳回')
        fetchOrderList()
      } catch {
        // 错误提示已由 axios 拦截器统一弹出
      }
    })
    .catch(() => {})
}

// 关单
function handleOrderClose(row: KanjiaOrderItem) {
  ElMessageBox.confirm(`确定要关闭订单「${row.ordernum}」吗？关闭后不可恢复。`, '关单确认', {
    confirmButtonText: '关闭订单',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await closeKanjiaOrder(row.id)
        ElMessage.success('订单已关闭')
        fetchOrderList()
      } catch {
        // 错误提示已由 axios 拦截器统一弹出
      }
    })
    .catch(() => {})
}

/* ================= 核销码 ================= */
const codeLoading = ref(false)
const codeData = ref<KanjiaCodelistItem[]>([])
const codeTotal = ref(0)

const codeQuery = reactive({
  page: 1,
  pagesize: 10,
  keyword: '',
  status: '' as number | '',
})

async function fetchCodeList() {
  codeLoading.value = true
  try {
    const data = await getKanjiaCodelist({
      page: codeQuery.page,
      pagesize: codeQuery.pagesize,
      keyword: codeQuery.keyword || undefined,
      status: codeQuery.status === '' ? undefined : codeQuery.status,
    })
    codeData.value = data.list || []
    codeTotal.value = data.total || 0
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    codeLoading.value = false
  }
}

function handleCodeSearch() {
  codeQuery.page = 1
  fetchCodeList()
}

function handleCodePageChange(page: number) {
  codeQuery.page = page
  fetchCodeList()
}

function handleCodeSizeChange(size: number) {
  codeQuery.pagesize = size
  codeQuery.page = 1
  fetchCodeList()
}

// 核销操作
const hexiaoCode = ref('')
const hexiaoSubmitting = ref(false)

async function handleHexiao() {
  const code = hexiaoCode.value.trim()
  if (!code) {
    ElMessage.warning('请输入核销码或订单号')
    return
  }
  hexiaoSubmitting.value = true
  try {
    const ret = await hexiaoKanjia(code)
    ElMessage.success(`核销成功：${ret.proname}（${ret.ordernum}）`)
    hexiaoCode.value = ''
    fetchCodeList()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    hexiaoSubmitting.value = false
  }
}

onMounted(fetchJoinList)
</script>

<template>
  <div>
    <el-card shadow="never">
      <el-tabs v-model="mainTab" @tab-change="handleMainTabChange">
        <!-- ============ 发起记录 ============ -->
        <el-tab-pane label="发起记录" name="join">
          <el-form inline @submit.prevent>
            <el-form-item label="关键词">
              <el-input
                v-model="joinQuery.keyword"
                placeholder="会员昵称/商品名称"
                clearable
                style="width: 200px"
                @keyup.enter="handleJoinSearch"
              />
            </el-form-item>
            <el-form-item label="状态">
              <el-select v-model="joinQuery.status" placeholder="全部" clearable style="width: 120px">
                <el-option label="进行中" :value="0" />
                <el-option label="已完成" :value="1" />
                <el-option label="已失败" :value="2" />
              </el-select>
            </el-form-item>
            <el-form-item>
              <el-button type="primary" @click="handleJoinSearch">搜索</el-button>
            </el-form-item>
          </el-form>

          <el-table v-loading="joinLoading" :data="joinData" stripe>
            <el-table-column label="会员" min-width="120">
              <template #default="{ row }">
                {{ row.nickname || `会员${row.mid}` }}
              </template>
            </el-table-column>
            <el-table-column label="商品" min-width="180">
              <template #default="{ row }">
                <div class="product-cell">
                  <el-image
                    v-if="row.propic"
                    :src="row.propic"
                    fit="cover"
                    style="width: 36px; height: 36px; border-radius: 4px"
                  />
                  <span class="product-name">{{ row.proname }}</span>
                </div>
              </template>
            </el-table-column>
            <el-table-column label="当前价/底价" width="130">
              <template #default="{ row }">
                <div>￥{{ Number(row.now_price).toFixed(2) }}</div>
                <div class="text-muted">底价 ￥{{ Number(row.min_price).toFixed(2) }}</div>
              </template>
            </el-table-column>
            <el-table-column label="砍价进度" width="160">
              <template #default="{ row }">
                <el-progress :percentage="row.cut_percent" :stroke-width="8" />
                <div class="text-muted">{{ row.helpnum }}/{{ row.target_helpnum }} 人帮砍</div>
              </template>
            </el-table-column>
            <el-table-column label="剩余时间" width="140">
              <template #default="{ row }">
                <span v-if="row.status === 0">{{ formatLeft(row.leftsecond) }}</span>
                <span v-else class="text-muted">-</span>
              </template>
            </el-table-column>
            <el-table-column label="状态" width="130">
              <template #default="{ row }">
                <el-tag :type="joinTagType(row.status)" size="small">{{ row.status_text }}</el-tag>
                <el-tag v-if="row.isbuy === 1" type="success" size="small" style="margin-left: 4px">
                  已下单
                </el-tag>
              </template>
            </el-table-column>
            <el-table-column label="发起时间" width="160">
              <template #default="{ row }">{{ formatTime(row.createtime) }}</template>
            </el-table-column>
            <el-table-column label="操作" width="150" fixed="right">
              <template #default="{ row }">
                <el-button link type="primary" @click="openHelp(row)">帮砍明细</el-button>
                <el-button v-if="row.status !== 1" link type="danger" @click="handleJoinDelete(row)">
                  删除
                </el-button>
              </template>
            </el-table-column>
          </el-table>

          <el-pagination
            class="pagination"
            background
            layout="total, sizes, prev, pager, next, jumper"
            :total="joinTotal"
            :current-page="joinQuery.page"
            :page-size="joinQuery.pagesize"
            :page-sizes="[10, 20, 50, 100]"
            @current-change="handleJoinPageChange"
            @size-change="handleJoinSizeChange"
          />
        </el-tab-pane>

        <!-- ============ 砍价订单 ============ -->
        <el-tab-pane label="砍价订单" name="order">
          <el-tabs v-model="activeTab" @tab-change="handleOrderTabChange">
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
                v-model="orderQuery.ordernum"
                placeholder="订单号"
                clearable
                style="width: 190px"
                @keyup.enter="handleOrderSearch"
              />
            </el-form-item>
            <el-form-item label="商品名">
              <el-input
                v-model="orderQuery.proname"
                placeholder="商品名称"
                clearable
                style="width: 190px"
                @keyup.enter="handleOrderSearch"
              />
            </el-form-item>
            <el-form-item>
              <el-button type="primary" @click="handleOrderSearch">搜索</el-button>
              <el-button @click="handleOrderReset">重置</el-button>
            </el-form-item>
          </el-form>

          <el-table v-loading="orderLoading" :data="orderData" stripe>
            <el-table-column prop="ordernum" label="订单号" width="190" />
            <el-table-column label="商品" min-width="180">
              <template #default="{ row }">
                <div class="product-cell">
                  <el-image
                    v-if="row.propic"
                    :src="row.propic"
                    fit="cover"
                    style="width: 36px; height: 36px; border-radius: 4px"
                  />
                  <span class="product-name">{{ row.proname }}</span>
                </div>
              </template>
            </el-table-column>
            <el-table-column label="买家" min-width="110">
              <template #default="{ row }">
                {{ row.nickname || `会员${row.mid}` }}
              </template>
            </el-table-column>
            <el-table-column label="原价/成交价" width="130">
              <template #default="{ row }">
                <div class="text-muted">￥{{ Number(row.sell_price).toFixed(2) }}</div>
                <div>￥{{ Number(row.totalprice).toFixed(2) }}</div>
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
            <el-table-column label="收货人" width="130">
              <template #default="{ row }">
                <div>{{ row.linkman }}</div>
                <div class="text-muted">{{ row.tel }}</div>
              </template>
            </el-table-column>
            <el-table-column label="下单时间" width="160">
              <template #default="{ row }">{{ formatTime(row.createtime) }}</template>
            </el-table-column>
            <el-table-column label="操作" width="190" fixed="right">
              <template #default="{ row }">
                <template v-if="row.status === 0">
                  <el-button link type="danger" @click="handleOrderClose(row)">关单</el-button>
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
            :total="orderTotal"
            :current-page="orderQuery.page"
            :page-size="orderQuery.pagesize"
            :page-sizes="[10, 20, 50, 100]"
            @current-change="handleOrderPageChange"
            @size-change="handleOrderSizeChange"
          />
        </el-tab-pane>

        <!-- ============ 核销码 ============ -->
        <el-tab-pane label="核销码" name="code">
          <el-form inline @submit.prevent>
            <el-form-item label="核销操作">
              <el-input
                v-model="hexiaoCode"
                placeholder="输入核销码或订单号"
                clearable
                style="width: 240px"
                @keyup.enter="handleHexiao"
              />
              <el-button type="primary" :loading="hexiaoSubmitting" @click="handleHexiao">
                核销
              </el-button>
            </el-form-item>
          </el-form>
          <el-form inline @submit.prevent>
            <el-form-item label="关键词">
              <el-input
                v-model="codeQuery.keyword"
                placeholder="卡密内容/订单号/昵称"
                clearable
                style="width: 220px"
                @keyup.enter="handleCodeSearch"
              />
            </el-form-item>
            <el-form-item label="状态">
              <el-select v-model="codeQuery.status" placeholder="全部" clearable style="width: 120px">
                <el-option label="未售出" :value="0" />
                <el-option label="已售出" :value="1" />
              </el-select>
            </el-form-item>
            <el-form-item>
              <el-button type="primary" @click="handleCodeSearch">搜索</el-button>
            </el-form-item>
          </el-form>

          <el-table v-loading="codeLoading" :data="codeData" stripe>
            <el-table-column prop="content" label="卡密内容" min-width="200" />
            <el-table-column prop="proname" label="商品" min-width="160" />
            <el-table-column label="状态" width="100">
              <template #default="{ row }">
                <el-tag :type="row.status === 1 ? 'success' : 'info'" size="small">
                  {{ row.status_text }}
                </el-tag>
              </template>
            </el-table-column>
            <el-table-column prop="ordernum" label="关联订单" width="190" />
            <el-table-column prop="nickname" label="购买会员" width="120" />
            <el-table-column label="入库时间" width="160">
              <template #default="{ row }">{{ formatTime(row.createtime) }}</template>
            </el-table-column>
            <el-table-column label="售出时间" width="160">
              <template #default="{ row }">
                <span v-if="row.buytime">{{ formatTime(row.buytime) }}</span>
                <span v-else class="text-muted">-</span>
              </template>
            </el-table-column>
          </el-table>

          <el-pagination
            class="pagination"
            background
            layout="total, sizes, prev, pager, next, jumper"
            :total="codeTotal"
            :current-page="codeQuery.page"
            :page-size="codeQuery.pagesize"
            :page-sizes="[10, 20, 50, 100]"
            @current-change="handleCodePageChange"
            @size-change="handleCodeSizeChange"
          />
        </el-tab-pane>
      </el-tabs>
    </el-card>

    <!-- 帮砍明细弹窗 -->
    <el-dialog v-model="helpVisible" title="帮砍明细" width="560px">
      <div v-if="helpJoin" class="help-summary">
        <span>{{ helpJoin.nickname || `会员${helpJoin.mid}` }} 发起的「{{ helpJoin.proname }}」</span>
        <el-tag :type="joinTagType(helpJoin.status)" size="small">{{ helpJoin.status_text }}</el-tag>
        <span class="text-muted">当前价 ￥{{ Number(helpJoin.now_price).toFixed(2) }}</span>
      </div>
      <el-table v-loading="helpLoading" :data="helpData" max-height="360">
        <el-table-column label="帮砍会员" min-width="120">
          <template #default="{ row }">
            {{ row.nickname || `会员${row.mid}` }}
          </template>
        </el-table-column>
        <el-table-column label="砍掉金额" width="110">
          <template #default="{ row }">-￥{{ Number(row.cut_price).toFixed(2) }}</template>
        </el-table-column>
        <el-table-column label="砍后价" width="110">
          <template #default="{ row }">￥{{ Number(row.after_price).toFixed(2) }}</template>
        </el-table-column>
        <el-table-column label="时间" width="160">
          <template #default="{ row }">{{ formatTime(row.createtime) }}</template>
        </el-table-column>
      </el-table>
      <template #footer>
        <el-button @click="helpVisible = false">关闭</el-button>
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

.help-summary {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 12px;
}
</style>
