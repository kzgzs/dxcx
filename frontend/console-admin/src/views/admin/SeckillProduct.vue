<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import type { FormInstance } from 'element-plus'
import {
  checkSeckillProduct,
  copySeckillProduct,
  deleteSeckillProduct,
  getSeckillProductDetail,
  getSeckillProductList,
  getSeckillSet,
  saveSeckillProduct,
  setSeckillProductStatus,
} from '../../api/seckill'
import type { SeckillProductItem, SeckillGuigeEdit } from '../../api/seckill'
import { getGoodsList } from '../../api/goods'
import ImgIconPicker from '../../components/ImgIconPicker.vue'

const props = withDefaults(defineProps<{ bid?: string }>(), { bid: '' })
const isMerchantView = computed(() => props.bid !== '' && props.bid !== 'all')

// ============ 列表 ============
const viewMode = ref<'list' | 'edit'>('list')
const loading = ref(false)
const tableData = ref<SeckillProductItem[]>([])
const total = ref(0)
const selected = ref<SeckillProductItem[]>([])

const query = reactive({
  page: 1,
  pagesize: 10,
  keyword: '',
  status: '' as number | '',
  seckill_date: '',
  seckill_time: '' as number | '',
  orderField: '',
  orderType: '',
})

// 场次设置（timeset 小时点 + duration 时长）
const timeset = ref<number[]>([])
const duration = ref(12)

async function fetchSysset() {
  try {
    const s = await getSeckillSet()
    timeset.value = s.timeset || []
    duration.value = Number(s.duration) || 12
  } catch {
    /* ignore */
  }
}

async function fetchList() {
  loading.value = true
  try {
    const data = await getSeckillProductList({
      page: query.page,
      pagesize: query.pagesize,
      keyword: query.keyword || undefined,
      status: query.status === '' ? undefined : query.status,
      seckill_date: query.seckill_date || undefined,
      seckill_time: query.seckill_time === '' ? undefined : query.seckill_time,
      bid: props.bid || undefined,
      orderField: query.orderField || undefined,
      orderType: query.orderType || undefined,
    })
    tableData.value = data.list || []
    total.value = data.total || 0
  } catch {
    /* 错误已提示 */
  } finally {
    loading.value = false
  }
}

function handleSearch() {
  query.page = 1
  fetchList()
}
function handlePageChange(p: number) { query.page = p; fetchList() }
function handleSizeChange(s: number) { query.pagesize = s; query.page = 1; fetchList() }
function handleSortChange({ prop, order }: { prop: string; order: string | null }) {
  query.orderField = order ? prop : ''
  query.orderType = order === 'ascending' ? 'asc' : 'desc'
  fetchList()
}
function handleSelectionChange(rows: SeckillProductItem[]) { selected.value = rows }

function pad2(n: number) { return String(n).padStart(2, '0') }
function sessionText(row: SeckillProductItem) {
  if (!row.seckill_date) return '-'
  return `${row.seckill_date} ${pad2(row.seckill_time)}:00`
}
function statusClass(row: SeckillProductRowLike) {
  if (row.status === 0) return 'st-off'
  const now = Math.floor(Date.now() / 1000)
  if (row.starttime > 0 && now < row.starttime) return 'st-pending'
  if (row.starttime > 0 && now > row.starttime + duration.value * 3600) return 'st-end'
  return 'st-live'
}
function statusText(row: SeckillProductRowLike) {
  if (row.status === 0) return '未上架'
  const now = Math.floor(Date.now() / 1000)
  if (row.starttime > 0 && now < row.starttime) return '未开始'
  if (row.starttime > 0 && now > row.starttime + duration.value * 3600) return '已结束'
  return '抢购中'
}
type SeckillProductRowLike = { status: number; starttime: number }
function fmtTs(ts: number) {
  if (!ts) return '-'
  const d = new Date(ts * 1000)
  return `${d.getFullYear()}-${pad2(d.getMonth() + 1)}-${pad2(d.getDate())} ${pad2(d.getHours())}:${pad2(d.getMinutes())}`
}

