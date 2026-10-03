<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  closeKechengOrder,
  deleteKechengOrder,
  getKechengOrderDetail,
  getKechengOrderList,
} from '../../api/kecheng'
import type { KechengOrderDetail, KechengOrderItem } from '../../api/kecheng'

// 课程订单（对齐点大 扩展-知识付费-课程订单：导出+订单号/下单时间/状态 同行搜索；状态机 0未支付/1已支付/4已关闭）
const loading = ref(false)
const tableData = ref<KechengOrderItem[]>([])
const total = ref(0)

const query = reactive<{
  page: number
  pagesize: number
  ordernum: string
  ctime: [string, string] | null
  status: number | ''
}>({
  page: 1,
  pagesize: 10,
  ordernum: '',
  ctime: null,
  status: '',
})

async function fetchList() {
  loading.value = true
  try {
    const params: Record<string, unknown> = {
      page: query.page,
      pagesize: query.pagesize,
      ordernum: query.ordernum || undefined,
      status: query.status === '' ? undefined : query.status,
    }
    if (query.ctime && query.ctime.length === 2) {
      params.ctime = `${query.ctime[0]} ~ ${query.ctime[1]}`
    }
    const data = await getKechengOrderList(params)
    tableData.value = data.list || []
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

function statusText(row: KechengOrderItem): string {
  if (row.status === 0) return '未支付'
  if (row.status === 1) return '已支付'
  if (row.status === 4) return '已关闭'
  return '-'
}

function statusClass(row: KechengOrderItem): string {
  if (row.status === 0) return 'st-unpaid'
  if (row.status === 1) return 'st-paid'
  if (row.status === 4) return 'st-closed'
  return ''
}

// ============ 查看详情弹窗 ============
const detailVisible = ref(false)
const detailLoading = ref(false)
const detail = ref<KechengOrderDetail>({ order: {} as KechengOrderItem & Record<string, any>, member: { id: 0, nickname: '', headimg: '', tel: '' } })

async function openDetail(row: KechengOrderItem) {
  detailVisible.value = true
  detailLoading.value = true
  try {
    detail.value = await getKechengOrderDetail(row.id)
  } catch {
    // 拦截器统一提示
  } finally {
    detailLoading.value = false
  }
}

// ============ 关闭订单 ============
function handleClose(row: KechengOrderItem) {
  ElMessageBox.confirm(`确定要关闭订单「${row.ordernum}」吗？`, '操作确认', {
    confirmButtonText: '确定',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await closeKechengOrder(row.id)
        ElMessage.success('操作成功')
        fetchList()
      } catch {
        // 拦截器统一提示
      }
    })
    .catch(() => {})
}

// ============ 删除订单 ============
function handleDelete(row: KechengOrderItem) {
  ElMessageBox.confirm('确定要删除该订单吗？删除后无法恢复！', '操作确认', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await deleteKechengOrder(row.id)
        ElMessage.success('删除成功')
        fetchList()
      } catch {
        // 拦截器统一提示
      }
    })
    .catch(() => {})
}

onMounted(fetchList)
</script>

