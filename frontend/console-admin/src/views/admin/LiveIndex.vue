<script setup lang="ts">
/**
 * 扩展·小程序直播（对齐点大 Live）
 * 视图状态机：list 直播管理 / roomEdit 创建·编辑直播间（平铺主区域+右上X）
 *           goodsLibrary 商品库（平铺+X）/ goodsEdit 添加商品·修改价格（平铺+X）
 * 小弹窗：直播码（主播端小程序码）、选择商品（商品库审核通过）
 */
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import type { FormInstance } from 'element-plus'
import {
  liveList,
  liveDetail,
  liveSave,
  liveSetst,
  liveDel,
  livePusherQr,
  liveTongbu,
  liveGoodsList,
  liveGoodsAdd,
  liveGoodsUpd,
  liveGoodsDel,
  liveRoomAddgoods,
} from '../../api/live'
import type { LiveRoomRow, LiveGoodsRow } from '../../api/live'
import ImgIconPicker from '../../components/ImgIconPicker.vue'
import LinkPicker from '../../components/LinkPicker.vue'

type ViewMode = 'list' | 'roomEdit' | 'goodsLibrary' | 'goodsEdit'
const viewMode = ref<ViewMode>('list')

// ================= 直播间列表 =================
const loading = ref(false)
const tableData = ref<LiveRoomRow[]>([])
const total = ref(0)
const query = reactive({ page: 1, pagesize: 10 })
const selected = ref<LiveRoomRow[]>([])

async function fetchList() {
  loading.value = true
  try {
    const d = await liveList({ page: query.page, pagesize: query.pagesize })
    tableData.value = d.list || []
    total.value = d.total || 0
  } finally {
    loading.value = false
  }
}
function onSelectionChange(rows: LiveRoomRow[]) {
  selected.value = rows
}
function handlePage(p: number) {
  query.page = p
  fetchList()
}
function handleSize(s: number) {
  query.pagesize = s
  query.page = 1
  fetchList()
}

async function batchSetSt(st: number) {
  const ids = selected.value.map((r) => r.id)
  if (!ids.length) {
    ElMessage.warning('请先选择直播间')
    return
  }
  await liveSetst(ids, st)
  ElMessage.success('操作成功')
  fetchList()
}
async function batchDel() {
  const ids = selected.value.map((r) => r.id)
  if (!ids.length) {
    ElMessage.warning('请先选择直播间')
    return
  }
  await ElMessageBox.confirm('确定删除选中的直播间吗？', '提示', { type: 'warning' })
  await liveDel(ids)
  ElMessage.success('删除成功')
  fetchList()
}
async function handleTongbu() {
  await ElMessageBox.confirm('确定从小程序中同步直播间吗？', '提示', { type: 'warning' })
  await liveTongbu()
  ElMessage.success('同步完成')
  fetchList()
}

// ================= 创建/编辑直播间 =================
const roomFormRef = ref<FormInstance>()
const roomForm = reactive<any>({})
const roomIsEdit = ref(false)

function defaultRoomForm() {
  return {
    id: 0,
    name: '',
    coverImg: '',
    startTime: '',
    endTime: '',
    anchorName: '',
    anchorWechat: '',
    subAnchorWechat: '',
    createrWechat: '',
    shareImg: '',
    feedsImg: '',
    isFeedsPublic: 1,
    type: 0,
    screenType: 0,
    closeLike: 0,
    closeGoods: 0,
    closeComment: 0,
    closeReplay: 0,
    closeShare: 0,
    closeKf: 0,
  }
}
function openCreate() {
  roomIsEdit.value = false
  Object.assign(roomForm, defaultRoomForm())
  viewMode.value = 'roomEdit'
}
async function openEdit(row: LiveRoomRow) {
  roomIsEdit.value = true
  const d = await liveDetail(row.id)
  Object.assign(roomForm, defaultRoomForm(), d, {
    startTime: d.startTime ? fmtDateTime(d.startTime) : '',
    endTime: d.endTime ? fmtDateTime(d.endTime) : '',
  })
  viewMode.value = 'roomEdit'
}
async function submitRoom() {
  await roomFormRef.value?.validate()
  if (!roomForm.startTime || !roomForm.endTime) {
    ElMessage.warning('请选择直播开始/结束时间')
    return
  }
  await liveSave({ ...roomForm })
  ElMessage.success('操作成功')
  backToList()
  fetchList()
}