// ============ 批量 ============
function requireSelected() {
  if (selected.value.length === 0) { ElMessage.warning('请先勾选商品'); return false }
  return true
}
function batchDelete() {
  if (!requireSelected()) return
  const ids = selected.value.map((r) => r.id)
  ElMessageBox.confirm(`确定删除选中的 ${ids.length} 个秒杀商品吗？`, '删除确认', { type: 'warning' })
    .then(async () => { await deleteSeckillProduct(ids); ElMessage.success('删除成功'); fetchList() })
    .catch(() => {})
}
function batchStatus(status: number) {
  if (!requireSelected()) return
  setSeckillProductStatus(selected.value.map((r) => r.id), status)
    .then(() => { ElMessage.success(status === 1 ? '已上架' : '已下架'); fetchList() })
    .catch(() => {})
}

// ============ 审核 ============
function handleCheck(row: SeckillProductItem, pass: boolean) {
  if (pass) {
    ElMessageBox.confirm(`确定通过秒杀商品「${row.name}」的审核吗？`, '审核确认', { type: 'success' })
      .then(async () => { await checkSeckillProduct(row.id, 1); ElMessage.success('审核通过'); fetchList() })
      .catch(() => {})
    return
  }
  ElMessageBox.prompt('请输入驳回原因', '驳回审核', { inputType: 'textarea', inputPlaceholder: '请填写驳回原因', type: 'warning' })
    .then(async ({ value }) => { await checkSeckillProduct(row.id, 2, (value || '').trim()); ElMessage.success('已驳回'); fetchList() })
    .catch(() => {})
}

// ============ 单行操作 ============
function handleDelete(row: SeckillProductItem) {
  ElMessageBox.confirm(`确定删除秒杀商品「${row.name}」吗？`, '删除确认', { type: 'warning' })
    .then(async () => { await deleteSeckillProduct(row.id); ElMessage.success('删除成功'); fetchList() })
    .catch(() => {})
}
function handleRowStatus(row: SeckillProductItem) {
  setSeckillProductStatus(row.id, row.status === 1 ? 0 : 1)
    .then(() => { ElMessage.success(row.status === 1 ? '已下架' : '已上架'); fetchList() })
    .catch(() => {})
}
function handleCopy(row: SeckillProductItem) {
  copySeckillProduct(row.id)
    .then(async (res) => {
      ElMessage.success('复制成功')
      try {
        await ElMessageBox.confirm('是否立即编辑新复制的商品？', '复制成功', { confirmButtonText: '去编辑', cancelButtonText: '关闭', type: 'success' })
        const d = await getSeckillProductDetail(res.id)
        openEdit(d as SeckillProductItem)
      } catch { /* 留在列表 */ }
      fetchList()
    })
    .catch(() => {})
}

// ============ 编辑 ============
const isEdit = ref(false)
const formRef = ref<FormInstance>()
const submitting = ref(false)
const activeTab = ref('info')

const emptyForm = () => ({
  id: 0,
  goods_id: 0,
  name: '',
  pic: '',
  pics: '' as string,
  market_price: 0 as number,
  sell_price: 0 as number,
  stock: 100 as number,
  seckill_date: '',
  seckill_time: '' as number | '',
  buymax: 0,
  perlimit: 0,
  sort: 0,
  status: 1,
  sellpoint: '',
  detail: '',
})
const form = reactive(emptyForm())
const guige = ref<SeckillGuigeEdit[]>([])

function resetForm() {
  Object.assign(form, emptyForm())
  guige.value = [{ id: undefined, name: '默认', sell_price: 0, stock: 100, sales: 0 }]
}

function openAdd() {
  isEdit.value = false
  resetForm()
  activeTab.value = 'info'
  viewMode.value = 'edit'
}

