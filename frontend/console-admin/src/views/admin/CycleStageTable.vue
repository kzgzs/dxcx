<template>
  <div class="stage-table-wrap">
    <!-- 状态 Tab -->
    <el-tabs v-model="activeTab" @tab-change="onTabChange" style="margin-bottom:3px">
      <el-tab-pane label="待发货" name="1" />
      <el-tab-pane label="已发货" name="2" />
      <el-tab-pane label="全部订单" name="" />
    </el-tabs>

    <!-- 搜索栏 -->
    <div class="search-bar">
      <el-input v-model="query.ordernum" placeholder="订单号" clearable size="small" style="width:160px" @keyup.enter="reload" />
      <el-input v-model="query.linkman" placeholder="收货人" clearable size="small" style="width:140px" />
      <el-input v-model="query.tel" placeholder="手机号" clearable size="small" style="width:140px" />
      <el-date-picker v-model="dateRange" type="daterange" range-separator="至" start-placeholder="下单开始" end-placeholder="下单结束" size="small" style="width:260px" />
      <el-select v-model="query.status" placeholder="状态" size="small" style="width:110px">
        <el-option label="全部" value="" />
      </el-select>
      <el-button type="primary" size="small" @click="reload">搜索</el-button>
    </div>

    <!-- 分期配送表格 -->
    <el-table :data="list" v-loading="loading" border stripe size="small" style="width:100%">
      <el-table-column prop="id" label="ID" width="60" />
      <el-table-column label="商品信息" width="160">
        <template #default="{ row }">
          <div class="goods-cell">
            <el-image v-if="row.propic || row.order_propic" :src="row.propic || row.order_propic" style="width:44px;height:44px" fit="cover" />
            <div class="goods-meta">
              <div class="proname">{{ row.proname || row.order_proname }}</div>
              <div class="ggname" v-if="row.ggname || row.order_ggname">{{ row.ggname || row.order_ggname }}</div>
            </div>
          </div>
        </template>
      </el-table-column>
      <el-table-column label="周期" width="70">
        <template #default="{ row }">第{{ row.cycle_number }}期</template>
      </el-table-column>
      <el-table-column label="订单号/下单时间" width="170">
        <template #default="{ row }">
          <div class="ordernum">{{ row.ordernum }}</div>
          <div class="ctime">{{ fmtTime(row.order_createtime) }}</div>
        </template>
      </el-table-column>
      <el-table-column label="收货人信息" width="230">
        <template #default="{ row }">
          <div>{{ row.linkman }} {{ row.tel }}</div>
          <div class="addr" v-if="row.address">{{ row.area }}{{ row.address }}</div>
        </template>
      </el-table-column>
      <el-table-column label="配送方式" width="140">
        <template #default="{ row }">{{ row.freight_text || '—' }}</template>
      </el-table-column>
      <el-table-column label="发货日期" width="110">
        <template #default="{ row }">{{ row.cycle_date_fmt }}</template>
      </el-table-column>
      <el-table-column label="状态" width="90">
        <template #default="{ row }">
          <span :class="['status-tag', stageStatusClass(row.status)]">{{ row.status_text }}</span>
        </template>
      </el-table-column>
      <el-table-column label="操作" width="220" fixed="right">
        <template #default="{ row }">
          <!-- status=1 待发/待提货 -->
          <el-button v-if="row.status === 1 && row.freight_type === 1" link type="warning" size="small" @click="openHexiao(row)">核销</el-button>
          <template v-else-if="row.status === 1">
            <el-button link type="primary" size="small" @click="openShip(row)">发货</el-button>
            <el-button link type="primary" size="small" @click="openShd(row)">送货单</el-button>
          </template>
          <!-- status=2 已发货/已提货 -->
          <template v-else-if="row.status === 2">
            <el-button link type="primary" size="small" @click="openExpress(row)">查物流</el-button>
            <el-button v-if="row.freight_type === 2" link type="primary" size="small" @click="openShd(row)">送货单</el-button>
            <el-button link type="success" size="small" @click="onCollect(row)">确认收货</el-button>
          </template>
          <!-- status=3 已完成 -->
          <el-button v-else-if="row.status === 3 && row.freight_type !== 1" link type="primary" size="small" @click="openExpress(row)">配送信息</el-button>
        </template>
      </el-table-column>
    </el-table>

    <el-pagination
      v-model:current-page="query.page"
      v-model:page-size="query.pagesize"
      :total="total"
      :page-sizes="[10, 20, 50]"
      layout="total, sizes, prev, pager, next, jumper"
      @size-change="reload"
      @current-change="reload"
    />

    <!-- 发货弹窗 -->
    <el-dialog v-model="ship.visible" title="发货" width="380px" append-to-body>
      <el-form label-width="80px">
        <el-form-item label="快递公司">
          <el-select v-model="ship.express_com" placeholder="请选择" style="width:100%">
            <el-option v-for="c in expressCompanies" :key="c" :label="c" :value="c" />
          </el-select>
        </el-form-item>
        <el-form-item label="快递单号">
          <el-input v-model="ship.express_no" placeholder="请输入快递单号" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="ship.visible = false">取消</el-button>
        <el-button type="primary" @click="confirmShip">确定</el-button>
      </template>
    </el-dialog>

    <!-- 核销确认弹窗 -->
    <el-dialog v-model="hexiao.visible" title="操作确认" width="360px" append-to-body>
      <div class="confirm-warn">
        <el-icon color="#e6a23c" size="22"><Warning /></el-icon>
        <span>确定要核销并改为已完成状态吗？</span>
      </div>
      <template #footer>
        <el-button @click="hexiao.visible = false">取消</el-button>
        <el-button type="primary" @click="confirmHexiao">确定</el-button>
      </template>
    </el-dialog>

    <!-- 查物流/配送信息弹窗 -->
    <el-dialog v-model="express.visible" title="配送信息" width="480px" append-to-body>
      <div v-if="express.data.has_info">
        <p><b>{{ express.data.express_com }}</b> {{ express.data.express_no }}</p>
        <el-timeline>
          <el-timeline-item v-for="(t, i) in express.data.traces" :key="i" :timestamp="t.time">
            {{ t.context }}
          </el-timeline-item>
        </el-timeline>
      </div>
      <div v-else class="no-express">没有物流信息</div>
    </el-dialog>

    <!-- 送货单平铺浮层（可打印） -->
    <div v-if="shd.visible" class="overlay-mask" @click.self="closeShd">
      <div class="overlay-panel shd-panel">
        <div class="overlay-head">
          <el-button type="success" size="small" @click="doPrint">打印</el-button>
          <el-button circle size="small" @click="closeShd">✕</el-button>
        </div>
        <div class="shd-paper" v-if="shd.data">
          <div class="shd-shop">{{ shd.data.shop_name }}</div>
          <div class="shd-subtitle">{{ shd.data.shop_name }}售货单</div>
          <div class="shd-meta">
            <span>NO: {{ shd.data.ordernum }}</span>
            <span>送货日期: {{ shd.data.send_date }}</span>
          </div>
          <div class="shd-cust">
            客户名称: {{ shd.data.linkman }}　客户电话: {{ shd.data.tel }}<br />
            客户地址: {{ shd.data.address }}<br />
            备注: {{ shd.data.remark }}
          </div>
          <table class="shd-table">
            <thead>
              <tr><th>序号</th><th>品名及规格</th><th>数量</th><th>单价</th><th>金额</th><th>备注</th></tr>
            </thead>
            <tbody>
              <tr v-for="r in shd.data.rows" :key="r.seq">
                <td>{{ r.seq }}</td>
                <td class="tl">{{ r.name }}</td>
                <td>{{ r.num }}</td>
                <td>{{ r.price ? r.price.toFixed(2) : '' }}</td>
                <td>{{ r.money.toFixed(2) }}</td>
                <td></td>
              </tr>
            </tbody>
          </table>
          <div class="shd-sum">合计金额(小写): {{ shd.data.total_money.toFixed(2) }}</div>
          <div class="shd-sum">合计金额(大写): {{ shd.data.money_cn }}</div>
          <div class="shd-sign">制单人: ________　送货人: ________</div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, reactive, onMounted, watch } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Warning } from '@element-plus/icons-vue'