// ================= 商品库 =================
const goodsTab = ref(1)
const goodsLoading = ref(false)
const goodsRows = ref<LiveGoodsRow[]>([])
const goodsTotal = ref(0)
const goodsQuery = reactive({ page: 1, pagesize: 10 })

interface NormGoods {
  id: number
  name: string
  cover: string
  url: string
  priceType: number
  priceYuan: string
  price2Yuan: string
}
const normGoods = computed<NormGoods[]>(() =>
  goodsRows.value.map((g) => ({
    id: Number(g.goodsId ?? g.goods_id ?? g.id),
    name: g.name,
    cover: g.coverImgUrl || g.cover_img || '',
    url: g.url || '',
    priceType: Number(g.priceType ?? g.price_type ?? 1),
    priceYuan: g.price_yuan ?? fenToYuan(Number(g.price || 0)),
    price2Yuan: g.price2_yuan ?? fenToYuan(Number(g.price2 || 0)),
  })),
)

async function fetchGoods() {
  goodsLoading.value = true
  try {
    const d = await liveGoodsList({ st: goodsTab.value, page: goodsQuery.page, pagesize: goodsQuery.pagesize })
    goodsRows.value = d.list || []
    goodsTotal.value = d.total || 0
  } finally {
    goodsLoading.value = false
  }
}
function switchGoodsTab(t: number) {
  goodsTab.value = t
  goodsQuery.page = 1
  fetchGoods()
}
function goodsPage(p: number) {
  goodsQuery.page = p
  fetchGoods()
}
function goodsSize(s: number) {
  goodsQuery.pagesize = s
  goodsQuery.page = 1
  fetchGoods()
}
async function delGoods(row: NormGoods) {
  await ElMessageBox.confirm('确定删除该商品吗？', '提示', { type: 'warning' })
  await liveGoodsDel(row.id)
  ElMessage.success('删除成功')
  fetchGoods()
}

// ================= 添加商品 / 修改价格 =================
const goodsIsEdit = ref(false)
const goodsFormRef = ref<FormInstance>()
const goodsForm = reactive<any>({
  id: 0,
  name: '',
  coverImgUrl: '',
  url: '',
  priceType: 1,
  price: '',
  price2: '',
})
function openGoodsAdd() {
  goodsIsEdit.value = false
  Object.assign(goodsForm, { id: 0, name: '', coverImgUrl: '', url: '', priceType: 1, price: '', price2: '' })
  viewMode.value = 'goodsEdit'
}
function openGoodsEdit(row: NormGoods) {
  goodsIsEdit.value = true
  Object.assign(goodsForm, {
    id: row.id,
    name: row.name,
    coverImgUrl: row.cover,
    url: row.url,
    priceType: row.priceType,
    price: row.priceYuan,
    price2: row.price2Yuan,
  })
  viewMode.value = 'goodsEdit'
}
async function submitGoods() {
  if (!goodsIsEdit.value) {
    if (!goodsForm.name.trim()) return ElMessage.warning('请填写商品名称')
    if (!goodsForm.coverImgUrl) return ElMessage.warning('请上传商品图片')
  }
  if (goodsForm.priceType !== 1 && goodsForm.price2 === '') return ElMessage.warning('请填写完整价格')
  if (goodsIsEdit.value) {
    await liveGoodsUpd(goodsForm.id, {
      priceType: goodsForm.priceType,
      price: goodsForm.price,
      price2: goodsForm.price2,
    })
  } else {
    await liveGoodsAdd({ ...goodsForm })
  }
  ElMessage.success('操作成功')
  viewMode.value = 'goodsLibrary'
  fetchGoods()
}

// ================= 图片选择 =================
const imgPickerVisible = ref(false)
let imgSetter: ((u: string) => void) | null = null
function openImg(setter: (u: string) => void) {
  imgSetter = setter
  imgPickerVisible.value = true
}
function onPickImg(urls: string[]) {
  if (urls[0] && imgSetter) imgSetter(urls[0])
  imgSetter = null
}

