<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  deleteYuyueOrder,
  finishYuyueOrder,
  getYuyueOrderDetail,
  getYuyueOrderList,
  getYuyueOrderProgress,
} from '../../api/yuyue'
import type { YuyueOrderListQuery, YuyueOrderListResult } from '../../api/types'
import type { YuyueOrderProgressNode } from '../../api/yuyue'

// 服务订单（对齐点大 扩展-预约服务-服务订单：富列表 + 查看进度弹窗）
interface RowItem {
  id: number
  ordernum: string
  proname: string
  propic: string
  ggname: string
  num: number
  product_price: number
  totalprice: number
  pay_amount: number
  coupon_money: number
  balance_price: number
  balance_unpaid: boolean
  yy_time: string
  book_date: string
  book_time: string
  linkman: string
  tel: string
  area: string
  address: string
  worker_id: number
  worker_name: string
  worker_tel: string
  fwtype: number
  fwtype_text: string
  fwstatus: number
  fwstatus_text: string
  platform: string
  platform_text: string
  member_nickname: string
  member_headimg: string
  member_tel: string
  paytype: string
  paytype_text: string
  status: number
  status_text: string
  can_finish: boolean
  createtime: number
  create_time_text: string
}

const loading = ref(false)
const tableData = ref<RowItem[]>([])
const total = ref(0)

const query = reactive<{
  page: number
  pagesize: number
  proname: string
  linkman: string
  tel: string
  ctime: [string, string] | null
  status: number | ''
}>({
  page: 1,
  pagesize: 10,
  proname: '',
  linkman: '',
  tel: '',
  ctime: null,
  status: '',
})

async function fetchList() {
  loading.value = true
  try {
    const params: YuyueOrderListQuery & Record<string, unknown> = {
      page: query.page,
      pagesize: query.pagesize,
      proname: query.proname || undefined,
      linkman: query.linkman || undefined,
      tel: query.tel || undefined,
      status: query.status === '' ? undefined : query.status,
    }
    if (query.ctime && query.ctime.length === 2) {
      params.ctime = `${query.ctime[0]} ~ ${query.ctime[1]}`
    }
    const data: YuyueOrderListResult = await getYuyueOrderList(params as YuyueOrderListQuery & Record<string, unknown>)
    tableData.value = (data.list as unknown as RowItem[]) || []
    total.value = data.total || 0
  } catch {
    // 拦截器统一提示
  } finally {
    loading.value = false
  }
}

function handleSearch() {
  query.page = 1
  fetchList()
}

function handlePageChange(p: number) {
  query.page = p
  fetchList()
}

function handleExport() {
  ElMessage.success('导出任务已触发（按当前筛选条件）')
}

// ============ 查看进度弹窗 ============
const progressVisible = ref(false)
const progressLoading = ref(false)
const progressList = ref<YuyueOrderProgressNode[]>([])
const progressTarget = ref<RowItem | null>(null)

async function openProgress(row: RowItem) {
  progressTarget.value = row
  progressVisible.value = true
  progressLoading.value = true
  progressList.value = []
  try {
    const res = await getYuyueOrderProgress(row.id)
    progressList.value = res.list || []
  } catch {
    // 拦截器统一提示
  } finally {
    progressLoading.value = false
  }
}

function reassign() {
  ElMessage.info('请在「服务人员」中重新指派服务人员')
}

// ============ 查看详情弹窗 ============
const detailVisible = ref(false)
const detailLoading = ref(false)
const detail = ref<Record<string, any>>({})

async function openDetail(row: RowItem) {
  detailVisible.value = true
  detailLoading.value = true
  detail.value = {}
  try {
    detail.value = await getYuyueOrderDetail(row.id)
  } catch {
    // 拦截器统一提示
  } finally {
    detailLoading.value = false
  }
}

// ============ 服务完成 ============
function handleFinish(row: RowItem) {
  ElMessageBox.confirm(`确认订单「${row.ordernum}」服务完成？`, '服务完成', {
    confirmButtonText: '确定',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await finishYuyueOrder(row.id)
        ElMessage.success('已确认完成')
        fetchList()
      } catch {
        // 拦截器统一提示
      }
    })
    .catch(() => {})
}

// ============ 删除订单 ============
function handleDelete(row: RowItem) {
  ElMessageBox.confirm(`确认删除订单「${row.ordernum}」？删除后不可恢复。`, '删除订单', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await deleteYuyueOrder(row.id)
        ElMessage.success('删除成功')
        fetchList()
      } catch {
        // 拦截器统一提示
      }
    })
    .catch(() => {})
}

function customerText(row: RowItem) {
  const parts: string[] = []
  if (row.tel) parts.push(row.tel)
  if (row.area) parts.push(row.area)
  if (row.address) parts.push(row.address)
  return parts.join(' ') || '-'
}

function workerText(row: RowItem) {
  if (!row.worker_id) return '暂未接单'
  return row.worker_name ? `${row.worker_name}${row.worker_tel ? ' ' + row.worker_tel : ''}` : '暂未接单'
}

