<template>
  <div class="page-wrap">
    <!-- ===================== 订单管理模式 ===================== -->
    <template v-if="!isDeliveryMode">
      <!-- 搜索栏 -->
      <div class="search-bar">
        <el-button type="primary" plain size="small" :loading="exporting" @click="onExport">导出</el-button>
        <el-input v-model="query.ordernum" placeholder="订单号" clearable size="small" style="width:160px" @keyup.enter="loadOrders" />
        <el-input v-model="query.linkman" placeholder="收货人" clearable size="small" style="width:140px" />
        <el-input v-model="query.tel" placeholder="手机号" clearable size="small" style="width:140px" />
        <el-date-picker v-model="dateRange" type="daterange" range-separator="至" start-placeholder="下单开始" end-placeholder="下单结束" size="small" style="width:260px" />
        <el-select v-model="query.status" placeholder="状态" size="small" style="width:120px">
          <el-option label="全部" value="" />
          <el-option label="未支付" :value="0" />
          <el-option label="已支付" :value="1" />
          <el-option label="已发货" :value="2" />
          <el-option label="已收货" :value="3" />
          <el-option label="已关闭" :value="4" />
        </el-select>
        <el-button type="primary" size="small" @click="loadOrders">搜索</el-button>
      </div>

      <!-- 订单表格 -->
      <el-table :data="orders" v-loading="loading" border stripe size="small" style="width:100%">
        <el-table-column prop="id" label="ID" width="60" />
        <el-table-column label="商品信息" width="220">
          <template #default="{ row }">
            <div class="goods-cell">
              <el-image v-if="row.propic" :src="row.propic" style="width:48px;height:48px" fit="cover" />
              <div class="goods-meta">
                <div class="proname">{{ row.proname }}</div>
                <div class="ggname" v-if="row.ggname">{{ row.ggname }}</div>
                <div class="price-num"><span class="red">¥{{ fmt(row.sell_price) }}</span>×{{ row.num }}</div>
              </div>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="订单号/下单时间" width="170">
          <template #default="{ row }">
            <div class="ordernum">{{ row.ordernum }}</div>
            <div class="ctime">{{ fmtTime(row.createtime) }}</div>
          </template>
        </el-table-column>
        <el-table-column label="商品总价/实付款" width="120">
          <template #default="{ row }">
            <div>总价: ¥{{ fmt(row.product_price) }}</div>
            <div>实付: ¥{{ fmt(row.totalprice) }}</div>
          </template>
        </el-table-column>
        <el-table-column label="收货地址" width="240">
          <template #default="{ row }">
            <div>{{ row.linkman }} {{ row.tel }}</div>
            <div class="addr">{{ row.area }}{{ row.address }}</div>
          </template>
        </el-table-column>
        <el-table-column label="配送周期状态" width="180">
          <template #default="{ row }">
            <div v-for="s in row.stage_brief || []" :key="s.cycle_number" class="stage-chip">
              第{{ s.cycle_number }}期
              <span :class="['status-tag', stageChipClass(s.status)]">{{ s.status_text }}</span>
            </div>
            <el-button link type="primary" size="small" @click="openCyclePlan(row)">查看更多</el-button>
          </template>
        </el-table-column>
        <el-table-column label="来源" width="110">
          <template #default="{ row }">{{ row.platform_text || '微信小程序' }}</template>
        </el-table-column>
        <el-table-column label="支付方式" width="100">
          <template #default="{ row }">{{ row.paytype || '余额支付' }}</template>
        </el-table-column>
        <el-table-column label="配送方式" width="150">
          <template #default="{ row }">{{ row.freight_text || '—' }}</template>
        </el-table-column>
        <el-table-column label="状态" width="90">
          <template #default="{ row }">
            <span :class="['status-tag', orderStatusClass(row.status)]">{{ row.status_text }}</span>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="240" fixed="right">
          <template #default="{ row }">
            <el-button link type="primary" size="small" @click="openDetail(row.id)">查看详情</el-button>
            <el-button link type="danger" size="small" @click="onDelete(row)">删除订单</el-button>
            <el-button v-if="row.status === 1" link type="warning" size="small" @click="openCyclePlan(row)">周期计划</el-button>
            <el-button v-if="row.status === 2" link type="success" size="small" @click="onFinish(row)">完成</el-button>
            <el-button link type="primary" size="small" @click="openDetail(row.id)">退款</el-button>
          </template>
        </el-table-column>
      </el-table>

      <el-pagination
        v-model:current-page="query.page"
        v-model:page-size="query.pagesize"
        :total="total"
        :page-sizes="[10, 20, 50]"
        layout="total, sizes, prev, pager, next, jumper"
        @size-change="loadOrders"
        @current-change="loadOrders"
      />
    </template>

    <!-- ===================== 配送管理模式 ===================== -->
    <template v-else>
      <CycleStageTable :order-id="0" default-tab="1" />
    </template>

    <!-- ===================== 周期计划平铺大浮层（订单管理内） ===================== -->
    <div v-if="cyclePlanOrder" class="overlay-mask" @click.self="closeCyclePlan">
      <div class="overlay-panel">
        <div class="overlay-head">
          <span class="overlay-title">周期计划</span>
          <el-button circle size="small" @click="closeCyclePlan">✕</el-button>
        </div>
        <CycleStageTable :order-id="cyclePlanOrder.id" default-tab="" />
      </div>
    </div>

    <!-- ===================== 订单详情抽屉（查看详情/退款） ===================== -->
    <el-drawer v-model="detailDrawer.visible" title="订单详情" size="640px">
      <div v-if="detailDrawer.data" class="detail-body">
        <p>订单号：{{ detailDrawer.data.ordernum }}</p>
        <p>商品：{{ detailDrawer.data.proname }} {{ detailDrawer.data.ggname }}</p>
        <p>收货人：{{ detailDrawer.data.linkman }} {{ detailDrawer.data.tel }}</p>
        <p>地址：{{ detailDrawer.data.area }}{{ detailDrawer.data.address }}</p>
        <p>实付：¥{{ fmt(detailDrawer.data.totalprice) }}</p>
        <p>状态：{{ detailDrawer.data.status_text }}</p>
        <el-divider>分期发货计划</el-divider>
        <el-table :data="detailDrawer.data.stage_list" border size="small">
          <el-table-column prop="cycle_number" label="期数" width="70" />
          <el-table-column prop="cycle_date" label="发货日期" width="120" />
          <el-table-column label="状态" width="100">
            <template #default="{ row }">{{ row.status_text }}</template>
          </el-table-column>
          <el-table-column label="周期" width="80">
            <template #default="{ row }">第{{ row.cycle_number }}期</template>
          </el-table-column>
        </el-table>
      </div>
    </el-drawer>
  </div>