// ================= 直播码 =================
const qrVisible = ref(false)
const qrUrl = ref('')
const qrLoading = ref(false)
async function showQr(row: LiveRoomRow) {
  qrVisible.value = true
  qrLoading.value = true
  qrUrl.value = ''
  try {
    const d = await livePusherQr(row.id)
    qrUrl.value = d.url
  } finally {
    qrLoading.value = false
  }
}

// ================= 选择商品 =================
const pickVisible = ref(false)
const pickRoom = ref<LiveRoomRow | null>(null)
const pickLoading = ref(false)
const pickRows = ref<NormGoods[]>([])
const pickIds = ref<number[]>([])
async function openPick(row: LiveRoomRow) {
  pickRoom.value = row
  pickIds.value = []
  pickVisible.value = true
  pickLoading.value = true
  try {
    const d = await liveGoodsList({ st: 2, page: 1, pagesize: 100 })
    goodsRows.value = d.list || []
    pickRows.value = normGoods.value
  } finally {
    pickLoading.value = false
  }
}
async function confirmPick() {
  if (!pickRoom.value) return
  if (!pickIds.value.length) {
    ElMessage.warning('请选择商品')
    return
  }
  await liveRoomAddgoods(pickRoom.value.id, pickIds.value)
  ElMessage.success('添加成功')
  pickVisible.value = false
  fetchList()
}

// ================= 复制链接 =================
async function copyLink(row: LiveRoomRow) {
  const link = `plugin-private://wx2b03c6e691cd7370/pages/live-player-plugin?room_id=${row.roomId}`
  try {
    await navigator.clipboard.writeText(link)
  } catch {
    const ta = document.createElement('textarea')
    ta.value = link
    document.body.appendChild(ta)
    ta.select()
    document.execCommand('copy')
    document.body.removeChild(ta)
  }
  ElMessage.success('链接已复制：' + link)
}

