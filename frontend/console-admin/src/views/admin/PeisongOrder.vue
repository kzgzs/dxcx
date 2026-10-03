<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  delPeisongOrder,
  getPeisongOrderList,
  getPeisongUserOptions,
  setstPeisongOrder,
} from '../../api/peisong'
import type { PeisongOrderItem, PsUserOption } from '../../api/peisong'

// 租户后台 - 同城配送·配送单（批次8 任务223；对标 canzhao PeisongOrder::index）
// bid：商户过滤视图注入 'gt0'；psid/embedded：由配送员列表「查看配送记录」平铺嵌入，按配送员过滤
const props = withDefaults(
  defineProps<{ bid?: string; psid?: string | number; embedded?: boolean }>(),
  { bid: '', psid: '', embedded: false },
)
const emit = defineEmits<{ (e: 'close'): void }>()

const loading = ref(false)
const tableData = ref<PeisongOrderItem[]>([])
const total = ref(0)
const psOptions = ref<PsUserOption[]>([])

const query = reactive({
  page: 1,
  pagesize: 10,
  mid: '',
  nickname: '',
  ordernum: '',
  psid: '',
  pstype: '',
  status: '',
})

/** 配送状态文字颜色（照 canzhao：0红 / 1,2,3绿 / 4灰 / 10浅灰） */
const STATUS_COLOR: Record<number, string> = {
  0: '#f56c6c',
  1: '#67c23a',
  2: '#67c23a',
  3: '#67c23a',
  4: '#999999',
  10: '#cccccc',
}

/** 固定配送员（查看配送记录）时隐藏配送员下拉 */
const fixedPsid = computed(() => props.psid !== '' && props.psid !== undefined && props.psid !== null)

function oi(row: PeisongOrderItem, k: string): string {
  return String((row.orderinfo as Record<string, unknown>)[k] ?? '')
}
interface ProItem {
  pic?: string
  name?: string
  ggname?: string
  sell_price?: string
  num?: number | string
}
function prolist(row: PeisongOrderItem): ProItem[] {
  return (row.prolist || []) as unknown as ProItem[]
}
function fmtOrderTime(ts?: number | string): string {
  const t = Number(ts)
  if (!t) return ''
  const d = new Date(t * 1000)
  const p = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}年${p(d.getMonth() + 1)}月${p(d.getDate())}日 ${p(d.getHours())}:${p(d.getMinutes())}`
}

async function fetchList() {
  loading.value = true
  try {
    const data = await getPeisongOrderList({
      page: query.page,
      pagesize: query.pagesize,
      mid: query.mid || undefined,
      nickname: query.nickname || undefined,
      ordernum: query.ordernum || undefined,
      psid: fixedPsid.value ? props.psid : query.psid === '' ? undefined : query.psid,
      pstype: query.pstype === '' ? undefined : query.pstype,
      status: query.status === '' ? undefined : query.status,
      bid: props.bid || undefined,
    })
    tableData.value = data.list || []
    total.value = data.total || 0
  } catch {
    // axios 拦截器已提示
  } finally {
    loading.value = false
  }
}

async function fetchPsOptions() {
  if (fixedPsid.value) return
  try {
    const data = await getPeisongUserOptions()
    psOptions.value = data.list || []
  } catch {
    // 忽略
  }
}

function handleSearch() {
  query.page = 1
  fetchList()
}

/** 行操作是否可完成/取消（status 非 4已完成 非 10已取消） */
function canOperate(row: PeisongOrderItem): boolean {
  return row.status !== 4 && row.status !== 10
}

/** 完成：st=4；取消：st=10（确认弹窗照截图） */
async function handleSetst(row: PeisongOrderItem, st: number) {
  const text = st === 10 ? '确定要取消吗？' : '确定要完成配送单吗？'
  await ElMessageBox.confirm(text, '操作确认', {
    type: 'warning',
    confirmButtonText: '确定',
    cancelButtonText: '取消',
  })
  await setstPeisongOrder({ ids: [row.id], st, bid: props.bid || undefined })
  ElMessage.success('操作成功')
  fetchList()
}

async function handleDel(row: PeisongOrderItem) {
  await ElMessageBox.confirm('确定要删除吗？删除后无法恢复！', '操作确认', {
    type: 'warning',
    confirmButtonText: '确定',
    cancelButtonText: '取消',
  })
  await delPeisongOrder([row.id], props.bid || undefined)
  ElMessage.success('删除成功')
  fetchList()
}

/** 导出EXCEL：当前已加载的过滤结果导出 CSV（照截图保留按钮） */
function handleExport() {
  const head = ['ID', '所属商家', '配送类型', '配送提成', '配送状态', '配送员', '配送员电话', '下单人', '订单号', '总价', '实付款', '收货地址', '支付方式']
  const lines = tableData.value.map((r) => {
    const addr = [oi(r, 'linkman'), oi(r, 'tel'), oi(r, 'area'), oi(r, 'address')].filter(Boolean).join(' ')
    return [
      r.id,
      (r.binfo as Record<string, unknown>).name ?? '',
      r.pstype,
      r.ticheng,
      r.status_name,
      r.psuser.realname,
      r.psuser.tel,
      r.member.nickname || `会员${r.member.id}`,
      oi(r, 'ordernum'),
      oi(r, 'product_price'),
      oi(r, 'totalprice'),
      addr,
      oi(r, 'paytype'),
    ]
      .map((c) => `"${String(c).replace(/"/g, '""')}"`)
      .join(',')
  })
  const csv = '﻿' + head.join(',') + '\n' + lines.join('\n')
  const blob = new Blob([csv], { type: 'text/csv;charset=utf-8' })
  const a = document.createElement('a')
  a.href = URL.createObjectURL(blob)
  a.download = '配送记录.csv'
  a.click()
  URL.revokeObjectURL(a.href)
}

