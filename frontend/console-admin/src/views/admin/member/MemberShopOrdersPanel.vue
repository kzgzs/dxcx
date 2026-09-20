<template>
  <div class="mp-panel">
    <div class="ge-head">
      <span class="ge-title">商城订单</span>
      <el-button class="ge-close" circle @click="emit('close')">
        <svg viewBox="0 0 1024 1024" width="16" height="16" fill="currentColor"><path d="M764.288 214.595 512 466.883 259.712 214.595a32 32 0 0 0-45.248 45.248L466.752 512 214.464 764.288a32 32 0 1 0 45.248 45.248L512 557.117l252.288 252.419a32 32 0 0 0 45.248-45.248L557.248 512l252.288-252.157a32 32 0 0 0-45.248-45.248z" /></svg>
      </el-button>
    </div>

    <el-tabs v-model="activeStatus" class="mp-tabs" @tab-change="onTab">
      <el-tab-pane v-for="t in tabs" :key="String(t.value)" :label="t.label" :name="String(t.value)" />
    </el-tabs>

    <el-card shadow="never" class="toolbar-card">
      <div class="toolbar-line">
        <el-input v-model="query.ordernum" placeholder="订单号" clearable class="w150" @keyup.enter="onSearch" />
        <el-date-picker
          v-model="dateRange"
          type="daterange"
          value-format="YYYY-MM-DD"
          range-separator="-"
          start-placeholder="下单开始"
          end-placeholder="下单结束"
          class="date-range"
        />
        <el-select v-model="query.freight_type" placeholder="配送方式" clearable class="w110">
          <el-option label="快递配送" :value="0" />
          <el-option label="自提/同城" :value="1" />
        </el-select>
        <el-select v-model="statusSelect" placeholder="状态" clearable class="w110" @change="onSearch">
          <el-option v-for="s in statusOptions" :key="s.value" :label="s.label" :value="s.value" />
        </el-select>
        <el-input v-model="query.min_price" placeholder="金额起" class="w90" />
        <span class="price-sep">-</span>
        <el-input v-model="query.max_price" placeholder="金额止" class="w90" />
        <el-button type="success" @click="onSearch">搜索</el-button>
        <el-button @click="onReset">重置</el-button>
      </div>
    </el-card>

    <el-card shadow="never" class="toolbar-card">
      <div class="toolbar-line between">
        <div class="left-ops">
          <el-button :loading="exporting" @click="handleExport">导出</el-button>
        </div>
        <el-popover placement="bottom-end" :width="160" trigger="click">
          <template #reference>
            <el-button circle class="col-set-btn" title="列设置">
              <svg viewBox="0 0 1024 1024" width="16" height="16" fill="currentColor"><path d="M930.133333 490.666667h-72.533333a42.666667 42.666667 0 0 1 0-85.333334h72.533333a42.666667 42.666667 0 0 1 0 85.333334zM810.666667 768a42.666667 42.666667 0 0 0-85.333334 0v42.666667a42.666667 42.666667 0 0 0 85.333334 0v-42.666667z m0-554.666667a42.666667 42.666667 0 0 0-85.333334 0v192a42.666667 42.666667 0 0 0 85.333334 0V213.333333zM490.666667 857.6a42.666667 42.666667 0 0 0-85.333334 0v42.666667a42.666667 42.666667 0 0 0 85.333334 0v-42.666667z m0-234.666667a42.666667 42.666667 0 0 0-85.333334 0v277.333334a42.666667 42.666667 0 0 0 85.333334 0V622.933333z m0-320a42.666667 42.666667 0 0 0-85.333334 0v362.666667a42.666667 42.666667 0 0 0 85.333334 0V302.933333z m0-234.666666a42.666667 42.666667 0 0 0-85.333334 0v277.333333a42.666667 42.666667 0 0 0 85.333334 0V68.266667zM213.333333 490.666667a42.666667 42.666667 0 0 0 0-85.333334H93.866667a42.666667 42.666667 0 0 0 0 85.333334h119.466666z m85.333334 277.333333a42.666667 42.666667 0 0 0-85.333334 0v42.666667a42.666667 42.666667 0 0 0 85.333334 0v-42.666667z m0-554.666667a42.666667 42.666667 0 0 0-85.333334 0v192a42.666667 42.666667 0 0 0 85.333334 0V213.333333z" /></svg>
            </el-button>
          </template>
          <div class="col-set-pop">
            <el-checkbox v-for="c in colOptions" :key="c.k" v-model="colVisible[c.k]">{{ c.l }}</el-checkbox>
          </div>
        </el-popover>
      </div>
    </el-card>

    <el-card shadow="never" class="table-card">
      <el-table :data="list" v-loading="loading" border size="small" class="data-table">
        <el-table-column prop="id" label="ID" width="80" sortable="custom" align="center" />
        <el-table-column label="商品信息" min-width="220" v-if="colVisible.goods">
          <template #default="{ row }">
            <div v-for="g in row.goods" :key="g.id" class="goods-line">
              <el-image :src="assetUrl(g.pic)" class="goods-pic" fit="cover">
                <template #error><div class="img-fallback">无图</div></template>
              </el-image>
              <div class="goods-meta">
                <div class="goods-name">{{ g.name }}</div>
                <div class="goods-gg">{{ g.ggname }}</div>
                <div class="goods-num">x{{ g.num }}　¥{{ fmtMoney(g.totalprice) }}</div>
              </div>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="订单信息" min-width="170" v-if="colVisible.order">
          <template #default="{ row }">
            <div class="cell-rows">
              <div>{{ row.ordernum || row.sysOrderNo }}</div>
              <div class="muted">{{ fmtTime(row.createtime) }}</div>
              <div class="muted">来源：{{ platformText(row.platform) }}</div>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="金额/支付" min-width="140" v-if="colVisible.money">
          <template #default="{ row }">
            <div class="cell-rows">
              <div>商品总价：¥{{ fmtMoney(row.product_price) }}</div>
              <div>实付款：<b>¥{{ fmtMoney(row.totalprice) }}</b></div>
              <div class="muted">{{ row.paytype || '--' }}</div>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="收货地址" min-width="180" v-if="colVisible.address">
          <template #default="{ row }">
            <div class="cell-rows">
              <div>{{ row.linkman }} {{ row.tel }}</div>
              <div class="muted">{{ row.address }}</div>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="配送方式" width="110" v-if="colVisible.freight">
          <template #default="{ row }">
            <span>{{ row.freight_text || (row.freight_type === 1 ? '自提/同城' : '快递配送') }}</span>
          </template>
        </el-table-column>
        <el-table-column label="状态" width="90" v-if="colVisible.status">
          <template #default="{ row }">
            <el-tag :color="statusMeta(row.status).c" style="color:#fff;border:none" size="small" disable-transitions>
              {{ statusMeta(row.status).t }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="remark" label="备注" min-width="120" show-overflow-tooltip v-if="colVisible.remark" />
      </el-table>

      <div class="pager">
        <el-pagination
          background
          layout="total, sizes, prev, pager, next, jumper"
          :total="total"
          :current-page="query.page"
          :page-size="query.pagesize"
          :page-sizes="[10, 20, 50]"
          @current-change="onPage"
          @size-change="onSize"
        />
      </div>
    </el-card>
  </div>
</template>

<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import {
  exportMemberShopOrders,
  getMemberShopOrders,
  type MemberShopOrderItem,
  type MemberShopOrderQuery,
} from '../../../api/member'
import { assetUrl } from '../../../api/upload'

const props = defineProps<{ mid: number }>()
const emit = defineEmits<{ close: [] }>()

const tabs = [
  { label: '全部订单', value: '' },
  { label: '未支付', value: 0 },
  { label: '待发货', value: 1 },
  { label: '已发货', value: 2 },
  { label: '已完成', value: 3 },
  { label: '已关闭', value: 4 },
]
const statusOptions = tabs.filter((t) => t.value !== '')

const loading = ref(false)
const exporting = ref(false)
const list = ref<MemberShopOrderItem[]>([])
const total = ref(0)
const activeStatus = ref('')
const statusSelect = ref<number | ''>('')
const dateRange = ref<[string, string] | null>(null)

const query = reactive<MemberShopOrderQuery>({
  mid: props.mid,
  page: 1,
  pagesize: 10,
  status: '',
  ordernum: '',
  freight_type: '',
  min_price: '',
  max_price: '',
  start_time: '',
  end_time: '',
})

const colOptions = [
  { k: 'goods', l: '商品信息' },
  { k: 'order', l: '订单信息' },
  { k: 'money', l: '金额/支付' },
  { k: 'address', l: '收货地址' },
  { k: 'freight', l: '配送方式' },
  { k: 'status', l: '状态' },
  { k: 'remark', l: '备注' },
]
const colVisible = reactive<Record<string, boolean>>({
  goods: true, order: true, money: true, address: true, freight: true, status: true, remark: true,
})

function fmtMoney(v: number): string {
  return Number(v || 0).toFixed(2)
}
function fmtTime(ts: number): string {
  if (!ts) return ''
  const d = new Date(ts * 1000)
  const p = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}`
}
function statusMeta(s: number): { t: string; c: string } {
  return (({
    0: { t: '未支付', c: '#ff9900' },
    1: { t: '待发货', c: '#19be6b' },
    2: { t: '已发货', c: '#2d8cf0' },
    3: { t: '已完成', c: '#52c41a' },
    4: { t: '已关闭', c: '#c0c4cc' },
  } as Record<number, { t: string; c: string }>)[s]) || { t: '未知', c: '#909399' }
}
function platformText(p?: string): string {
  if (!p) return '--'
  const m: Record<string, string> = {
    wx: '微信小程序', wechat: '微信小程序', h5: 'H5', wap: 'H5', api: 'H5',
    app: 'APP', admin: '后台下单', backend: '后台下单',
  }
  return m[p] || p
}

function downloadBlob(blob: Blob, fileName: string) {
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = fileName
  document.body.appendChild(a)
  a.click()
  document.body.removeChild(a)
  URL.revokeObjectURL(url)
}

function buildParams(): MemberShopOrderQuery {
  return {
    ...query,
    status: activeStatus.value === '' ? statusSelect.value : Number(activeStatus.value),
    start_time: dateRange.value?.[0] || '',
    end_time: dateRange.value?.[1] || '',
  }
}

async function load() {
  loading.value = true
  try {
    const res = await getMemberShopOrders(buildParams())
    list.value = res.list
    total.value = res.total
  } finally {
    loading.value = false
  }
}

function onTab() {
  statusSelect.value = ''
  query.page = 1
  load()
}
function onSearch() {
  query.page = 1
  if (statusSelect.value !== '') activeStatus.value = String(statusSelect.value)
  load()
}
function onReset() {
  query.ordernum = ''
  query.freight_type = ''
  query.min_price = ''
  query.max_price = ''
  statusSelect.value = ''
  activeStatus.value = ''
  dateRange.value = null
  query.page = 1
  load()
}
function onPage(p: number) {
  query.page = p
  load()
}
function onSize(s: number) {
  query.pagesize = s
  query.page = 1
  load()
}
async function handleExport() {
  if (exporting.value) return
  exporting.value = true
  try {
    const blob = await exportMemberShopOrders({ ...buildParams() } as Record<string, unknown>)
    downloadBlob(blob, `会员商城订单_${Date.now()}.xlsx`)
  } finally {
    exporting.value = false
  }
}

onMounted(load)
</script>

<style scoped>
.mp-panel {
  background: #fff;
  border-radius: 6px;
  padding: 12px 12px 16px;
}
.ge-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.ge-title {
  font-size: 15px;
  font-weight: 600;
}
.ge-close {
  border: none;
  background: transparent;
  color: #909399;
}
.ge-close:hover {
  color: #409eff;
  background: transparent;
}
.mp-tabs :deep(.el-tabs__header) {
  margin-bottom: 6px;
}
.toolbar-card {
  margin-bottom: 6px;
}
.toolbar-card :deep(.el-card__body) {
  padding: 8px 10px;
}
.toolbar-line {
  display: flex;
  align-items: center;
  flex-wrap: nowrap;
  gap: 6px;
}
.toolbar-line.between {
  justify-content: space-between;
}
.left-ops {
  display: flex;
  align-items: center;
  gap: 6px;
}
.w150 { width: 150px; }
.w110 { width: 110px; }
.w90 { width: 90px; }
.date-range { width: 240px; }
.price-sep { color: #909399; }
.col-set-btn {
  padding: 6px;
}
.col-set-pop {
  display: flex;
  flex-direction: column;
  gap: 6px;
}
.table-card :deep(.el-card__body) {
  padding: 8px;
}
.goods-line {
  display: flex;
  gap: 8px;
  padding: 3px 0;
}
.goods-pic {
  width: 44px;
  height: 44px;
  border-radius: 4px;
  flex: none;
}
.img-fallback {
  width: 44px;
  height: 44px;
  border-radius: 4px;
  background: #f5f7fa;
  color: #c0c4cc;
  font-size: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
}
.goods-meta {
  line-height: 1.5;
}
.goods-name {
  font-size: 12px;
}
.goods-gg,
.goods-num {
  color: #909399;
  font-size: 12px;
}
.cell-rows {
  line-height: 1.6;
  font-size: 12px;
}
.muted {
  color: #909399;
}
.pager {
  display: flex;
  justify-content: flex-end;
  margin-top: 8px;
}
</style>