// ================= 工具 =================
function backToList() {
  viewMode.value = 'list'
  fetchList()
}
function backToLibrary() {
  viewMode.value = 'goodsLibrary'
  fetchGoods()
}
function fmtDateTime(ts: number): string {
  if (!ts) return ''
  const d = new Date(ts * 1000)
  const p = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}:${p(d.getSeconds())}`
}
function fenToYuan(fen: number): string {
  const v = fen / 100
  return Number.isInteger(v) ? String(v) : v.toFixed(2)
}
/** 直播间商品价格展示：1一口价 2区间 3折扣价 */
function goodsPriceText(g: any): { type: number; main: string; origin?: string } {
  const pt = Number(g.price_type ?? 1)
  if (pt === 2) return { type: 2, main: `${fenToYuan(Number(g.price || 0))} ~ ${fenToYuan(Number(g.price2 || 0))}元` }
  if (pt === 3) return { type: 3, main: fenToYuan(Number(g.price2 || 0)), origin: fenToYuan(Number(g.price || 0)) }
  return { type: 1, main: `${fenToYuan(Number(g.price || 0))}元` }
}
function parseRoomGoods(row: LiveRoomRow): any[] {
  if (Array.isArray(row.goods)) return row.goods
  try {
    const v = JSON.parse((row as any).goodsStr || '[]')
    return Array.isArray(v) ? v : []
  } catch {
    return []
  }
}
/** 直播状态：101直播中 102未开始 103已结束 104已暂停 105异常 106过期 */
function liveStatusText(s: string): { text: string; cls: string } {
  const map: Record<string, [string, string]> = {
    '101': ['直播中', 'st-live'],
    '102': ['未开始', 'st-pending'],
    '103': ['已结束', 'st-end'],
    '104': ['已暂停', 'st-pending'],
    '105': ['异常', 'st-off'],
    '106': ['已过期', 'st-end'],
  }
  const v = map[s] || ['未知', 'st-end']
  return { text: v[0], cls: v[1] }
}

onMounted(() => {
  fetchList()
})
</script>

<template>
  <!-- ================= 直播管理列表 ================= -->
  <div v-if="viewMode === 'list'">
    <el-alert type="info" :closable="false" class="notice-box">
      <template #title>
        <div class="notice-line">1、直播功能仅支持小程序端，不支持公众号H5端。</div>
        <div class="notice-line">
          2、请先前往
          <a href="https://mp.weixin.qq.com" target="_blank" rel="noopener">微信公众平台</a>
          开通小程序直播功能，开通后需重新上传小程序代码并提交审核。
        </div>
        <div class="notice-line">
          3、直播间跳转链接：plugin-private://wx2b03c6e691cd7370/pages/live-player-plugin?room_id=[房间号]；也可在「设计-页面设计」新建页面选择直播组件。
        </div>
      </template>
    </el-alert>

    <el-card shadow="never" class="toolbar">
      <div class="toolbar-left">
        <el-button type="success" @click="openCreate">创建直播间</el-button>
        <el-button @click="handleTongbu">从小程序中同步</el-button>
        <el-button @click="batchSetSt(1)">开启</el-button>
        <el-button @click="batchSetSt(0)">关闭</el-button>
        <el-button @click="() => { viewMode = 'goodsLibrary'; goodsQuery.page = 1; fetchGoods() }">商品库</el-button>
      </div>
    </el-card>

    <el-card shadow="never">
      <el-table v-loading="loading" :data="tableData" border @selection-change="onSelectionChange">
        <el-table-column type="selection" width="40" align="center" />
        <el-table-column prop="id" label="ID" width="70" sortable align="left" />
        <el-table-column prop="roomId" label="房间号" width="80" align="left" />
        <el-table-column label="直播间信息" min-width="240">
          <template #default="{ row }">
            <div class="room-info">
              <el-image v-if="row.coverImg" :src="row.coverImg" fit="cover" class="room-cover" preview-teleported />
              <div v-else class="room-cover room-cover-empty">无图</div>
              <div class="room-meta">
                <div class="room-name">{{ row.name }}</div>
                <div class="room-time room-time-on">开播时间：{{ fmtDateTime(row.startTime) }}</div>
                <div class="room-time">结束时间：{{ fmtDateTime(row.endTime) }}</div>
                <div class="room-anchor">主播：{{ row.anchorName }}</div>
              </div>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="直播间商品" min-width="200">
          <template #default="{ row }">
            <div v-for="g in parseRoomGoods(row)" :key="g.goods_id" class="room-goods">
              <el-image v-if="g.cover_img" :src="g.cover_img" fit="cover" class="rg-img" preview-teleported />
              <div class="rg-meta">
                <div class="rg-name">{{ g.name }}</div>
                <div class="rg-price">
                  <template v-if="goodsPriceText(g).type === 3">
                    <span class="rg-origin">{{ goodsPriceText(g).origin }}元</span>
                    <span class="rg-now">{{ goodsPriceText(g).main }}元</span>
                  </template>
                  <template v-else>{{ goodsPriceText(g).main }}</template>
                </div>
              </div>
            </div>
            <span v-if="!parseRoomGoods(row).length" class="rg-empty">暂无商品</span>
          </template>
        </el-table-column>
        <el-table-column label="直播状态" width="90" align="left">
          <template #default="{ row }">
            <span :class="liveStatusText(row.live_status).cls">{{ liveStatusText(row.live_status).text }}</span>
          </template>
        </el-table-column>
        <el-table-column label="列表显示" width="90" align="left">
          <template #default="{ row }">
            <span :class="row.status === 1 ? 'ck-pass' : 'st-end'">{{ row.status === 1 ? '已开启' : '已关闭' }}</span>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="190">
          <template #default="{ row }">
            <div class="row-ops">
              <el-button link type="primary" @click="copyLink(row)">复制链接</el-button>
              <el-button link type="primary" @click="showQr(row)">直播码</el-button>
              <el-button link type="primary" @click="openPick(row)">选择商品</el-button>
            </div>
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
        @current-change="handlePage"
        @size-change="handleSize"
      />
    </el-card>
  </div>

  <!-- ================= 创建/编辑直播间（平铺） ================= -->
  <div v-else-if="viewMode === 'roomEdit'" class="goods-edit">
    <div class="ge-head">
      <div class="ge-title">{{ roomIsEdit ? '编辑直播间' : '创建直播间' }}</div>
      <el-button class="ge-close" circle @click="backToList">
        <svg viewBox="0 0 1024 1024" width="16" height="16" fill="currentColor"><path d="M764.288 214.595 512 466.883 259.712 214.595a32 32 0 0 0-45.248 45.248L466.752 512 214.464 764.288a32 32 0 1 0 45.248 45.248L512 557.117l252.288 252.419a32 32 0 0 0 45.248-45.248L557.248 512l252.288-252.157a32 32 0 0 0-45.248-45.248z" /></svg>
      </el-button>
    </div>

    <el-form ref="roomFormRef" :model="roomForm" label-width="150px" class="ge-form">
      <el-form-item label="直播间名字" required>
        <el-input v-model="roomForm.name" maxlength="17" show-word-limit style="width: 320px" placeholder="3-17个汉字" />
      </el-form-item>
      <el-form-item label="背景图">
        <el-image v-if="roomForm.coverImg" :src="roomForm.coverImg" fit="cover" class="main-pic" @click="openImg((u) => (roomForm.coverImg = u))" />
        <el-button v-else size="small" @click="openImg((u) => (roomForm.coverImg = u))">上传图片</el-button>
        <span class="ge-tip">建议尺寸1080*1920像素，大小不超过2M</span>
      </el-form-item>
      <el-form-item label="开始时间" required>
        <el-date-picker v-model="roomForm.startTime" type="datetime" value-format="YYYY-MM-DD HH:mm:ss" placeholder="选择开始时间" style="width: 200px" />
        <span class="ge-tip">需在当前时间10分钟后至6个月内</span>
      </el-form-item>
      <el-form-item label="结束时间" required>
        <el-date-picker v-model="roomForm.endTime" type="datetime" value-format="YYYY-MM-DD HH:mm:ss" placeholder="选择结束时间" style="width: 200px" />
        <span class="ge-tip">直播时长30分钟至12小时</span>
      </el-form-item>
      <el-form-item label="主播昵称" required>
        <el-input v-model="roomForm.anchorName" maxlength="15" style="width: 320px" placeholder="2-15个汉字" />
      </el-form-item>
      <el-form-item label="主播微信号" required>
        <el-input v-model="roomForm.anchorWechat" style="width: 320px" placeholder="主播微信号" />
        <span class="ge-tip">主播微信号需完成实名认证，<a href="https://mp.weixin.qq.com/wxamp/wacodepage/getcodepage?token=&lang=zh_CN" target="_blank" rel="noopener">查看实名认证</a></span>
      </el-form-item>
      <el-form-item label="副主播微信号">
        <el-input v-model="roomForm.subAnchorWechat" style="width: 320px" placeholder="副主播微信号" />
      </el-form-item>
      <el-form-item label="创建者微信号">
        <el-input v-model="roomForm.createrWechat" style="width: 320px" placeholder="创建者微信号" />
      </el-form-item>
      <el-form-item label="分享图">
        <el-image v-if="roomForm.shareImg" :src="roomForm.shareImg" fit="cover" class="main-pic" @click="openImg((u) => (roomForm.shareImg = u))" />
        <el-button v-else size="small" @click="openImg((u) => (roomForm.shareImg = u))">上传图片</el-button>
        <span class="ge-tip">建议尺寸800*640像素，大小不超过1M</span>
      </el-form-item>
      <el-form-item label="购物直播频道封面图">
        <el-image v-if="roomForm.feedsImg" :src="roomForm.feedsImg" fit="cover" class="main-pic" @click="openImg((u) => (roomForm.feedsImg = u))" />
        <el-button v-else size="small" @click="openImg((u) => (roomForm.feedsImg = u))">上传图片</el-button>
        <span class="ge-tip">建议尺寸800*800像素，大小不超过100K</span>
      </el-form-item>
      <el-form-item label="是否官方收录">
        <el-radio-group v-model="roomForm.isFeedsPublic">
          <el-radio :value="1">开启</el-radio>
          <el-radio :value="0">关闭</el-radio>
        </el-radio-group>
      </el-form-item>
      <el-form-item label="直播间类型">
        <el-radio-group v-model="roomForm.type">
          <el-radio :value="0">手机直播</el-radio>
          <el-radio :value="1">推流</el-radio>
        </el-radio-group>
      </el-form-item>
      <el-form-item label="屏幕方向">
        <el-radio-group v-model="roomForm.screenType">
          <el-radio :value="0">竖屏</el-radio>
          <el-radio :value="1">横屏</el-radio>
        </el-radio-group>
      </el-form-item>
      <el-form-item v-for="item in [
        { key: 'closeLike', label: '是否点赞' },
        { key: 'closeGoods', label: '是否显示货架' },
        { key: 'closeComment', label: '是否评论' },
        { key: 'closeReplay', label: '是否回放' },
        { key: 'closeShare', label: '是否分享' },
        { key: 'closeKf', label: '是否显示客服' },
      ]" :key="item.key" :label="item.label">
        <el-radio-group v-model="(roomForm as any)[item.key]">
          <el-radio :value="0">开启</el-radio>
          <el-radio :value="1">关闭</el-radio>
        </el-radio-group>
      </el-form-item>
      <el-form-item>
        <el-button type="danger" @click="submitRoom">提交</el-button>
      </el-form-item>
    </el-form>
  </div>

  <!-- ================= 商品库（平铺） ================= -->
  <div v-else-if="viewMode === 'goodsLibrary'" class="goods-edit">
    <div class="ge-head">
      <el-tabs v-model="goodsTab" class="ge-tabs" @tab-change="(t: any) => switchGoodsTab(Number(t))">
        <el-tab-pane label="审核中" :name="1" />
        <el-tab-pane label="审核通过" :name="2" />
        <el-tab-pane label="审核驳回" :name="3" />
      </el-tabs>
      <el-button class="ge-close" circle @click="backToList">
        <svg viewBox="0 0 1024 1024" width="16" height="16" fill="currentColor"><path d="M764.288 214.595 512 466.883 259.712 214.595a32 32 0 0 0-45.248 45.248L466.752 512 214.464 764.288a32 32 0 1 0 45.248 45.248L512 557.117l252.288 252.419a32 32 0 0 0 45.248-45.248L557.248 512l252.288-252.157a32 32 0 0 0-45.248-45.248z" /></svg>
      </el-button>
    </div>

    <el-card shadow="never" class="toolbar">
      <div class="toolbar-left">
        <el-button type="success" @click="openGoodsAdd">添加</el-button>
      </div>
    </el-card>

    <el-card shadow="never">
      <el-table v-loading="goodsLoading" :data="normGoods" border>
        <el-table-column prop="id" label="ID" width="80" align="left" />
        <el-table-column prop="name" label="商品名称" min-width="180" align="left" />
        <el-table-column label="商品图片" width="110" align="left">
          <template #default="{ row }">
            <el-image v-if="row.cover" :src="row.cover" fit="cover" class="goods-thumb" preview-teleported />
            <span v-else class="rg-empty">无图</span>
          </template>
        </el-table-column>
        <el-table-column label="价格" width="180" align="left">
          <template #default="{ row }">
            <template v-if="row.priceType === 2">{{ row.priceYuan }} ~ {{ row.price2Yuan }}元</template>
            <template v-else-if="row.priceType === 3">
              <span class="rg-origin">{{ row.priceYuan }}元</span>
              <span class="rg-now">{{ row.price2Yuan }}元</span>
            </template>
            <template v-else>{{ row.priceYuan }}元</template>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="150">
          <template #default="{ row }">
            <div class="row-ops">
              <el-button link type="primary" @click="openGoodsEdit(row)">修改价格</el-button>
              <el-button link type="danger" @click="delGoods(row)">删除</el-button>
            </div>
          </template>
        </el-table-column>
      </el-table>
      <el-pagination
        class="pagination"
        background
        layout="total, prev, pager, next"
        :total="goodsTotal"
        :current-page="goodsQuery.page"
        :page-size="goodsQuery.pagesize"
        @current-change="goodsPage"
        @size-change="goodsSize"
      />
    </el-card>
  </div>

  <!-- ================= 添加商品 / 修改价格（平铺） ================= -->
  <div v-else-if="viewMode === 'goodsEdit'" class="goods-edit">
    <div class="ge-head">
      <div class="ge-title">{{ goodsIsEdit ? '修改价格' : '添加商品' }}</div>
      <el-button class="ge-close" circle @click="backToLibrary">
        <svg viewBox="0 0 1024 1024" width="16" height="16" fill="currentColor"><path d="M764.288 214.595 512 466.883 259.712 214.595a32 32 0 0 0-45.248 45.248L466.752 512 214.464 764.288a32 32 0 1 0 45.248 45.248L512 557.117l252.288 252.419a32 32 0 0 0 45.248-45.248L557.248 512l252.288-252.157a32 32 0 0 0-45.248-45.248z" /></svg>
      </el-button>
    </div>

    <el-form ref="goodsFormRef" :model="goodsForm" label-width="100px" class="ge-form">
      <template v-if="!goodsIsEdit">
        <el-form-item label="商品名称" required>
          <el-input v-model="goodsForm.name" maxlength="17" style="width: 320px" placeholder="最短3个汉字，最长17个汉字" />
        </el-form-item>
        <el-form-item label="商品图片" required>
          <el-image v-if="goodsForm.coverImgUrl" :src="goodsForm.coverImgUrl" fit="cover" class="main-pic" @click="openImg((u) => (goodsForm.coverImgUrl = u))" />
          <el-button v-else size="small" @click="openImg((u) => (goodsForm.coverImgUrl = u))">上传图片</el-button>
          <span class="ge-tip">建议尺寸200*200像素，大小不超过80K</span>
        </el-form-item>
        <el-form-item label="商品链接" required>
          <LinkPicker v-model="goodsForm.url" pick-text="选择链接" placeholder="请选择商品链接" />
        </el-form-item>
      </template>
      <el-form-item v-else label="商品名称">
        <el-input v-model="goodsForm.name" disabled style="width: 320px" />
      </el-form-item>

      <el-form-item label="价格形式" required>
        <el-radio-group v-model="goodsForm.priceType">
          <el-radio :value="1">一口价</el-radio>
          <el-radio :value="2">价格区间</el-radio>
          <el-radio :value="3">显示折扣价</el-radio>
        </el-radio-group>
      </el-form-item>
      <el-form-item label="价格设置" required>
        <template v-if="goodsForm.priceType === 1">
          <el-input v-model="goodsForm.price" type="number" style="width: 140px" />
          <span class="ge-tip">元</span>
        </template>
        <template v-else-if="goodsForm.priceType === 2">
          <el-input v-model="goodsForm.price" type="number" style="width: 140px" />
          <span class="ge-tip" style="margin: 0 6px">元 ~</span>
          <el-input v-model="goodsForm.price2" type="number" style="width: 140px" />
          <span class="ge-tip">元</span>
        </template>
        <template v-else>
          <span class="ge-tip" style="margin-right: 6px">原价</span>
          <el-input v-model="goodsForm.price" type="number" style="width: 140px" />
          <span class="ge-tip" style="margin: 0 6px">元</span>
          <span class="ge-tip" style="margin-right: 6px">现价</span>
          <el-input v-model="goodsForm.price2" type="number" style="width: 140px" />
          <span class="ge-tip">元</span>
        </template>
      </el-form-item>
      <el-form-item>
        <el-button type="warning" @click="submitGoods">提交</el-button>
      </el-form-item>
    </el-form>
  </div>

  <!-- ================= 直播码小弹窗 ================= -->
  <el-dialog v-model="qrVisible" title="主播端小程序码" width="320px" align-center>
    <div v-loading="qrLoading" class="qr-box">
      <el-image v-if="qrUrl" :src="qrUrl" fit="contain" class="qr-img" />
      <div class="qr-tip">主播扫码进行直播</div>
    </div>
  </el-dialog>

  <!-- ================= 选择商品小弹窗 ================= -->
  <el-dialog v-model="pickVisible" title="选择商品" width="720px" align-center>
    <el-table
      v-loading="pickLoading"
      :data="pickRows"
      border
      max-height="420"
      @selection-change="(rows: NormGoods[]) => (pickIds = rows.map((r) => r.id))"
    >
      <el-table-column type="selection" width="44" align="center" />
      <el-table-column prop="id" label="ID" width="80" align="left" />
      <el-table-column prop="name" label="商品名称" min-width="180" align="left" />
      <el-table-column label="商品图片" width="100" align="left">
        <template #default="{ row }">
          <el-image v-if="row.cover" :src="row.cover" fit="cover" class="goods-thumb" preview-teleported />
        </template>
      </el-table-column>
      <el-table-column label="价格" width="160" align="left">
        <template #default="{ row }">
          <template v-if="row.priceType === 2">{{ row.priceYuan }} ~ {{ row.price2Yuan }}元</template>
          <template v-else-if="row.priceType === 3">
            <span class="rg-origin">{{ row.priceYuan }}元</span>
            <span class="rg-now">{{ row.price2Yuan }}元</span>
          </template>
          <template v-else>{{ row.priceYuan }}元</template>
        </template>
      </el-table-column>
    </el-table>
    <template #footer>
      <el-button @click="pickVisible = false">取消</el-button>
      <el-button type="primary" @click="confirmPick">确定</el-button>
    </template>
  </el-dialog>

  <ImgIconPicker v-model:visible="imgPickerVisible" default-tab="library" @pick="onPickImg" />
</template>

<style scoped>
.notice-box { margin-bottom: 3px; }
.notice-line { line-height: 1.8; font-size: 12px; }
.notice-line a { color: #409eff; }

.toolbar :deep(.el-card__body) { padding: 3px 6px; display: flex; align-items: center; gap: 8px; flex-wrap: nowrap; }
.toolbar-left { display: flex; align-items: center; gap: 8px; flex: 0 0 auto; }
.toolbar-left .el-button { margin: 0; }
:deep(th.el-table__cell) { background: #e6e6e6 !important; }
:deep(.cell) { text-align: left; }
.row-ops { display: flex; flex-direction: row; flex-wrap: wrap; gap: 2px 8px; line-height: 1.6; align-items: center; }
.row-ops .el-button { margin: 0; height: auto; padding: 0; font-size: 12px; }
.pagination { margin-top: 3px; justify-content: flex-end; }

.room-info { display: flex; align-items: flex-start; gap: 10px; }
.room-cover { width: 70px; height: 70px; border-radius: 4px; border: 1px solid #ebeef5; flex-shrink: 0; }
.room-cover-empty { display: flex; align-items: center; justify-content: center; color: #c0c4cc; font-size: 12px; background: #fafafa; }
.room-meta { min-width: 0; }
.room-name { font-size: 13px; color: #303133; }
.room-time { font-size: 12px; color: #909399; margin-top: 2px; }
.room-time-on { color: #21ba45; }
.room-anchor { font-size: 12px; color: #606266; margin-top: 2px; }

.room-goods { display: flex; align-items: center; gap: 8px; padding: 2px 0; }
.rg-img { width: 40px; height: 40px; border-radius: 4px; border: 1px solid #ebeef5; flex-shrink: 0; }
.rg-meta { min-width: 0; }
.rg-name { font-size: 12px; color: #303133; }
.rg-price { font-size: 12px; margin-top: 2px; }
.rg-origin { color: #909399; text-decoration: line-through; margin-right: 6px; }
.rg-now { color: #ff6600; font-weight: 600; }
.rg-empty { color: #c0c4cc; font-size: 12px; }
.goods-thumb { width: 50px; height: 50px; border-radius: 4px; border: 1px solid #ebeef5; }

.st-live { color: #f56c6c; }
.st-off { color: #f56c6c; }
.st-pending { color: #e6a23c; }
.st-end { color: #909399; }
.ck-pass { color: #21ba45; }

.goods-edit { background: #fff; border-radius: 6px; padding: 3px 6px; }
.ge-head { display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid #ebeef5; margin-bottom: 6px; min-height: 34px; }
.ge-title { font-size: 14px; font-weight: 600; color: #303133; padding-left: 6px; }
.ge-tabs { flex: 1; }
.ge-close { border: none; background: transparent; color: #909399; }
.ge-form :deep(.el-form-item) { margin-bottom: 10px; }
.ge-tip { color: #909399; font-size: 12px; margin-left: 8px; }
.ge-tip a { color: #409eff; }
.main-pic { width: 80px; height: 80px; border-radius: 4px; border: 1px solid #e4e7ed; cursor: pointer; }

.qr-box { display: flex; flex-direction: column; align-items: center; padding: 10px 0; }
.qr-img { width: 220px; height: 220px; }
.qr-tip { margin-top: 10px; color: #606266; font-size: 13px; }
</style>
