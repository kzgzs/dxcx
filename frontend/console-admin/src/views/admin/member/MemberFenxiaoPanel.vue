<template>
  <div class="fp-panel">
    <div class="ge-head">
      <span class="ge-title">分销订单 · 佣金订单记录</span>
      <el-button class="ge-close" circle @click="emit('close')">
        <svg viewBox="0 0 1024 1024" width="16" height="16" fill="currentColor"><path d="M764.288 214.595 512 466.883 259.712 214.595a32 32 0 0 0-45.248 45.248L466.752 512 214.464 764.288a32 32 0 1 0 45.248 45.248L512 557.117l252.288 252.419a32 32 0 0 0 45.248-45.248L557.248 512l252.288-252.157a32 32 0 0 0-45.248-45.248z" /></svg>
      </el-button>
    </div>

    <el-card shadow="never" class="toolbar-card">
      <div class="toolbar-line">
        <el-input v-model.number="query.buy_mid" placeholder="会员ID" clearable class="w90" @keyup.enter="onSearch" />
        <el-select v-model="query.status" placeholder="状态" clearable class="w110">
          <el-option label="未发放" :value="0" />
          <el-option label="已发放" :value="1" />
          <el-option label="已退回" :value="2" />
        </el-select>
        <el-select v-model="query.type" placeholder="订单类型" clearable class="w120">
          <el-option label="商城订单" value="shop" />
        </el-select>
        <el-input v-model="query.ordernum" placeholder="订单号" clearable class="w150" @keyup.enter="onSearch" />
        <el-input v-model.number="query.orderid" placeholder="订单ID" clearable class="w100" @keyup.enter="onSearch" />
        <el-date-picker
          v-model="dateRange"
          type="daterange"
          value-format="YYYY-MM-DD"
          range-separator="-"
          start-placeholder="开始"
          end-placeholder="结束"
          class="date-range"
        />
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
      <el-table :data="list" v-loading="loading" border size="small" class="data-table" @sort-change="onSort">
        <el-table-column prop="id" label="ID" width="80" align="center" />
        <el-table-column prop="orderid" label="订单ID" width="90" align="center" />
        <el-table-column label="商家" min-width="110" v-if="colVisible.business">
          <template #default="{ row }">{{ row.business_name || '平台自营' }}</template>
        </el-table-column>
        <el-table-column label="订单类型" width="95" v-if="colVisible.type">
          <template #default="{ row }">{{ row.type_text || row.type }}</template>
        </el-table-column>
        <el-table-column prop="mid" label="会员ID" width="80" align="center" />
        <el-table-column label="来源会员" min-width="140" v-if="colVisible.source">
          <template #default="{ row }">
            <div class="owner">
              <el-image :src="assetUrl(row.headimg)" class="owner-pic" fit="cover">
                <template #error><div class="img-fallback round">无</div></template>
              </el-image>
              <div class="owner-meta">
                <div>{{ row.nickname || ('会员' + row.mid) }}</div>
                <div class="muted">ID:{{ row.mid }}</div>
              </div>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="商品信息" min-width="200" v-if="colVisible.goods">
          <template #default="{ row }">
            <div class="goods-line">
              <el-image :src="assetUrl(row.goods_pic)" class="goods-pic" fit="cover">
                <template #error><div class="img-fallback">无图</div></template>
              </el-image>
              <div class="goods-meta">
                <div class="goods-name">{{ row.goods_name }}</div>
                <div class="goods-gg">{{ row.goods_gg }}<template v-if="row.goods_num">　x{{ row.goods_num }}</template></div>
              </div>
            </div>
          </template>
        </el-table-column>
        <el-table-column prop="ordernum" label="订单号" min-width="160" show-overflow-tooltip v-if="colVisible.ordernum" />
        <el-table-column label="下单时间" width="155" v-if="colVisible.time">
          <template #default="{ row }">{{ fmtTime(row.order_createtime || row.createtime) }}</template>
        </el-table-column>
        <el-table-column label="分销层级" width="85" align="center" v-if="colVisible.level">
          <template #default="{ row }">{{ row.level ? row.level + '级' : '--' }}</template>
        </el-table-column>
        <el-table-column label="真实层级" width="85" align="center" v-if="colVisible.reallevel">
          <template #default="{ row }">{{ row.real_level ? row.real_level + '级' : '--' }}</template>
        </el-table-column>
        <el-table-column prop="commission" label="佣金" width="95" align="right" sortable="custom" v-if="colVisible.commission">
          <template #default="{ row }">¥{{ fmtMoney(row.commission) }}</template>
        </el-table-column>
        <el-table-column prop="score" label="积分" width="80" align="right" sortable="custom" v-if="colVisible.score" />
        <el-table-column label="状态" width="90" align="center" v-if="colVisible.status">
          <template #default="{ row }">
            <el-tag :color="statusColor(row.status)" style="color:#fff;border:none" size="small" disable-transitions>
              {{ row.status_text }}
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
  exportMemberFenxiao,
  getMemberFenxiaoOrders,
  type MemberFenxiaoItem,
  type MemberFenxiaoQuery,
} from '../../../api/member'
import { assetUrl } from '../../../api/upload'