</template>

<script setup lang="ts">
import { ref, reactive, computed, inject, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { getCycleOrderList, getCycleOrderDetail, cycleOrderDelete, cycleOrderFinish, cycleOrderExport } from '../../api/cycle'
import CycleStageTable from './CycleStageTable.vue'

/* ================= 模式判定：配送管理 vs 订单管理 ================= */
const activeId = inject<{ value: string }>('adminActiveId', { value: '' })
const isDeliveryMode = computed(() => String(activeId?.value || '').includes('cycle-list'))

/* ================= 订单管理（订单列表） ================= */
const loading = ref(false)
const orders = ref<any[]>([])
const total = ref(0)
const dateRange = ref<any[]>([])
const query = reactive({ ordernum: '', linkman: '', tel: '', status: '' as string | number, start_date: '', end_date: '', page: 1, pagesize: 10 })

function fmt(v: any) { return Number(v || 0).toFixed(2) }
function fmtTime(ts: any) {
  if (!ts) return ''
  const d = new Date(Number(ts) * 1000)
  const p = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}年${p(d.getMonth() + 1)}月${p(d.getDate())}日 ${p(d.getHours())}:${p(d.getMinutes())}`
}
function orderStatusClass(s: any) {
  return [1, 3].includes(Number(s)) ? 'st-green' : 'st-red'
}
function stageChipClass(s: any) {
  return Number(s) === 3 ? 'st-green' : 'st-red'
}

async function loadOrders() {
  loading.value = true
  try {
    const params: Record<string, any> = {
      ordernum: query.ordernum,
      linkman: query.linkman,
      tel: query.tel,
      status: query.status,
      page: query.page,
      pagesize: query.pagesize,
    }
    if (dateRange.value && dateRange.value.length === 2) {
      params.start_date = dateRange.value[0]
      params.end_date = dateRange.value[1]
    }
    const res = await getCycleOrderList(params as any)
    orders.value = res.list || []
    total.value = res.total || 0
  } finally {
    loading.value = false
  }
}

const exporting = ref(false)
async function onExport() {
  exporting.value = true
  try {
    const params: Record<string, any> = {
      ordernum: query.ordernum,
      linkman: query.linkman,
      tel: query.tel,
      status: query.status,
    }
    if (dateRange.value && dateRange.value.length === 2) {
      params.start_date = dateRange.value[0]
      params.end_date = dateRange.value[1]
    }
    const blob = await cycleOrderExport(params)
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = `cycle_order_${Date.now()}.csv`
    a.click()
    URL.revokeObjectURL(url)
    ElMessage.success('导出成功')
  } catch (e) {
    // 错误提示由 axios 拦截器统一弹出
  } finally {
    exporting.value = false
  }
}

async function onDelete(row: any) {
  try {
    await ElMessageBox.confirm(`确定删除订单 ${row.ordernum} 吗？`, '删除订单', { type: 'warning' })
    await cycleOrderDelete(row.id)
    ElMessage.success('删除成功')
    loadOrders()
  } catch (e) { /* 取消 */ }
}

async function onFinish(row: any) {
  try {
    await ElMessageBox.confirm(`确定将订单 ${row.ordernum} 整单完成吗？剩余未完成分期将一并收口。`, '完成订单', { type: 'warning' })
    await cycleOrderFinish(row.id)
    ElMessage.success('订单已完成')
    loadOrders()
  } catch (e) { /* 取消 */ }
}

/* ================= 周期计划平铺浮层 ================= */
const cyclePlanOrder = ref<any>(null)
function openCyclePlan(row: any) {
  cyclePlanOrder.value = row
}
function closeCyclePlan() {
  cyclePlanOrder.value = null
}

/* ================= 订单详情抽屉 ================= */
const detailDrawer = reactive<{ visible: boolean; data: any }>({ visible: false, data: null })
async function openDetail(id: number) {
  detailDrawer.data = await getCycleOrderDetail(id)
  detailDrawer.visible = true
}

onMounted(() => {
  if (!isDeliveryMode.value) loadOrders()
})
</script>

<style scoped>
.search-bar { display:flex; gap:8px; align-items:center; margin-bottom:3px; flex-wrap:wrap; }
.goods-cell { display:flex; gap:8px; align-items:center; }
.goods-meta { min-width:0; }
.proname { font-size:13px; }
.ggname { font-size:12px; color:#999; }
.price-num .red { color:#ff4246; }
.ordernum { color:#008000; font-size:12px; }
.ctime { color:#008000; font-size:12px; }
.addr { color:#666; font-size:12px; }
.stage-chip { font-size:12px; margin-bottom:2px; }
.status-tag { padding:2px 6px; border-radius:3px; font-size:12px; }
.st-green { color:#008000; background:#eef9ee; }
.st-red { color:#ff4246; background:#ffecec; }

.overlay-mask { position:fixed; inset:0; background:rgba(0,0,0,.45); z-index:2000; display:flex; align-items:center; justify-content:center; }
.overlay-panel { background:#fff; width:92%; max-width:1100px; max-height:92vh; overflow:auto; border-radius:6px; padding:16px; }
.overlay-head { display:flex; justify-content:space-between; align-items:center; margin-bottom:10px; }
.overlay-title { font-size:16px; font-weight:600; }
.detail-body p { margin:4px 0; }
</style>