function openEdit(row: SeckillProductItem) {
  isEdit.value = true
  resetForm()
  activeTab.value = 'info'
  viewMode.value = 'edit'
  getSeckillProductDetail(row.id).then((d) => {
    Object.assign(form, {
      id: d.id,
      goods_id: 0,
      name: d.name,
      pic: d.pic,
      pics: d.pics || '',
      market_price: Number(d.market_price),
      sell_price: Number(d.sell_price),
      stock: d.stock,
      seckill_date: d.seckill_date || '',
      seckill_time: d.seckill_time ?? '',
      buymax: d.buymax,
      perlimit: d.perlimit,
      sort: d.sort,
      status: d.status,
      sellpoint: d.sellpoint || '',
      detail: d.detail || '',
    })
    guige.value = (d.guige && d.guige.length ? d.guige : [{ id: undefined, name: '默认', sell_price: 0, stock: 100, sales: 0 }])
      .map((g) => ({ id: g.id, name: g.name, sell_price: Number(g.sell_price), stock: Number(g.stock), sales: Number(g.sales) }))
  }).catch(() => { viewMode.value = 'list' })
}

function addGuige() { guige.value.push({ id: undefined, name: '', sell_price: 0, stock: 100, sales: 0 }) }
function removeGuige(i: number) { guige.value.splice(i, 1) }

function backToList() { viewMode.value = 'list'; fetchList() }

async function handleSubmit() {
  if (!formRef.value) return
  await formRef.value.validate(async (valid) => {
    if (!valid) return
    if (!form.pic) { ElMessage.warning('请上传商品主图'); return }
    submitting.value = true
    try {
      await saveSeckillProduct({
        id: form.id || undefined,
        goods_id: form.goods_id || undefined,
        name: form.name,
        pic: form.pic,
        pics: form.pics,
        market_price: Number(form.market_price),
        sell_price: Number(form.sell_price),
        stock: Number(form.stock),
        seckill_date: form.seckill_date || undefined,
        seckill_time: form.seckill_time === '' ? undefined : Number(form.seckill_time),
        buymax: Number(form.buymax),
        perlimit: Number(form.perlimit),
        sort: Number(form.sort),
        status: form.status ? 1 : 0,
        sellpoint: form.sellpoint,
        detail: form.detail,
        guige: guige.value.map((g) => ({
          id: g.id || undefined,
          name: g.name,
          sell_price: Number(g.sell_price),
          stock: Number(g.stock),
        })),
      })
      ElMessage.success('保存成功')
      backToList()
    } catch {
      /* 错误已提示 */
    } finally {
      submitting.value = false
    }
  })
}

// ============ 选图 ============
const imgPickerVisible = ref(false)
let imgSetter: ((u: string) => void) | null = null
function openImg(setter: (u: string) => void) { imgSetter = setter; imgPickerVisible.value = true }
function onPick(urls: string[]) { if (urls[0] && imgSetter) imgSetter(urls[0]); imgSetter = null }
const multiPickerVisible = ref(false)
function onMultiPick(urls: string[]) {
  const cur = form.pics ? form.pics.split(',').filter(Boolean) : []
  urls.forEach((u) => { if (u && !cur.includes(u)) cur.push(u) })
  form.pics = cur.join(',')
}

// ============ 选择商城商品弹窗 ============
const chooseVisible = ref(false)
const chooseLoading = ref(false)
const chooseList = ref<{ id: number; name: string; image: string; price: number; market_price: number }[]>([])
const chooseKeyword = ref('')
const chooseTotal = ref(0)
const choosePage = ref(1)

async function openChoose() {
  chooseVisible.value = true
  chooseKeyword.value = ''
  choosePage.value = 1
  await fetchChoose()
}
async function fetchChoose() {
  chooseLoading.value = true
  try {
    const d = await getGoodsList({ page: choosePage.value, pagesize: 10, keyword: chooseKeyword.value || undefined, status: 1 })
    chooseList.value = (d.list || []).map((g: any) => ({ id: g.id, name: g.name, image: g.image, price: g.price, market_price: g.market_price }))
    chooseTotal.value = d.total || 0
  } catch { /* ignore */ } finally { chooseLoading.value = false }
}
function handleChooseSearch() {
  choosePage.value = 1
  fetchChoose()
}
function pickGoods(g: { id: number; name: string; image: string; price: number; market_price: number }) {
  form.goods_id = g.id
  form.name = g.name
  form.pic = g.image
  form.market_price = Number(g.market_price || g.price)
  form.sell_price = Number(g.price) || form.sell_price
  chooseVisible.value = false
}