<template>
  <div class="kecheng-order">
    <!-- 工具栏：导出 + 搜索条件 同行 -->
    <div class="toolbar">
      <el-button @click="handleExport">导出</el-button>
      <div class="search-row">
        <span class="lbl">订单号</span>
        <el-input v-model="query.ordernum" clearable class="w180" @keyup.enter="handleSearch" />
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
        <el-select v-model="query.status" placeholder="全部" class="w110">
          <el-option label="全部" :value="''" />
          <el-option label="未支付" :value="0" />
          <el-option label="已支付" :value="1" />
        </el-select>
        <el-button type="primary" @click="handleSearch">搜索</el-button>
      </div>
    </div>

    <!-- 列表 -->
    <el-table v-loading="loading" :data="tableData" class="data-table" size="default">
      <el-table-column prop="id" label="ID" width="60" />
      <el-table-column label="课程信息" min-width="150">
        <template #default="{ row }">
          <div class="goods-name">{{ row.title }}</div>
          <div class="goods-price">购买价¥{{ row.totalprice }}</div>
        </template>
      </el-table-column>
      <el-table-column label="订单号/下单时间" min-width="180">
        <template #default="{ row }">
          <div>{{ row.ordernum }}</div>
          <div class="sub green">{{ row.createtime_text }}</div>
        </template>
      </el-table-column>
      <el-table-column label="总价" min-width="130">
        <template #default="{ row }">支付金额：¥{{ row.totalprice }}</template>
      </el-table-column>
      <el-table-column label="来源" width="100">
        <template #default="{ row }">{{ row.platform_text || '-' }}</template>
      </el-table-column>
      <el-table-column label="头像昵称" min-width="140">
        <template #default="{ row }">
          <div class="member-cell">
            <el-avatar :src="row.member_headimg" :size="28">{{ (row.member_nickname || '?').slice(0, 1) }}</el-avatar>
            <span>{{ row.member_nickname || row.member_tel || '-' }}</span>
          </div>
        </template>
      </el-table-column>
      <el-table-column label="支付方式" width="90">
        <template #default="{ row }">{{ row.paytype || '-' }}</template>
      </el-table-column>
      <el-table-column label="订单状态" width="90">
        <template #default="{ row }">
          <span :class="['order-status', statusClass(row)]">{{ statusText(row) }}</span>
        </template>
      </el-table-column>
      <el-table-column label="操作" width="220">
        <template #default="{ row }">
          <div class="op-row">
            <el-button link type="primary" @click="openDetail(row)">查看详情</el-button>
            <el-button v-if="row.status === 0" link type="primary" @click="handleClose(row)">关闭订单</el-button>
            <el-button v-if="row.status === 0 || row.status === 4" link type="primary" @click="handleDelete(row)">删除订单</el-button>
          </div>
        </template>
      </el-table-column>
    </el-table>

    <el-pagination
      class="pager"
      background
      layout="total, sizes, prev, pager, next"
      :total="total"
      :current-page="query.page"
      :page-size="query.pagesize"
      :page-sizes="[10, 20, 50]"
      @current-change="handlePageChange"
      @size-change="(s: number) => { query.pagesize = s; handleSearch() }"
    />

    <!-- 查看详情弹窗 -->
    <el-dialog v-model="detailVisible" title="订单详情" width="440px" append-to-body>
      <div v-loading="detailLoading" class="detail-box">
        <div class="d-row"><span class="d-k">课程</span>{{ detail.order.title }}</div>
        <div class="d-row"><span class="d-k">订单编号</span>{{ detail.order.ordernum }}</div>
        <div class="d-row"><span class="d-k">下单人</span>{{ detail.member.nickname || '-' }} {{ detail.member.tel || '' }}</div>
        <div class="d-row"><span class="d-k">会员ID</span>{{ detail.order.mid }}</div>
        <div class="d-row"><span class="d-k">下单时间</span>{{ detail.order.createtime_text }}</div>
        <div class="d-row" v-if="detail.order.status > 0">
          <span class="d-k">支付时间</span>{{ detail.order.paytime_text }}
        </div>
        <div class="d-row" v-if="detail.order.status > 0">
          <span class="d-k">支付方式</span>{{ detail.order.paytype || '-' }}
        </div>
        <div class="d-row"><span class="d-k">金额</span>¥{{ detail.order.price }}</div>
        <div class="d-row"><span class="d-k">实付款</span>¥{{ detail.order.totalprice }}</div>
        <div class="d-row">
          <span class="d-k">订单状态</span>
          <span :class="['order-status', statusClass(detail.order as KechengOrderItem)]">{{ statusText(detail.order as KechengOrderItem) }}</span>
        </div>
      </div>
      <template #footer>
        <el-button @click="detailVisible = false">关闭</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped>
.kecheng-order {
  margin: 3px;
}

.toolbar {
  background: #fff;
  padding: 8px 10px;
  margin-bottom: 3px;
  display: flex;
  flex-direction: row;
  align-items: center;
  flex-wrap: wrap;
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

.w110 { width: 110px; }
.w180 { width: 180px; }
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

.goods-name {
  color: #f60;
  font-size: 12px;
}

.goods-price {
  color: #f60;
  font-size: 12px;
}

.sub {
  font-size: 12px;
  color: #999;
}

.sub.green {
  color: #080;
}

.member-cell {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 12px;
}

.order-status {
  font-size: 13px;
}

.order-status.st-unpaid {
  color: #ff8758;
}

.order-status.st-paid {
  color: #ffc702;
}

.order-status.st-closed {
  color: #ff4246;
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