onMounted(() => {
  fetchList()
  fetchPsOptions()
})
</script>

<template>
  <div class="peisong-order-page">
    <!-- 平铺嵌入（查看配送记录）时的标题条 + 右上 X -->
    <div v-if="embedded" class="page-head-bar">
      <span class="page-title"><span class="title-bar">≡</span>配送记录</span>
      <el-button class="edit-close" circle @click="emit('close')">
        <svg viewBox="0 0 1024 1024" width="16" height="16" fill="currentColor"><path d="M764.288 214.595 512 466.883 259.712 214.595a32 32 0 0 0-45.248 45.248L466.752 512 214.464 764.288a32 32 0 1 0 45.248 45.248L512 557.117l252.288 252.419a32 32 0 0 0 45.248-45.248L557.248 512l252.288-252.157a32 32 0 0 0-45.248-45.248z" /></svg>
      </el-button>
    </div>

    <!-- 工具栏：导出按钮左 + 搜索右（同行不换行，框收窄） -->
    <el-card shadow="never" class="toolbar-card">
      <div class="toolbar-left">
        <el-button @click="handleExport">导出EXCEL</el-button>
      </div>
      <el-form inline class="toolbar-search" @submit.prevent>
        <el-form-item label="下单人ID">
          <el-input v-model="query.mid" clearable style="width: 90px" @keyup.enter="handleSearch" />
        </el-form-item>
        <el-form-item label="关键字">
          <el-input v-model="query.nickname" placeholder="下单人昵称/姓名/手机号" clearable style="width: 130px" @keyup.enter="handleSearch" />
        </el-form-item>
        <el-form-item label="订单号">
          <el-input v-model="query.ordernum" clearable style="width: 110px" @keyup.enter="handleSearch" />
        </el-form-item>
        <el-form-item v-if="!fixedPsid" label="配送员">
          <el-select v-model="query.psid" placeholder="全部" clearable style="width: 100px">
            <el-option label="全部" value="" />
            <el-option v-for="u in psOptions" :key="u.id" :label="u.realname" :value="String(u.id)" />
          </el-select>
        </el-form-item>
        <el-form-item label="配送类型">
          <el-select v-model="query.pstype" placeholder="全部" clearable style="width: 95px">
            <el-option label="全部" value="" />
            <el-option label="系统配送" value="1" />
            <el-option label="码科配送" value="2" />
          </el-select>
        </el-form-item>
        <el-form-item label="配送状态">
          <el-select v-model="query.status" placeholder="全部" clearable style="width: 95px">
            <el-option label="全部" value="" />
            <el-option label="待接单" value="0" />
            <el-option label="已接单" value="1" />
            <el-option label="已到店" value="2" />
            <el-option label="已取货" value="3" />
            <el-option label="已完成" value="4" />
            <el-option label="已取消" value="10" />
          </el-select>
        </el-form-item>
        <el-form-item>
          <el-button type="success" class="search-btn" @click="handleSearch">
            <svg viewBox="0 0 1024 1024" width="16" height="16" fill="currentColor"><path d="M945.82578 870.125405l-179.064759-179.128198c37.633442-50.252111 60.226958-112.396119 60.226958-179.635713 0-165.019429-133.863717-298.820032-298.94691-298.820032S229.157273 346.342065 229.157273 511.361494s133.800278 298.883471 298.883471 298.883471c64.175523 0 123.67499-20.451714 172.925368-54.893622l180.206658 180.270097c8.639241 8.575802 19.883819 12.926817 31.191832 12.926817s22.552591-4.351015 31.128393-12.926817C962.978033 917.976954 962.978033 887.274231 945.82578 870.125405zM528.040744 710.308079c-109.760556 0-198.946585-89.122915-198.946585-198.946585s89.186354-198.946585 198.946585-198.946585 198.946585 89.122915 198.946585 198.946585S637.8013 710.308079 528.040744 710.308079z" /></svg>
          </el-button>
        </el-form-item>
      </el-form>
    </el-card>

    <!-- 配送单表格（无 fixed，表头 #e6e6e6，内容居左） -->
    <el-card shadow="never">
      <el-table
        v-loading="loading"
        :data="tableData"
        border
        :header-cell-style="{ background: '#e6e6e6' }"
      >
        <el-table-column prop="id" label="ID" width="70" align="left" header-align="left" />
        <el-table-column label="所属商家" width="120" align="left" header-align="left">
          <template #default="{ row }">
            {{ (row.binfo && row.binfo.name) || '-' }}
          </template>
        </el-table-column>
        <el-table-column label="配送信息" width="190" align="left" header-align="left">
          <template #default="{ row }">
            <div class="psinfo-line">配送类型：{{ row.pstype || '-' }}</div>
            <div class="psinfo-line">配送提成：{{ row.ticheng }}</div>
            <div class="psinfo-line">
              配送状态：<span :style="{ color: STATUS_COLOR[row.status] || '#606266' }">{{ row.status_name }}</span>
            </div>
            <div class="psinfo-line">配送员：{{ row.psuser.realname || '-' }}</div>
            <div class="psinfo-line">配送员电话：{{ row.psuser.tel || '-' }}</div>
          </template>
        </el-table-column>
        <el-table-column label="下单人" width="150" align="left" header-align="left">
          <template #default="{ row }">
            <div class="member-cell">
              <img v-if="row.member.headimg" :src="row.member.headimg" class="member-img" />
              <div v-else class="member-img member-img-empty">
                <svg viewBox="0 0 1024 1024" width="22" height="22" fill="#c0c4cc"><path d="M512 512a192 192 0 1 0 0-384 192 192 0 0 0 0 384zm0 64c-120 0-224 67-256 166a32 32 0 0 0 30 42h452a32 32 0 0 0 30-42c-32-99-136-166-256-166z"/></svg>
              </div>
              <div class="member-meta">
                <div>{{ row.member.nickname || 'null' }}</div>
                <div class="sub-text">(ID:{{ row.member.id }})</div>
              </div>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="商品信息" width="240" align="left" header-align="left">
          <template #default="{ row }">
            <div v-for="(g, i) in prolist(row)" :key="i" class="goods-cell">
              <img v-if="g.pic" :src="g.pic" class="goods-pic" />
              <div v-else class="goods-pic goods-pic-empty"></div>
              <div class="goods-meta">
                <div class="goods-name">{{ g.name }}</div>
                <div v-if="g.ggname" class="goods-gg">{{ g.ggname }}</div>
                <div class="goods-price">￥{{ g.sell_price }} × {{ g.num }}</div>
              </div>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="商品总价/实付款" width="110" align="left" header-align="left">
          <template #default="{ row }">
            <div>总价：￥{{ oi(row, 'product_price') || '0' }}</div>
            <div class="strong">实付：￥{{ oi(row, 'totalprice') || '0' }}</div>
          </template>
        </el-table-column>
        <el-table-column label="订单号/下单时间" width="150" align="left" header-align="left">
          <template #default="{ row }">
            <div>{{ row.ordernum || oi(row, 'ordernum') || '-' }}</div>
            <div class="order-time">{{ fmtOrderTime(Number(row.createtime) || Number(oi(row, 'createtime'))) }}</div>
          </template>
        </el-table-column>
        <el-table-column label="收货地址" width="200" align="left" header-align="left">
          <template #default="{ row }">
            <div class="strong">{{ oi(row, 'linkman') }} {{ oi(row, 'tel') }}</div>
            <div class="addr">{{ oi(row, 'area') }} {{ oi(row, 'address') }}</div>
          </template>
        </el-table-column>
        <el-table-column label="支付方式" width="90" align="left" header-align="left">
          <template #default="{ row }">{{ oi(row, 'paytype') || '-' }}</template>
        </el-table-column>
        <el-table-column label="操作" width="110" align="left" header-align="left">
          <template #default="{ row }">
            <div class="row-ops">
              <template v-if="canOperate(row)">
                <el-button link type="primary" size="small" @click="handleSetst(row, 4)">完成</el-button>
                <el-button link type="primary" size="small" @click="handleSetst(row, 10)">取消</el-button>
              </template>
              <el-button v-else link type="primary" size="small" @click="handleDel(row)">删除</el-button>
            </div>
          </template>
        </el-table-column>
        <template #empty>暂无数据</template>
      </el-table>

      <el-pagination
        v-model:current-page="query.page"
        v-model:page-size="query.pagesize"
        :total="total"
        :page-sizes="[10, 20, 50]"
        layout="total, sizes, prev, pager, next, jumper"
        class="pagination"
        @change="fetchList"
      />
    </el-card>
  </div>