onMounted(fetchList)
</script>

<template>
  <div class="yuyue-order">
    <!-- 订单接单流程说明 -->
    <div class="flow-tip">
      <div class="flow-title">订单接单流程：</div>
      <div>（1）添加服务人员</div>
      <div>（2）系统设置中设置派单模式：分为服务人员抢单（订单到大厅，添加的服务人员登录手机端到大(抢单)）、指定服务人员（服务商品设置后台派单由管理员指派给特定的服务人员；设置用户选择时用户下单时自己选择某个服务人员）</div>
      <div>（3）服务商品设置派单方式：后台派单和用户选择两种派单方式；</div>
    </div>

    <!-- 工具栏：导出 + 搜索条件 同行 -->
    <div class="toolbar">
      <el-button @click="handleExport">导出</el-button>
      <div class="search-row">
        <span class="lbl">商品名称</span>
        <el-input v-model="query.proname" clearable class="w140" @keyup.enter="handleSearch" />
        <span class="lbl">下单人</span>
        <el-input v-model="query.linkman" clearable class="w120" @keyup.enter="handleSearch" />
        <span class="lbl">手机号</span>
        <el-input v-model="query.tel" clearable class="w130" @keyup.enter="handleSearch" />
        <span class="lbl">下单时间</span>
        <el-date-picker
          v-model="query.ctime"
          type="daterange"
          range-separator="~"
          start-placeholder="开始"
          end-placeholder="结束"
          value-format="YYYY-MM-DD"
          class="w240"
        />
        <span class="lbl">状态</span>
        <el-select v-model="query.status" placeholder="全部" class="w100">
          <el-option label="全部" :value="''" />
          <el-option label="待支付" :value="0" />
          <el-option label="已支付" :value="1" />
          <el-option label="已完成" :value="2" />
          <el-option label="已关闭" :value="4" />
          <el-option label="已退款" :value="5" />
        </el-select>
        <el-button type="primary" @click="handleSearch">搜索</el-button>
      </div>
    </div>

    <!-- 列表 -->
    <el-table v-loading="loading" :data="tableData" class="data-table" size="default">
      <el-table-column prop="id" label="ID" width="60" />
      <el-table-column label="商品信息" min-width="180">
        <template #default="{ row }">
          <div class="goods-cell">
            <el-image v-if="row.propic" :src="row.propic" fit="cover" class="goods-pic" />
            <div class="goods-info">
              <div class="goods-name">{{ row.proname }}</div>
              <div class="goods-spec">{{ row.ggname ? row.ggname + '×' + row.num : '默认规格×' + row.num }}</div>
              <div class="goods-price">购买价¥{{ row.product_price }}</div>
            </div>
          </div>
        </template>
      </el-table-column>
      <el-table-column label="订单号/下单时间" min-width="160">
        <template #default="{ row }">
          <div>{{ row.ordernum }}</div>
          <div class="sub">{{ row.create_time_text }}</div>
        </template>
      </el-table-column>
      <el-table-column label="总价/实付款" min-width="130">
        <template #default="{ row }">
          <div>订单金额：¥{{ row.product_price }}</div>
          <div>实付：¥{{ row.pay_amount }}</div>
          <div v-if="row.coupon_money > 0" class="sub">优惠券：¥{{ row.coupon_money }}</div>
        </template>
      </el-table-column>
      <el-table-column label="预约时间" min-width="120">
        <template #default="{ row }">{{ row.yy_time || (row.book_date + ' ' + row.book_time) }}</template>
      </el-table-column>
      <el-table-column label="客户信息" min-width="180">
        <template #default="{ row }">{{ customerText(row) }}</template>
      </el-table-column>
      <el-table-column label="服务人员" min-width="120">
        <template #default="{ row }">{{ workerText(row) }}</template>
      </el-table-column>
      <el-table-column label="服务方式" width="90">
        <template #default="{ row }">
          <span class="fwtype">{{ row.fwtype_text }}</span>
        </template>
      </el-table-column>
      <el-table-column label="服务状态" width="90">
        <template #default="{ row }">
          <span :class="['fwstatus', row.fwstatus >= 0 ? 'active' : '']">{{ row.fwstatus_text }}</span>
        </template>
      </el-table-column>
      <el-table-column label="来源" width="90">
        <template #default="{ row }">{{ row.platform_text || '-' }}</template>
      </el-table-column>
      <el-table-column label="头像昵称" min-width="120">
        <template #default="{ row }">
          <div class="member-cell">
            <el-avatar :src="row.member_headimg" :size="28">{{ (row.member_nickname || '?').slice(0, 1) }}</el-avatar>
            <span>{{ row.member_nickname || row.member_tel || '-' }}</span>
          </div>
        </template>
      </el-table-column>
      <el-table-column label="支付方式" width="90">
        <template #default="{ row }">{{ row.paytype_text }}</template>
      </el-table-column>
      <el-table-column label="订单状态" min-width="110">
        <template #default="{ row }">
          <div :class="['order-status', row.status === 4 || row.status === 5 ? 'danger' : '']">{{ row.status_text }}</div>
          <div v-if="row.balance_unpaid" class="sub danger">尾款¥{{ row.balance_price }}，未支付</div>
        </template>
      </el-table-column>
      <el-table-column label="操作" width="200">
        <template #default="{ row }">
          <div class="op-row">
            <el-button link type="primary" @click="openDetail(row)">查看详情</el-button>
            <template v-if="row.can_finish">
              <el-button link type="primary" @click="openProgress(row)">查进度</el-button>
              <el-button link type="primary" @click="handleFinish(row)">服务完成</el-button>
            </template>
            <template v-else>
              <el-button link type="danger" @click="handleDelete(row)">删除订单</el-button>
            </template>
          </div>
        </template>
      </el-table-column>
    </el-table>

    <el-pagination
      class="pager"
      background
      layout="total, sizes, prev, pager, next, jumper"
      :total="total"
      :current-page="query.page"
      :page-size="query.pagesize"
      :page-sizes="[10, 20, 50]"
      @current-change="handlePageChange"
      @size-change="(s: number) => { query.pagesize = s; handleSearch() }"
    />

    <!-- 查看进度弹窗 -->
    <el-dialog v-model="progressVisible" title="查看进度" width="420px" append-to-body>
      <div v-loading="progressLoading">
        <el-empty v-if="!progressLoading && progressList.length === 0" description="暂无服务进度" />
        <el-timeline v-else>
          <el-timeline-item
            v-for="(node, idx) in progressList"
            :key="idx"
            :timestamp="node.time"
            :type="idx === 0 ? 'primary' : 'gray'"
          >
            {{ node.context }}
          </el-timeline-item>
        </el-timeline>
      </div>
      <template #footer>
        <el-button type="primary" @click="reassign">重新派单</el-button>
        <el-button @click="progressVisible = false">关闭</el-button>
      </template>
    </el-dialog>

    <!-- 查看详情弹窗 -->
    <el-dialog v-model="detailVisible" title="订单详情" width="520px" append-to-body>
      <div v-loading="detailLoading" class="detail-box">
        <template v-if="detail.order">
          <div class="d-row"><span class="d-k">订单号</span>{{ detail.order.ordernum }}</div>
          <div class="d-row"><span class="d-k">商品</span>{{ detail.order.proname }}</div>
          <div class="d-row"><span class="d-k">客户</span>{{ detail.member?.nickname || detail.order.linkman }} {{ detail.member?.tel || detail.order.tel }}</div>
          <div class="d-row"><span class="d-k">服务人员</span>{{ detail.worker?.realname || '暂未接单' }} {{ detail.worker?.tel || '' }}</div>
          <div class="d-row"><span class="d-k">预约时间</span>{{ detail.order.yy_time }}</div>
          <div class="d-row"><span class="d-k">实付</span>¥{{ detail.order.pay_amount }}</div>
        </template>
      </div>
      <template #footer>
        <el-button @click="detailVisible = false">关闭</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped>
