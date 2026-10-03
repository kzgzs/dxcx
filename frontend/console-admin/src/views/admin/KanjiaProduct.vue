<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import type { FormInstance } from 'element-plus'
import {
  deleteKanjiaProduct,
  getKanjiaProductDetail,
  getKanjiaProductList,
  getKanjiaJoinList,
  saveKanjiaProduct,
  setKanjiaProductStatus,
} from '../../api/kanjia'
import type { KanjiaProductKjRow, KanjiaProductRow } from '../../api/kanjia'
import { getGoodsList } from '../../api/goods'
import type { DesignerModule } from '../../api/designer'
import { defaultModule } from './designerModules'
import ImgIconPicker from '../../components/ImgIconPicker.vue'
import GoodsDetailEditor from '../../components/GoodsDetailEditor.vue'

// 商户视图预置：bid 由菜单工厂注入，缺省=平台自营视图
const props = withDefaults(defineProps<{ bid?: string }>(), { bid: '' })
const isMerchantView = computed(() => props.bid !== '' && props.bid !== 'all')

// ============ 列表 ============
const viewMode = ref<'list' | 'edit'>('list')
const loading = ref(false)
const tableData = ref<KanjiaProductRow[]>([])
const total = ref(0)
const selected = ref<KanjiaProductRow[]>([])

const query = reactive({
  page: 1,
  pagesize: 10,
  keyword: '',
  status: '' as number | '',
})