const props = defineProps<{ mid: number }>()
const emit = defineEmits<{ close: [] }>()

const loading = ref(false)
const exporting = ref(false)
const list = ref<MemberFenxiaoItem[]>([])
const total = ref(0)
const dateRange = ref<[string, string] | null>(null)

const query = reactive<MemberFenxiaoQuery>({
  mid: props.mid,
  page: 1,
  pagesize: 10,
  buy_mid: undefined,
  status: '',
  type: '',
  orderid: undefined,
  ordernum: '',
  start_time: '',
  end_time: '',
})

const colOptions = [
  { k: 'business', l: '商家' },
  { k: 'type', l: '订单类型' },
  { k: 'source', l: '来源会员' },
  { k: 'goods', l: '商品信息' },
  { k: 'ordernum', l: '订单号' },
  { k: 'time', l: '下单时间' },
  { k: 'level', l: '分销层级' },
  { k: 'reallevel', l: '真实层级' },
  { k: 'commission', l: '佣金' },
  { k: 'score', l: '积分' },
  { k: 'status', l: '状态' },
  { k: 'remark', l: '备注' },
]
const colVisible = reactive<Record<string, boolean>>({
  business: true, type: true, source: true, goods: true, ordernum: true, time: true,
  level: true, reallevel: true, commission: true, score: true, status: true, remark: true,
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
function statusColor(s: number): string {
  return ({ 0: '#ff9900', 1: '#52c41a', 2: '#909399' } as Record<number, string>)[s] || '#909399'
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

function buildParams(): MemberFenxiaoQuery {
  return {
    ...query,
    buy_mid: query.buy_mid || undefined,
    orderid: query.orderid || undefined,
    start_time: dateRange.value?.[0] || '',
    end_time: dateRange.value?.[1] || '',
  }
}

async function load() {
  loading.value = true
  try {
    const res = await getMemberFenxiaoOrders(buildParams())
    list.value = res.list
    total.value = res.total
  } finally {
    loading.value = false
  }
}

function onSearch() {
  query.page = 1
  load()
}
function onReset() {
  query.buy_mid = undefined
  query.status = ''
  query.type = ''
  query.orderid = undefined
  query.ordernum = ''
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
function onSort({ prop, order }: { prop: string; order: string | null }) {
  // 佣金/积分排序交由后端（记录型列表默认 id desc，此处前端排序当前页足够轻量）
  const dir = order === 'ascending' ? 1 : -1
  list.value = [...list.value].sort((a, b) => {
    const av = Number((a as Record<string, unknown>)[prop] || 0)
    const bv = Number((b as Record<string, unknown>)[prop] || 0)
    return (av - bv) * dir
  })
}
async function handleExport() {
  if (exporting.value) return
  exporting.value = true
  try {
    const blob = await exportMemberFenxiao({ ...buildParams() } as Record<string, unknown>)
    downloadBlob(blob, `会员分销订单_${Date.now()}.xlsx`)
  } finally {
    exporting.value = false
  }
}

onMounted(load)
</script>

<style scoped>
.fp-panel {
  background: #fff;
  border-radius: 6px;
  padding: 12px 12px 16px;
}
.ge-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 8px;
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
.w90 { width: 90px; }
.w100 { width: 100px; }
.w110 { width: 110px; }
.w120 { width: 120px; }
.w150 { width: 150px; }
.date-range { width: 240px; }
.col-set-btn { padding: 6px; }
.col-set-pop {
  display: flex;
  flex-direction: column;
  gap: 6px;
}
.table-card :deep(.el-card__body) {
  padding: 8px;
}
.owner {
  display: flex;
  align-items: center;
  gap: 6px;
}
.owner-pic {
  width: 34px;
  height: 34px;
  border-radius: 50%;
  flex: none;
}
.owner-meta {
  line-height: 1.4;
  font-size: 12px;
}
.muted {
  color: #909399;
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
.img-fallback.round {
  width: 34px;
  height: 34px;
  border-radius: 50%;
}
.goods-meta {
  line-height: 1.5;
}
.goods-name {
  font-size: 12px;
}
.goods-gg {
  color: #909399;
  font-size: 12px;
}
.pager {
  display: flex;
  justify-content: flex-end;
  margin-top: 8px;
}
</style>