onMounted(() => { fetchSysset(); fetchList() })
</script>

<template>
  <!-- ================= 列表 ================= -->
  <div v-if="viewMode === 'list'">
    <el-card shadow="never" class="option-bar">
      <el-button v-if="!isMerchantView" type="success" @click="openAdd">添加</el-button>
      <el-button @click="batchDelete">删除</el-button>
      <el-button @click="batchStatus(1)">上架</el-button>
      <el-button @click="batchStatus(0)">下架</el-button>
    </el-card>

    <el-card shadow="never" class="search-bar">
      <el-form inline @submit.prevent>
        <el-form-item label="商品名称">
          <el-input v-model="query.keyword" placeholder="商品名称" clearable style="width: 160px" @keyup.enter="handleSearch" />
        </el-form-item>
        <el-form-item label="状态">
          <el-select v-model="query.status" placeholder="全部" clearable style="width: 110px">
            <el-option label="已上架" :value="1" />
            <el-option label="未上架" :value="0" />
          </el-select>
        </el-form-item>
        <el-form-item label="秒杀日期">
          <el-date-picker v-model="query.seckill_date" type="date" value-format="YYYY-MM-DD" placeholder="选择日期" style="width: 150px" />
        </el-form-item>
        <el-form-item label="秒杀时间">
          <el-select v-model="query.seckill_time" placeholder="请选择" clearable style="width: 120px">
            <el-option v-for="h in timeset" :key="h" :label="`${pad2(h)}:00`" :value="h" />
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
      <el-table v-loading="loading" :data="tableData" border @selection-change="handleSelectionChange" @sort-change="handleSortChange">
        <el-table-column type="selection" width="45" />
        <el-table-column prop="id" label="ID" width="80" sortable="custom" />
        <el-table-column v-if="isMerchantView" prop="bname" label="所属商户" width="120">
          <template #default="{ row }">{{ row.bname }}</template>
        </el-table-column>
        <el-table-column label="商品信息" min-width="300">
          <template #default="{ row }">
            <div class="goods-info">
              <el-image v-if="row.pic" :src="row.pic" :preview-src-list="[row.pic]" fit="cover" class="goods-info-img" preview-teleported />
              <div v-else class="goods-info-img goods-info-img-empty">无图</div>
              <div class="goods-info-meta">
                <div class="goods-info-name">{{ row.name }}</div>
                <div class="goods-info-sub">原价￥{{ Number(row.market_price).toFixed(2) }}</div>
                <div class="goods-info-price">秒杀价￥{{ Number(row.sell_price).toFixed(2) }}</div>
              </div>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="场次" width="150" prop="seckill_time" sortable="custom">
          <template #default="{ row }">{{ sessionText(row) }}</template>
        </el-table-column>
        <el-table-column prop="stock" label="库存" width="80" sortable="custom" />
        <el-table-column label="状态" width="100">
          <template #default="{ row }"><span :class="statusClass(row)">{{ statusText(row) }}</span></template>
        </el-table-column>
        <el-table-column label="秒杀进度" width="200">
          <template #default="{ row }">
            <el-progress :percentage="row.salepercent" :stroke-width="10" />
            <div class="prog-num">{{ row.sales }}/{{ row.salestotal }}</div>
          </template>
        </el-table-column>
        <el-table-column prop="perlimit" label="限购" width="80" sortable="custom" />
        <el-table-column prop="sort" label="序号" width="70" sortable="custom" />
        <el-table-column prop="createtime" label="创建时间" width="150" sortable="custom">
          <template #default="{ row }">{{ fmtTs(row.createtime) }}</template>
        </el-table-column>
        <el-table-column label="上架状态" width="90">
          <template #default="{ row }">
            <span :class="row.status === 1 ? 'ck-pass' : 'st-off'">{{ row.status === 1 ? '已上架' : '未上架' }}</span>
          </template>
        </el-table-column>
        <el-table-column v-if="isMerchantView" label="审核状态" width="100">
          <template #default="{ row }">
            <el-tooltip :content="row.check_reason" :disabled="!row.check_reason" placement="top">
              <span :class="row.ischecked === 1 ? 'ck-pass' : row.ischecked === 2 ? 'ck-reject' : 'ck-pending'">
                {{ row.ischecked === 1 ? '已通过' : row.ischecked === 2 ? '已驳回' : '待审核' }}
              </span>
            </el-tooltip>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="200" fixed="right">
          <template #default="{ row }">
            <div class="row-ops">
              <el-button link type="primary" @click="handleCopy(row)">复制</el-button>
              <el-button link type="primary" @click="openEdit(row)">编辑</el-button>
              <el-button link type="danger" @click="handleDelete(row)">删除</el-button>
              <el-button link :type="row.status === 1 ? 'warning' : 'success'" @click="handleRowStatus(row)">
                {{ row.status === 1 ? '下架' : '上架' }}
              </el-button>
              <template v-if="isMerchantView">
                <el-button v-if="row.ischecked === 0 || row.ischecked === 2" link type="success" @click="handleCheck(row, true)">通过</el-button>
                <el-button v-if="row.ischecked === 0 || row.ischecked === 1" link type="warning" @click="handleCheck(row, false)">驳回</el-button>
              </template>
            </div>
          </template>
        </el-table-column>
      </el-table>
      <el-pagination
        class="pagination" background layout="total, sizes, prev, pager, next, jumper"
        :total="total" :current-page="query.page" :page-size="query.pagesize" :page-sizes="[10, 20, 50, 100]"
        @current-change="handlePageChange" @size-change="handleSizeChange"
      />
    </el-card>
  </div>

  <!-- ================= 整页编辑 ================= -->
  <div v-else class="goods-edit">
    <div class="ge-head">
      <span class="ge-head-title">{{ isEdit ? '编辑商品' : '添加商品' }}</span>
      <el-button class="ge-close" circle @click="backToList">
        <svg viewBox="0 0 1024 1024" width="16" height="16" fill="currentColor"><path d="M764.288 214.595 512 466.883 259.712 214.595a32 32 0 0 0-45.248 45.248L466.752 512 214.464 764.288a32 32 0 1 0 45.248 45.248L512 557.117l252.288 252.419a32 32 0 0 0 45.248-45.248L557.248 512l252.288-252.157a32 32 0 0 0-45.248-45.248z"/></svg>
      </el-button>
    </div>

    <el-form ref="formRef" :model="form" label-position="right" label-width="116px" class="ge-form">
      <el-tabs v-model="activeTab" class="ge-tabs">
        <!-- 商品信息 -->
        <el-tab-pane label="商品信息" name="info">
          <el-form-item label="选择商城商品">
            <el-button @click="openChoose">从商城选择</el-button>
            <span v-if="form.goods_id" class="ge-hint">已选商城商品 #{{ form.goods_id }}</span>
          </el-form-item>
          <el-form-item label="商品名称" required>
            <el-input v-model="form.name" maxlength="120" style="width: 400px" placeholder="请输入商品名称" />
          </el-form-item>
          <el-form-item label="商品主图" required>
            <el-image v-if="form.pic" :src="form.pic" fit="cover" class="main-pic" @click="openImg((u) => (form.pic = u))" />
            <el-button v-else size="small" @click="openImg((u) => (form.pic = u))">上传图片</el-button>
          </el-form-item>
          <el-form-item label="商品轮播图">
            <div class="pic-line">
              <div v-for="u in (form.pics ? form.pics.split(',').filter(Boolean) : [])" :key="u" class="pic-item">
                <el-image :src="u" fit="cover" class="pic-thumb" />
                <span class="pic-del" @click="form.pics = (form.pics.split(',').filter((x: string) => x !== u) as string[]).join(',')">×</span>
              </div>
              <el-button size="small" @click="multiPickerVisible = true">+ 添加</el-button>
            </div>
          </el-form-item>
          <el-form-item label="原价">
            <el-input-number v-model="form.market_price" :min="0" :precision="2" :controls="false" style="width: 140px" />
            <span class="ge-unit">元</span>
          </el-form-item>
          <el-form-item label="秒杀价" required>
            <el-input-number v-model="form.sell_price" :min="0" :precision="2" :controls="false" style="width: 140px" />
            <span class="ge-unit">元</span>
          </el-form-item>
          <el-form-item label="商品卖点">
            <el-input v-model="form.sellpoint" maxlength="60" style="width: 400px" />
          </el-form-item>
          <el-form-item label="秒杀日期">
            <el-date-picker v-model="form.seckill_date" type="date" value-format="YYYY-MM-DD" placeholder="选择日期" />
          </el-form-item>
          <el-form-item label="秒杀时间">
            <el-select v-model="form.seckill_time" placeholder="请选择" style="width: 160px">
              <el-option v-for="h in timeset" :key="h" :label="`${pad2(h)}:00`" :value="h" />
            </el-select>
          </el-form-item>
          <el-form-item label="限购每人">
            <el-input-number v-model="form.perlimit" :min="0" :controls="false" style="width: 140px" />
            <span class="ge-unit">件（0 不限）</span>
          </el-form-item>
          <el-form-item label="活动限购">
            <el-input-number v-model="form.buymax" :min="0" :controls="false" style="width: 140px" />
            <span class="ge-unit">件（0 不限）</span>
          </el-form-item>
          <el-form-item label="序号">
            <el-input-number v-model="form.sort" :min="0" :controls="false" style="width: 140px" />
          </el-form-item>
          <el-form-item label="上架状态">
            <el-switch v-model="form.status" :active-value="1" :inactive-value="0" />
          </el-form-item>
          <el-form-item label="规格">
            <table class="kj-table">
              <thead><tr><th style="width:200px">规格名称</th><th style="width:160px">秒杀价</th><th style="width:160px">库存</th><th style="width:90px">操作</th></tr></thead>
              <tbody>
                <tr v-for="(g, i) in guige" :key="i">
                  <td><el-input v-model="g.name" placeholder="规格名称" size="small" style="width: 180px" /></td>
                  <td><el-input-number v-model="g.sell_price" :min="0" :precision="2" :controls="false" size="small" style="width: 140px" /></td>
                  <td><el-input-number v-model="g.stock" :min="0" :controls="false" size="small" style="width: 140px" /></td>
                  <td><el-button size="small" type="danger" plain @click="removeGuige(i)">删除</el-button></td>
                </tr>
              </tbody>
            </table>
            <el-button size="small" style="margin-top: 10px" @click="addGuige">+ 添加规格</el-button>
          </el-form-item>
        </el-tab-pane>

        <!-- 商品详情 -->
        <el-tab-pane label="商品详情" name="detail">
          <el-form-item label="商品详情">
            <el-input v-model="form.detail" type="textarea" :rows="8" placeholder="请输入商品详情（支持 HTML）" style="width: 720px" />
          </el-form-item>
        </el-tab-pane>
      </el-tabs>

      <div class="tab-footer">
        <el-button type="primary" :loading="submitting" @click="handleSubmit">保存</el-button>
        <el-button @click="backToList">取消</el-button>
      </div>
    </el-form>
  </div>

  <!-- 选择商城商品弹窗 -->
  <el-dialog v-model="chooseVisible" title="选择商城商品" width="760px">
    <el-form inline @submit.prevent style="margin-bottom: 10px">
      <el-form-item label="商品名称">
        <el-input v-model="chooseKeyword" placeholder="商品名称" clearable style="width: 200px" @keyup.enter="handleChooseSearch" />
      </el-form-item>
      <el-form-item>
        <el-button type="primary" @click="handleChooseSearch">搜索</el-button>
      </el-form-item>
    </el-form>
    <el-table v-loading="chooseLoading" :data="chooseList" border size="small">
      <el-table-column label="商品" min-width="280">
        <template #default="{ row }">
          <div style="display:flex;align-items:center;gap:8px">
            <el-image v-if="row.image" :src="row.image" fit="cover" style="width:40px;height:40px;border-radius:4px" />
            <span>{{ row.name }}</span>
          </div>
        </template>
      </el-table-column>
      <el-table-column label="市场价" width="100">
        <template #default="{ row }">￥{{ Number(row.market_price || row.price).toFixed(2) }}</template>
      </el-table-column>
      <el-table-column label="操作" width="90">
        <template #default="{ row }">
          <el-button link type="primary" @click="pickGoods(row)">选择</el-button>
        </template>
      </el-table-column>
    </el-table>
    <el-pagination
      style="margin-top: 10px; justify-content: flex-end" background layout="prev, pager, next"
      :total="chooseTotal" :current-page="choosePage" :page-size="10"
      @current-change="(p: number) => { choosePage = p; fetchChoose() }"
    />
  </el-dialog>

  <ImgIconPicker v-model:visible="imgPickerVisible" default-tab="library" @pick="onPick" />
  <ImgIconPicker v-model:visible="multiPickerVisible" default-tab="library" multi @pick="onMultiPick" />