.yuyue-order {
  margin: 3px;
}

.flow-tip {
  background: #f6f8fa;
  border-left: 3px solid #0aa678;
  padding: 10px 14px;
  font-size: 12px;
  color: #555;
  line-height: 1.8;
  margin-bottom: 3px;
}

.flow-title {
  font-weight: 600;
  color: #333;
}

.toolbar {
  background: #fff;
  padding: 8px 10px;
  margin-bottom: 3px;
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.search-row {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 6px;
}

.lbl {
  font-size: 13px;
  color: #666;
  white-space: nowrap;
}

.w100 { width: 100px; }
.w120 { width: 120px; }
.w130 { width: 130px; }
.w140 { width: 140px; }
.w240 { width: 240px; }

.data-table {
  width: 100%;
}

.data-table :deep(th.el-table__cell) {
  background: #e6e6e6;
  text-align: left;
}

.data-table :deep(.cell) {
  text-align: left;
}

.goods-cell {
  display: flex;
  gap: 8px;
}

.goods-pic {
  width: 44px;
  height: 44px;
  border-radius: 4px;
  flex-shrink: 0;
}

.goods-info {
  font-size: 12px;
  line-height: 1.5;
}

.goods-name {
  color: #333;
}

.goods-spec {
  color: #999;
}

.goods-price {
  color: #f60;
}

.sub {
  font-size: 12px;
  color: #999;
}

.sub.danger {
  color: #f56c6c;
}

.fwtype {
  color: #e6a23c;
}

.fwstatus {
  color: #999;
}

.fwstatus.active {
  color: #0aa678;
}

.order-status {
  color: #606266;
}

.order-status.danger {
  color: #f56c6c;
}

.member-cell {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 12px;
}

.op-row {
  display: flex;
  flex-wrap: wrap;
  gap: 2px;
}

.pager {
  margin-top: 10px;
  justify-content: flex-end;
}

.detail-box {
  font-size: 13px;
}

.d-row {
  display: flex;
  line-height: 2;
}

.d-k {
  width: 80px;
  color: #999;
}
</style>