</template>

<style scoped>
.peisong-order-page {
  display: block;
}
.page-head-bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 4px 2px 8px;
}
.page-title {
  font-size: 15px;
  font-weight: 600;
  color: #303133;
}
.title-bar {
  margin-right: 4px;
}
.edit-close {
  border: none;
  background: transparent;
  color: #909399;
}
.edit-close:hover {
  color: #409eff;
  background: transparent;
}
.toolbar-card {
  margin-bottom: 3px;
}
.toolbar-card :deep(.el-card__body) {
  padding: 10px 14px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  flex-wrap: nowrap;
}
.toolbar-left {
  display: flex;
  gap: 8px;
  flex: 0 0 auto;
}
.toolbar-left .el-button {
  margin: 0;
}
.toolbar-search {
  display: flex;
  align-items: center;
  flex: 1 1 auto;
  justify-content: flex-end;
  flex-wrap: nowrap;
  margin: 0;
  min-width: 0;
}
.toolbar-search :deep(.el-form-item) {
  margin-bottom: 0;
  margin-right: 6px;
  flex-shrink: 0;
}
.toolbar-search :deep(.el-form-item:last-child) {
  margin-right: 0;
}
.toolbar-search :deep(.el-form-item__label) {
  font-size: 12px;
}
.search-btn {
  padding: 8px 14px;
}
.search-btn svg {
  display: block;
}
.pagination {
  margin-top: 12px;
  justify-content: flex-end;
}
.psinfo-line {
  line-height: 18px;
  font-size: 12px;
}
.member-cell {
  display: flex;
  align-items: flex-start;
  gap: 8px;
}
.member-img {
  width: 40px;
  height: 40px;
  border-radius: 4px;
  flex-shrink: 0;
  background: #f5f7fa;
}
.member-img-empty {
  display: flex;
  align-items: center;
  justify-content: center;
}
.member-meta {
  min-width: 0;
  font-size: 12px;
  line-height: 18px;
}
.sub-text {
  color: #909399;
  font-size: 12px;
}
.goods-cell {
  display: flex;
  gap: 8px;
  margin-bottom: 4px;
}
.goods-pic {
  width: 44px;
  height: 44px;
  border-radius: 4px;
  flex-shrink: 0;
  background: #f5f7fa;
}
.goods-pic-empty {
  background: #f5f7fa;
}
.goods-meta {
  min-width: 0;
  font-size: 12px;
  line-height: 16px;
}
.goods-name {
  color: #303133;
}
.goods-gg {
  color: #888;
}
.goods-price {
  color: #f60;
}
.strong {
  font-weight: 600;
}
.order-time {
  color: #080;
  font-size: 12px;
}
.addr {
  font-size: 12px;
  color: #606266;
  line-height: 18px;
}
.row-ops {
  display: flex;
  gap: 10px;
}
.row-ops .el-button {
  margin: 0;
}
</style>