import {
  cycleStageList,
  cycleOrderHexiao,
  cycleOrderShd,
  cycleOrderExpress,
  shipCycleOrderStage,
  collectCycleOrderStage,
} from '../../api/cycle'

const props = defineProps({
  orderId: { type: Number, default: 0 }, // >0 = 单订单周期计划；0 = 全部配送
  defaultTab: { type: [String, Number], default: '1' },
})

const expressCompanies = ['EMS', '顺丰速运', '圆通快递', '中通快递', '韵达快递', '申通快递', '京东快递']

const activeTab = ref(String(props.defaultTab))
const loading = ref(false)
const list = ref<any[]>([])
const total = ref(0)
const dateRange = ref<any[]>([])
const query = reactive({ ordernum: '', linkman: '', tel: '', status: '', start_date: '', end_date: '', page: 1, pagesize: 10 })

function fmtTime(ts: any) {
  if (!ts) return ''
  const d = new Date(Number(ts) * 1000)
  const p = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}年${p(d.getMonth() + 1)}月${p(d.getDate())}日 ${p(d.getHours())}:${p(d.getMinutes())}`
}
function stageStatusClass(s: any) {
  return Number(s) === 2 ? 'st-green' : Number(s) === 3 ? 'st-red' : 'st-orange'
}

async function load() {
  loading.value = true
  try {
    const params: Record<string, any> = {
      status: activeTab.value,
      orderid: props.orderId || 0,
      ordernum: query.ordernum,
      linkman: query.linkman,
      tel: query.tel,
      page: query.page,
      pagesize: query.pagesize,
    }
    if (dateRange.value && dateRange.value.length === 2) {
      params.start_date = dateRange.value[0]
      params.end_date = dateRange.value[1]
    }
    const res = await cycleStageList(params)
    list.value = res.list || []
    total.value = res.total || 0
  } finally {
    loading.value = false
  }
}
function reload() {
  query.page = 1
  load()
}
function onTabChange() {
  query.page = 1
  load()
}

/* ---- 发货 ---- */
const ship = reactive({ visible: false, stageId: 0, express_com: '', express_no: '' })
function openShip(row: any) {
  ship.stageId = row.id
  ship.express_com = ''
  ship.express_no = ''
  ship.visible = true
}
async function confirmShip() {
  if (!ship.express_com || !ship.express_no) {
    ElMessage.warning('请选择快递公司并填写快递单号')
    return
  }
  await shipCycleOrderStage(ship.stageId, ship.express_com, ship.express_no)
  ElMessage.success('发货成功')
  ship.visible = false
  load()
}

/* ---- 核销 ---- */
const hexiao = reactive({ visible: false, stageId: 0 })
function openHexiao(row: any) {
  hexiao.stageId = row.id
  hexiao.visible = true
}
async function confirmHexiao() {
  await cycleOrderHexiao(hexiao.stageId)
  ElMessage.success('核销成功')
  hexiao.visible = false
  load()
}

/* ---- 确认收货 ---- */
async function onCollect(row: any) {
  try {
    await ElMessageBox.confirm('确认该期已收货完成吗？', '确认收货', { type: 'info' })
    await collectCycleOrderStage(row.id)
    ElMessage.success('已确认收货')
    load()
  } catch (e) { /* 取消 */ }
}

/* ---- 查物流 / 配送信息 ---- */
const express = reactive({ visible: false, data: { has_info: false, traces: [] as any[] } as any })
async function openExpress(row: any) {
  express.data = await cycleOrderExpress(row.id)
  express.visible = true
}

/* ---- 送货单 ---- */
const shd = reactive({ visible: false, data: null as any })
async function openShd(row: any) {
  shd.data = await cycleOrderShd(row.id)
  shd.visible = true
}
function closeShd() {
  shd.visible = false
  shd.data = null
}
function doPrint() {
  window.print()
}

watch(() => props.orderId, () => load())
onMounted(load)
</script>

<style scoped>
.goods-cell { display:flex; gap:8px; align-items:center; }
.goods-meta { min-width:0; }
.proname { font-size:13px; }
.ggname { font-size:12px; color:#999; }
.price-num .red { color:#ff4246; }
.ordernum { color:#008000; font-size:12px; }
.ctime { color:#008000; font-size:12px; }
.addr { color:#666; font-size:12px; }
.status-tag { padding:2px 6px; border-radius:3px; font-size:12px; }
.st-green { color:#008000; background:#eef9ee; }
.st-red { color:#ff4246; background:#ffecec; }
.st-orange { color:#ff8758; background:#fff3ec; }
.confirm-warn { display:flex; gap:10px; align-items:center; }
.no-express { color:#999; }

/* 送货单平铺浮层 */
.overlay-mask { position:fixed; inset:0; background:rgba(0,0,0,.45); z-index:2000; display:flex; align-items:center; justify-content:center; }
.overlay-panel { background:#fff; width:90%; max-width:960px; max-height:90vh; overflow:auto; border-radius:6px; padding:16px; }
.overlay-head { display:flex; justify-content:space-between; align-items:center; margin-bottom:10px; }
.shd-paper { color:#000; font-family:"Microsoft YaHei",sans-serif; }
.shd-shop { text-align:center; font-size:24px; font-weight:700; letter-spacing:4px; }
.shd-subtitle { text-align:center; font-size:16px; margin:4px 0 12px; }
.shd-meta { display:flex; justify-content:space-between; margin-bottom:8px; }
.shd-cust { border:1px solid #000; border-bottom:none; padding:6px 10px; line-height:1.9; font-size:13px; }
.shd-table { width:100%; border-collapse:collapse; border:1px solid #000; }
.shd-table th, .shd-table td { border:1px solid #000; padding:5px 8px; text-align:center; }
.shd-table td.tl { text-align:left; }
.shd-sum { border:1px solid #000; border-top:none; padding:4px 10px; }
.shd-sign { margin-top:16px; }
</style>