async function fetchList() {
  loading.value = true
  try {
    const data = await getKanjiaProductList({
      page: query.page,
      pagesize: query.pagesize,
      keyword: query.keyword || undefined,
      status: query.status === '' ? undefined : query.status,
      bid: props.bid || undefined,
    })
    tableData.value = data.list || []
    total.value = data.total || 0
  } catch {
    /* 错误已由拦截器弹出 */
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
function handleSizeChange(s: number) {
  query.pagesize = s
  query.page = 1
  fetchList()
}
function handleSelectionChange(rows: KanjiaProductRow[]) {
  selected.value = rows
}

// 活动状态文案与颜色（对齐截图：进行中绿 / 已结束灰 / 未上架红）
function activityClass(row: KanjiaProductRow) {
  if (row.status === 0) return 'st-off'
  const now = Math.floor(Date.now() / 1000)
  if (row.endtime > 0 && now > row.endtime) return 'st-end'
  return 'st-on'
}
function activityText(row: KanjiaProductRow) {
  if (row.status === 0) return '未上架'
  const now = Math.floor(Date.now() / 1000)
  if (row.endtime > 0 && now > row.endtime) return '已结束'
  return '进行中'
}
function fmtTs(ts: number) {
  if (!ts) return '-'
  const d = new Date(ts * 1000)
  const p = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}`
}

// ============ 批量操作 ============
function requireSelected() {
  if (selected.value.length === 0) {
    ElMessage.warning('请先勾选商品')
    return false
  }
  return true
}
async function batchDelete() {
  if (!requireSelected()) return
  const ids = selected.value.map((r) => r.id)
  try {
    await ElMessageBox.confirm(`确定删除选中的 ${ids.length} 个砍价商品吗？`, '删除确认', { type: 'warning' })
    await deleteKanjiaProduct(ids)
    ElMessage.success('删除成功')
    fetchList()
  } catch {
    /* 取消或错误已提示 */
  }
}
function batchStatus(status: number) {
  if (!requireSelected()) return
  const ids = selected.value.map((r) => r.id)
  setKanjiaProductStatus(ids, status)
    .then(() => {
      ElMessage.success(status === 1 ? '已上架' : '已下架')
      fetchList()
    })
    .catch(() => {})
}

// ============ 单行删除 ============
function handleDelete(row: KanjiaProductRow) {
  ElMessageBox.confirm(`确定删除砍价商品「${row.name}」吗？`, '删除确认', { type: 'warning' })
    .then(async () => {
      await deleteKanjiaProduct(row.id)
      ElMessage.success('删除成功')
      fetchList()
    })
    .catch(() => {})
}

// ============ 参与列表 ============
const joinVisible = ref(false)
const joinLoading = ref(false)
const joinList = ref<Record<string, any>[]>([])
const joinTitle = ref('')
async function openJoin(row: KanjiaProductRow) {
  joinVisible.value = true
  joinTitle.value = row.name
  joinLoading.value = true
  joinList.value = []
  try {
    const d = await getKanjiaJoinList({ page: 1, pagesize: 50, proid: row.id } as never)
    joinList.value = (d as unknown as { list: Record<string, any>[] }).list || []
  } catch {
    /* ignore */
  } finally {
    joinLoading.value = false
  }
}
function fmtLeft(sec: number) {
  sec = Number(sec) || 0
  const h = Math.floor(sec / 3600)
  const m = Math.floor((sec % 3600) / 60)
  return h > 0 ? `${h}小时${m}分` : `${m}分`
}

// ============ 编辑 ============
const isEdit = ref(false)
const formRef = ref<FormInstance>()
const submitting = ref(false)
const activeTab = ref('info')

function defaultDetailModules(): DesignerModule[] {
  return [defaultModule('richtext')]
}

const emptyForm = () => ({
  id: 0,
  name: '',
  pic: '',
  pics: '' as string,
  sell_price: 0 as number,
  min_price: 0 as number,
  helpgive_type: 0 as number,
  helpgive_percent: 0 as number,
  weight: 0 as number,
  sales: 0 as number,
  stock: 100 as number,
  directbuy: 0 as number,
  freighttype: 1 as number,
  starttime: '' as string,
  endtime: '' as string,
  sort: 0 as number,
  status: 1,
  detailModules: defaultDetailModules() as DesignerModule[],
})
const form = reactive(emptyForm())

// 砍价区间行：endnum 数字或字符串「最后一」
interface KjRow {
  startnum: number | string
  endnum: number | string
  startmoney: number
  endmoney: number
}
const kjRows = ref<KjRow[]>([])

function resetForm() {
  Object.assign(form, emptyForm())
  kjRows.value = [{ startnum: 1, endnum: '最后一', startmoney: 1, endmoney: 10 }]
}

function openAdd() {
  isEdit.value = false
  resetForm()
  activeTab.value = 'info'
  viewMode.value = 'edit'
}

async function openEdit(row: KanjiaProductRow) {
  isEdit.value = true
  resetForm()
  activeTab.value = 'info'
  viewMode.value = 'edit'
  try {
    const d = await getKanjiaProductDetail(row.id)
    Object.assign(form, {
      id: d.id,
      name: d.name,
      pic: d.pic,
      pics: d.pics || '',
      sell_price: Number(d.sell_price),
      min_price: Number(d.min_price),
      helpgive_type: Number((d as any).helpgive_type ?? 0),
      helpgive_percent: Number((d as any).helpgive_percent ?? 0),
      weight: Number((d as any).weight ?? 0),
      sales: Number((d as any).sales ?? 0),
      stock: d.stock,
      directbuy: d.directbuy,
      freighttype: Number((d as any).freighttype ?? 1),
      starttime: fmtTs(d.starttime),
      endtime: fmtTs(d.endtime),
      sort: d.sort,
      status: d.status,
    })
    // 详情装修器：detail 为 DesignerModule[] 的 JSON
    let modules: DesignerModule[] = []
    if (d.detail) {
      try {
        const parsed = JSON.parse(d.detail)
        if (Array.isArray(parsed)) modules = parsed
      } catch {
        modules = []
      }
    }
    form.detailModules = modules.length ? modules : defaultDetailModules()

    const kj = ((d as any).kjdata_list || []) as KanjiaProductKjRow[]
    kjRows.value = kj.length
      ? kj.map((k, i) => ({
          startnum: i === 0 ? 1 : (Number(k.startnum) || ''),
          endnum: i === kj.length - 1 ? '最后一' : (Number(k.endnum) || ''),
          startmoney: Number(k.startmoney) || 0,
          endmoney: Number(k.endmoney) || 0,
        }))
      : [{ startnum: 1, endnum: '最后一', startmoney: 1, endmoney: 10 }]
  } catch {
    viewMode.value = 'list'
  }
}

// 点「+」（首行）：把当前末行的「最后一」解锁为可编辑数字，末尾追加新行（新行 endnum=最后一）
function addKjRow() {
  const lastIdx = kjRows.value.length - 1
  // 旧末行不再是末行：endnum 变为可编辑数字（清空待填）
  kjRows.value[lastIdx].endnum = ''
  // 追加新末行
  kjRows.value.push({
    startnum: '',
    endnum: '最后一',
    startmoney: 1,
    endmoney: 10,
  })
}
// 点「-」（非首行）：删除该行，新末行 endnum 锁定为「最后一」
function removeKjRow(i: number) {
  if (kjRows.value.length <= 1) return
  kjRows.value.splice(i, 1)
  const last = kjRows.value[kjRows.value.length - 1]
  last.endnum = '最后一'
}

function backToList() {
  viewMode.value = 'list'
  fetchList()
}

async function handleSubmit() {
  if (formRef.value) {
    try { await formRef.value.validate() } catch { return }
  }
  submitting.value = true
  try {
    // 组装 kjdata：末行 endnum 已为「最后一」，首行 startnum 固定 1
    const rows = kjRows.value.map((r, i) => ({
      startnum: i === 0 ? 1 : Number(r.startnum) || 0,
      endnum: i === kjRows.value.length - 1 ? '最后一' : Number(r.endnum) || 0,
      startmoney: Number(r.startmoney) || 0,
      endmoney: Number(r.endmoney) || 0,
    }))
    await saveKanjiaProduct({
      id: form.id || undefined,
      name: form.name,
      pic: form.pic,
      pics: form.pics,
      sell_price: Number(form.sell_price),
      min_price: Number(form.min_price),
      stock: Number(form.stock),
      helpnum: 10,
      limitHour: 48,
      directbuy: form.directbuy ? 1 : 0,
      starttime: form.starttime || undefined,
      endtime: form.endtime || undefined,
      sort: Number(form.sort),
      status: form.status ? 1 : 0,
      weight: Number(form.weight),
      sales: Number(form.sales),
      helpgive_type: Number(form.helpgive_type),
      helpgive_percent: Number(form.helpgive_percent),
      freighttype: Number(form.freighttype),
      detail: JSON.stringify(form.detailModules || []),
      kjdata: rows,
    } as never)
    ElMessage.success('保存成功')
    backToList()
  } catch {
    /* 错误已提示 */
  } finally {
    submitting.value = false
  }
}

// ============ 选择商城商品 ============
const chooseVisible = ref(false)
const chooseLoading = ref(false)
const chooseKeyword = ref('')
const choosePage = ref(1)
const chooseTotal = ref(0)
const chooseList = ref<{ id: number; name: string; image: string; price: number }[]>([])
async function openChoose() {
  chooseVisible.value = true
  chooseKeyword.value = ''
  choosePage.value = 1
  await fetchChoose()
}
async function fetchChoose() {
  chooseLoading.value = true
  try {
    const d = await getGoodsList({ page: choosePage.value, pagesize: 10, keyword: chooseKeyword.value || undefined, status: 1 } as never)
    chooseList.value = (d as unknown as { list: any[] }).list.map((g) => ({
      id: Number(g.id),
      name: String(g.name),
      image: String(g.image || g.pic || ''),
      price: Number(g.price ?? g.sell_price ?? 0),
    }))
    chooseTotal.value = (d as unknown as { total: number }).total || 0
  } catch {
    /* ignore */
  } finally {
    chooseLoading.value = false
  }
}
function handleChooseSearch() {
  choosePage.value = 1
  fetchChoose()
}
function pickGoods(g: { id: number; name: string; image: string; price: number }) {
  form.name = g.name
  form.pic = g.image
  if (g.image) form.pics = g.image
  form.sell_price = g.price
  chooseVisible.value = false
}

// ============ 选图 ============
const imgPickerVisible = ref(false)
const multiPickerVisible = ref(false)
function onPick(urls: string[]) {
  if (urls[0]) form.pic = urls[0]
}
function onMultiPick(urls: string[]) {
  const cur = form.pics ? form.pics.split(',').filter(Boolean) : []
  urls.forEach((u) => {
    if (u && !cur.includes(u)) cur.push(u)
  })
  form.pics = cur.join(',')
}

onMounted(fetchList)
</script>

<template>
  <!-- ================= 列表 ================= -->
  <div v-if="viewMode === 'list'">
    <el-card shadow="never" class="toolbar">
      <div class="toolbar-left">
        <el-button v-if="!isMerchantView" type="success" @click="openAdd">添加</el-button>
        <el-button @click="batchDelete">删除</el-button>
        <el-button @click="batchStatus(1)">上架</el-button>
        <el-button @click="batchStatus(0)">下架</el-button>
      </div>
      <el-form inline class="toolbar-search" @submit.prevent>
        <el-form-item label="商品名称">
          <el-input v-model="query.keyword" placeholder="商品名称" clearable style="width: 140px" @keyup.enter="handleSearch" />
        </el-form-item>
        <el-form-item label="状态">
          <el-select v-model="query.status" placeholder="全部" clearable style="width: 96px">
            <el-option label="全部" :value="''" />
            <el-option label="已上架" :value="1" />
            <el-option label="未上架" :value="0" />
          </el-select>
        </el-form-item>
        <el-form-item>
          <el-button type="success" class="search-icon-btn" @click="handleSearch">
            <svg viewBox="0 0 1024 1024" width="16" height="16" fill="currentColor"><path d="M945.82578 870.125405l-179.064759-179.128198c37.633442-50.252111 60.226958-112.396119 60.226958-179.635713 0-165.019429-133.863717-298.820032-298.94691-298.820032S229.157273 346.342065 229.157273 511.361494s133.800278 298.883471 298.883471 298.883471c64.175523 0 123.67499-20.451714 172.925368-54.893622l180.206658 180.270097c8.639241 8.575802 19.883819 12.926817 31.191832 12.926817s22.552591-4.351015 31.128393-12.926817C962.978033 917.976954 962.978033 887.274231 945.82578 870.125405zM528.040744 710.308079c-109.760556 0-198.946585-89.122915-198.946585-198.946585s89.186354-198.946585 198.946585-198.946585 198.946585 89.122915 198.946585 198.946585S637.8013 710.308079 528.040744 710.308079z"/></svg>
          </el-button>
        </el-form-item>
      </el-form>
    </el-card>

    <el-card shadow="never">
      <el-table v-loading="loading" :data="tableData" border @selection-change="handleSelectionChange">
        <el-table-column type="selection" width="40" align="center" />
        <el-table-column prop="id" label="ID" width="60" align="left" />
        <el-table-column label="商品信息" min-width="260">
          <template #default="{ row }">
            <div class="goods-info">
              <el-image v-if="row.pic" :src="row.pic" :preview-src-list="[row.pic]" fit="cover" class="goods-info-img" preview-teleported />
              <div v-else class="goods-info-img goods-info-img-empty">无图</div>
              <div class="goods-info-meta">
                <div class="goods-info-name">{{ row.name }}</div>
                <div class="goods-info-sub">原价:￥{{ Number(row.sell_price).toFixed(2) }}</div>
                <div class="goods-info-price">最低价:￥{{ Number(row.min_price).toFixed(2) }}</div>
              </div>
            </div>
          </template>
        </el-table-column>
        <el-table-column prop="stock" label="库存" width="80" align="left" />
        <el-table-column prop="sales" label="已成功" width="80" align="left" />
        <el-table-column prop="saleing" label="砍价中" width="80" align="left" />
        <el-table-column label="活动时间" width="220" align="left">
          <template #default="{ row }">{{ fmtTs(row.starttime) }} ~ {{ fmtTs(row.endtime) }}</template>
        </el-table-column>
        <el-table-column label="状态" width="80" align="left">
          <template #default="{ row }">
            <span :class="activityClass(row)">{{ activityText(row) }}</span>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="200">
          <template #default="{ row }">
            <div class="row-ops">
              <el-button link type="primary" @click="openJoin(row)">参与列表</el-button>
              <el-button link type="primary" @click="openEdit(row)">编辑</el-button>
              <el-button link type="danger" @click="handleDelete(row)">删除</el-button>
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
        @current-change="handlePageChange"
        @size-change="handleSizeChange"
      />
    </el-card>

    <!-- 参与列表弹窗 -->
    <el-dialog v-model="joinVisible" :title="`参与列表 - ${joinTitle}`" width="720px">
      <el-table v-loading="joinLoading" :data="joinList" size="small" border max-height="420">
        <el-table-column label="会员" min-width="160">
          <template #default="{ row }">{{ row.nickname || ('会员' + row.mid) }}</template>
        </el-table-column>
        <el-table-column prop="cut_percent" label="已砍比例" width="100">
          <template #default="{ row }">{{ Math.round(Number(row.cut_percent || 0)) }}%</template>
        </el-table-column>
        <el-table-column label="目标人数" width="90">
          <template #default="{ row }">{{ row.target_helpnum }}</template>
        </el-table-column>
        <el-table-column prop="status_text" label="状态" width="90" />
        <el-table-column label="剩余时间" width="110">
          <template #default="{ row }">{{ fmtLeft(row.leftsecond) }}</template>
        </el-table-column>
      </el-table>
    </el-dialog>
  </div>

  <!-- ================= 整页编辑 ================= -->
  <div v-else class="goods-edit">
    <div class="ge-head">
      <span class="ge-head-title">{{ isEdit ? '编辑商品' : '添加商品' }}</span>
      <el-button class="ge-close" circle @click="backToList">
        <svg viewBox="0 0 1024 1024" width="16" height="16" fill="currentColor"><path d="M764.288 214.595 512 466.883 259.712 214.595a32 32 0 0 0-45.248 45.248L466.752 512 214.464 764.288a32 32 0 1 0 45.248 45.248L512 557.117l252.288 252.419a32 32 0 0 0 45.248-45.248L557.248 512l252.288-252.157a32 32 0 0 0-45.248-45.248z"/></svg>
      </el-button>
    </div>

    <el-form ref="formRef" :model="form" label-position="right" label-width="110px" class="ge-form">
      <el-tabs v-model="activeTab" class="ge-tabs">
        <!-- 基本信息 -->
        <el-tab-pane label="商品基本信息" name="info">
          <el-form-item label="商品名称" required>
            <el-input v-model="form.name" maxlength="120" style="width: 300px" />
            <el-button size="small" style="margin-left: 10px" @click="openChoose">选择商城商品</el-button>
          </el-form-item>
          <el-form-item label="商品主图">
            <el-image v-if="form.pic" :src="form.pic" fit="cover" class="main-pic" @click="imgPickerVisible = true" />
            <el-button v-else size="small" @click="imgPickerVisible = true">上传图片</el-button>
            <span class="ge-hint">建议尺寸：640×640像素</span>
          </el-form-item>
          <el-form-item label="商品图片">
            <div class="pic-line">
              <div v-for="u in (form.pics ? form.pics.split(',').filter(Boolean) : [])" :key="u" class="pic-item">
                <el-image :src="u" fit="cover" class="pic-thumb" />
                <span class="pic-del" @click="form.pics = (form.pics.split(',').filter((x: string) => x !== u) as string[]).join(',')">×</span>
              </div>
              <el-button size="small" @click="multiPickerVisible = true">批量上传</el-button>
            </div>
            <span class="ge-hint">建议尺寸：640×640像素</span>
          </el-form-item>
          <el-form-item label="原价：">
            <el-input v-model="form.sell_price" :type="Number" :controls="false" style="width: 160px" />
            <span class="ge-hint">最初价格</span>
          </el-form-item>
          <el-form-item label="底价：">
            <el-input v-model="form.min_price" :type="Number" :controls="false" style="width: 160px" />
            <span class="ge-hint">最低价格，砍到该价格就不能再砍了</span>
          </el-form-item>
          <el-form-item label="帮砍得：">
            <el-radio-group v-model="form.helpgive_type">
              <el-radio :value="0">积分</el-radio>
              <el-radio :value="1">余额</el-radio>
            </el-radio-group>
            <span class="ge-unit">比例(%)</span>
            <el-input v-model="form.helpgive_percent" :type="Number" :controls="false" style="width: 90px" />
            <span class="ge-hint">帮砍人砍掉多少钱就得百分之多少的积分或余额</span>
          </el-form-item>
          <el-form-item label="重量：">
            <el-input v-model="form.weight" :type="Number" :controls="false" style="width: 160px" />
            <span class="ge-unit">克</span>
          </el-form-item>
          <el-form-item label="库存：">
            <el-input v-model="form.stock" :type="Number" :controls="false" style="width: 160px" />
            <span class="ge-hint">库存为零时，本商品所有正在进行的砍价将变成砍价失败状态</span>
          </el-form-item>
          <el-form-item label="开始时间：">
            <el-date-picker v-model="form.starttime" type="datetime" value-format="YYYY-MM-DD HH:mm:ss" placeholder="选择开始时间" />
          </el-form-item>
          <el-form-item label="结束时间：">
            <el-date-picker v-model="form.endtime" type="datetime" value-format="YYYY-MM-DD HH:mm:ss" placeholder="选择结束时间" />
          </el-form-item>
          <el-form-item label="直接购买：">
            <el-radio-group v-model="form.directbuy">
              <el-radio :value="0">关闭</el-radio>
              <el-radio :value="1">开启</el-radio>
            </el-radio-group>
            <span class="ge-hint">砍价过程中，未砍到底价是否可以直接购买</span>
          </el-form-item>
          <el-form-item label="配送模板：">
            <el-radio-group v-model="form.freighttype">
              <el-radio :value="1">全部模板</el-radio>
              <el-radio :value="2">选择模板</el-radio>
              <el-radio :value="3">自动发货</el-radio>
              <el-radio :value="4">在线卡密</el-radio>
            </el-radio-group>
          </el-form-item>
          <el-form-item label="销量：">
            <el-input v-model="form.sales" :type="Number" :controls="false" style="width: 160px" />
          </el-form-item>
          <el-form-item label="序号：">
            <el-input v-model="form.sort" :type="Number" :controls="false" style="width: 160px" />
            <span class="ge-hint">用于排序，越大越靠前</span>
          </el-form-item>
          <el-form-item label=" ">
            <el-button type="primary" @click="activeTab = 'kj'">下一步 编辑砍价区间</el-button>
            <el-button type="warning" :loading="submitting" @click="handleSubmit">提交</el-button>
          </el-form-item>
        </el-tab-pane>

        <!-- 砍价区间设置 -->
        <el-tab-pane label="砍价区间设置" name="kj">
          <div class="kj-line" v-for="(row, i) in kjRows" :key="i">
            <span class="kj-label">{{ i === 0 ? '砍价区间设置：' : '' }}</span>
            <span>第</span>
            <el-input v-if="i === 0" :model-value="1" disabled size="small" style="width: 70px" />
            <el-input v-else v-model="row.startnum" :type="Number" :controls="false" size="small" style="width: 70px" />
            <span>刀到第</span>
            <template v-if="i === kjRows.length - 1">
              <span class="kj-last">最后一</span>
            </template>
            <el-input v-else v-model="row.endnum" :type="Number" :controls="false" size="small" style="width: 70px" />
            <span>刀，每刀砍掉</span>
            <el-input v-model="row.startmoney" :type="Number" :controls="false" size="small" style="width: 70px" />
            <span>到</span>
            <el-input v-model="row.endmoney" :type="Number" :controls="false" size="small" style="width: 70px" />
            <span>元</span>
            <el-button v-if="i === 0" size="small" circle @click="addKjRow">+</el-button>
            <el-button v-else size="small" circle @click="removeKjRow(i)">-</el-button>
          </div>
          <div class="kj-actions">
            <el-button type="primary" @click="activeTab = 'info'">上一步 编辑基本信息</el-button>
            <el-button type="primary" @click="activeTab = 'detail'">下一步 编辑商品详情</el-button>
            <el-button type="warning" :loading="submitting" @click="handleSubmit">提交</el-button>
          </div>
        </el-tab-pane>

        <!-- 商品详情 -->
        <el-tab-pane label="商品详情" name="detail">
          <GoodsDetailEditor v-model="form.detailModules" title="商品详情" />
          <div class="kj-actions">
            <el-button type="primary" @click="activeTab = 'info'">上一步 编辑基本信息</el-button>
            <el-button type="warning" :loading="submitting" @click="handleSubmit">提交</el-button>
          </div>
        </el-tab-pane>
      </el-tabs>
    </el-form>

    <ImgIconPicker v-model:visible="imgPickerVisible" default-tab="library" @pick="onPick" />
    <ImgIconPicker v-model:visible="multiPickerVisible" default-tab="library" multi @pick="onMultiPick" />

    <!-- 选择商城商品弹窗 -->
    <el-dialog v-model="chooseVisible" title="选择商城商品" width="760px">
      <div class="choose-bar">
        <el-input v-model="chooseKeyword" placeholder="输入商品名称搜索" clearable style="width: 240px" @keyup.enter="handleChooseSearch" />
        <el-button type="primary" @click="handleChooseSearch">搜索</el-button>
      </div>
      <el-table v-loading="chooseLoading" :data="chooseList" size="small" border style="margin-top: 10px">
        <el-table-column label="商品" min-width="260">
          <template #default="{ row }">
            <div class="choose-goods">
              <el-image v-if="row.image" :src="row.image" fit="cover" class="choose-img" />
              <span>{{ row.name }}</span>
            </div>
          </template>
        </el-table-column>
        <el-table-column prop="price" label="价格" width="100" />
        <el-table-column label="操作" width="90">
          <template #default="{ row }">
            <el-button link type="primary" @click="pickGoods(row)">选择</el-button>
          </template>
        </el-table-column>
      </el-table>
      <el-pagination
        layout="prev, pager, next"
        :total="chooseTotal"
        :current-page="choosePage"
        :page-size="10"
        style="margin-top: 10px; justify-content: flex-end"
        @current-change="(p: number) => { choosePage = p; fetchChoose() }"
      />
    </el-dialog>
  </div>
</template>

<style scoped>
.toolbar { margin-bottom: 3px; }
.toolbar :deep(.el-card__body) { padding: 3px 6px; display: flex; align-items: center; justify-content: space-between; gap: 8px; flex-wrap: nowrap; }
.toolbar-left { display: flex; align-items: center; gap: 8px; flex: 0 0 auto; }
.toolbar-left .el-button { margin: 0; }
.toolbar-search { display: flex; align-items: center; flex: 1 1 auto; justify-content: flex-end; flex-wrap: nowrap; min-width: 0; margin: 0; }
.toolbar-search :deep(.el-form-item) { margin-bottom: 0; margin-right: 8px; }
.toolbar-search :deep(.el-form-item:last-child) { margin-right: 0; }
.toolbar-search :deep(.el-form-item__label) { padding-right: 6px; }
.search-icon-btn { padding: 8px 14px; }
.search-icon-btn svg { display: block; }

:deep(.el-table) { background: #fff; }
:deep(th.el-table__cell) { background: #e6e6e6 !important; }
:deep(.cell) { text-align: left; }

.goods-info { display: flex; align-items: center; gap: 10px; }
.goods-info-img { width: 50px; height: 50px; border-radius: 4px; border: 1px solid #ebeef5; flex-shrink: 0; }
.goods-info-img-empty { display: flex; align-items: center; justify-content: center; color: #c0c4cc; font-size: 12px; background: #fafafa; }
.goods-info-meta { min-width: 0; flex: 1; }
.goods-info-name { color: #303133; font-size: 13px; line-height: 1.5; word-break: break-all; }
.goods-info-sub { color: #999; font-size: 12px; margin-top: 2px; }
.goods-info-price { color: #ff6600; font-weight: 600; font-size: 13px; margin-top: 2px; }

.st-on { color: #5FB878; }
.st-off { color: #f55; }
.st-end { color: #ccc; }

.row-ops { display: flex; flex-direction: row; flex-wrap: nowrap; gap: 4px 10px; line-height: 1.6; align-items: center; }
.row-ops .el-button { margin: 0; height: auto; min-height: 0; padding: 0; font-size: 12px; }
.row-ops :deep(.el-button) { height: auto; padding: 1px 0; margin: 0; font-size: 12px; }
.pagination { margin-top: 12px; justify-content: flex-end; }

.goods-edit { background: #fff; border-radius: 4px; padding: 16px 20px; margin: 3px; }
.ge-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; }
.ge-head-title { font-size: 15px; font-weight: 600; color: #303133; }
.ge-close { border: none; background: transparent; color: #909399; }
.ge-close:hover { color: #409eff; }
.ge-form :deep(.el-form-item) { margin-bottom: 14px; }
.ge-hint { color: #909399; font-size: 12px; margin-left: 10px; }
.ge-unit { color: #606266; font-size: 12px; margin: 0 6px 0 10px; }

.main-pic { width: 80px; height: 80px; border-radius: 4px; border: 1px solid #e4e7ed; cursor: pointer; }
.pic-line { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; }
.pic-item { position: relative; display: flex; flex-direction: column; align-items: center; }
.pic-thumb { width: 64px; height: 64px; border-radius: 4px; border: 1px solid #e4e7ed; object-fit: cover; }
.pic-del { position: absolute; top: -6px; right: -6px; width: 18px; height: 18px; line-height: 16px; text-align: center; background: #f56c6c; color: #fff; border-radius: 50%; cursor: pointer; font-size: 12px; }

.kj-line { display: flex; align-items: center; flex-wrap: wrap; gap: 6px; margin-bottom: 14px; font-size: 13px; color: #606266; }
.kj-label { width: 90px; }
.kj-last { display: inline-block; padding: 0 8px; color: #909399; }
.kj-actions { margin-top: 18px; display: flex; gap: 12px; }

.choose-bar { display: flex; gap: 10px; align-items: center; }
.choose-goods { display: flex; align-items: center; gap: 8px; }
.choose-img { width: 40px; height: 40px; border-radius: 4px; }
</style>