</template>

<style scoped>
.option-bar { margin-bottom: 10px; }
.option-bar :deep(.el-card__body) { padding: 12px 16px; }
.option-bar .el-button { margin-left: 0; margin-right: 8px; }
.search-bar { margin-bottom: 12px; }
.search-bar :deep(.el-card__body) { padding: 14px 16px 2px; }
.search-bar :deep(.el-form-item) { margin-bottom: 12px; }
.search-icon-btn { padding: 8px 14px; }
.search-icon-btn svg { display: block; }

.goods-info { display: flex; align-items: center; gap: 10px; }
.goods-info-img { width: 70px; height: 70px; border-radius: 4px; border: 1px solid #ebeef5; flex-shrink: 0; }
.goods-info-img-empty { display: flex; align-items: center; justify-content: center; color: #c0c4cc; font-size: 12px; background: #fafafa; }
.goods-info-meta { min-width: 0; flex: 1; }
.goods-info-name { color: #303133; font-size: 13px; line-height: 1.5; word-break: break-all; }
.goods-info-sub { color: #999; font-size: 12px; margin-top: 2px; }
.goods-info-price { color: #ff6600; font-weight: 600; font-size: 13px; margin-top: 2px; }
.prog-num { color: #909399; font-size: 12px; margin-top: 2px; }

.st-live { color: #f55; }
.st-off { color: #f55; }
.st-pending { color: #888; }
.st-end { color: #ccc; }
.ck-pending { color: #409eff; }
.ck-pass { color: #21ba45; }
.ck-reject { color: #f56c6c; }

.row-ops { display: flex; flex-direction: column; align-items: flex-start; line-height: 1.4; }
.row-ops :deep(.el-button) { height: auto; padding: 1px 0; margin: 0; font-size: 12px; }
.pagination { margin-top: 16px; justify-content: flex-end; }

.goods-edit { background: #fff; border-radius: 6px; padding: 16px 20px; }
.ge-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; }
.ge-head-title { font-size: 16px; font-weight: 600; color: #303133; }
.ge-close { border: none; background: transparent; color: #909399; }
.ge-close:hover { color: #409eff; }
.ge-form :deep(.el-form-item) { margin-bottom: 15px; }
.ge-hint { color: #909399; font-size: 12px; margin-left: 10px; }
.ge-unit { color: #909399; font-size: 12px; margin-left: 8px; }
.tab-footer { display: flex; justify-content: center; gap: 14px; padding: 16px 0 8px; border-top: 1px solid #ebeef5; }

.main-pic { width: 80px; height: 80px; border-radius: 4px; border: 1px solid #e4e7ed; cursor: pointer; }
.pic-line { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; }
.pic-item { position: relative; display: flex; flex-direction: column; align-items: center; }
.pic-thumb { width: 64px; height: 64px; border-radius: 4px; border: 1px solid #e4e7ed; object-fit: cover; }
.pic-del { position: absolute; top: -6px; right: -6px; width: 18px; height: 18px; line-height: 16px; text-align: center; background: #f56c6c; color: #fff; border-radius: 50%; cursor: pointer; font-size: 12px; }
.kj-table { width: 100%; max-width: 760px; border-collapse: collapse; font-size: 13px; }
.kj-table th, .kj-table td { border: 1px solid #dcdfe6; padding: 6px 8px; text-align: center; }
.kj-table th { background: #f5f7fa; color: #606266; font-weight: 600; }
</style>
