<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import type { FormInstance, FormRules } from 'element-plus'
import {
  batchDeleteCollageProducts,
  batchSetCollageProductStatus,
  checkCollageProduct,
  getCollageProductDetail,
  getCollageProductList,
  saveCollageProduct,
} from '../../api/collage'
import type { CollageProductRow } from '../../api/collage'
import type { CollageProductDetail } from '../../api/types'
import { getCollageCategoryList } from '../../api/collageCategory'
import { getGoodsList } from '../../api/goods'
import { formatTime } from '../../utils/time'
import ImgIconPicker from '../../components/ImgIconPicker.vue'
import GoodsDetailEditor from '../../components/GoodsDetailEditor.vue'
import type { DesignerModule } from '../../api/designer'
import { defaultModule } from './designerModules'

// ============ 双模式：bid 有值=商户视图(showtype=2)，无值=平台自营视图 ============
const props = withDefaults(defineProps<{ bid?: string }>(), { bid: '' })
const isMerchant = computed(() => !!props.bid)

// ============ 列表 ============
const viewMode = ref<'list' | 'edit'>('list')
const loading = ref(false)
const tableData = ref<CollageProductRow[]>([])
const total = ref(0)
const selected = ref<CollageProductRow[]>([])

const query = reactive({
  page: 1,
  pagesize: 10,
  keyword: '',
  cid: '' as number | '',
  status: '' as number | '',
  orderField: '',
  orderType: '',
})

// 两级分类（collageCategory，pid=0 为父）
interface CatNode {
  id: number
  name: string
  children: { id: number; name: string }[]
}
const categoryTree = ref<CatNode[]>([])

async function fetchCategories() {
  try {
    const data = await getCollageCategoryList()
    const list = data.list || []
    categoryTree.value = list
      .filter((c) => c.pid === 0)
      .map((p) => ({
        id: p.id,
        name: p.name,
        children: list.filter((c) => c.pid === p.id),
      }))
  } catch {
    // 忽略
  }
}

async function fetchList() {
  loading.value = true
  try {
    const data = await getCollageProductList({
      page: query.page,
      pagesize: query.pagesize,
      keyword: query.keyword || undefined,
      cid: query.cid === '' ? undefined : Number(query.cid),
      status: query.status === '' ? undefined : query.status,
      orderField: query.orderField || undefined,
      orderType: query.orderType || undefined,
      bid: props.bid || undefined,
    })
    tableData.value = data.list || []
    total.value = data.total || 0
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    loading.value = false
  }
}

function handleSearch() {
  query.page = 1
  fetchList()
}

function handleSelectionChange(rows: CollageProductRow[]) {
  selected.value = rows
}

function handleSortChange({ prop, order }: { prop: string; order: null | 'ascending' | 'descending' }) {
  if (!prop || !order) {
    query.orderField = ''
    query.orderType = ''
  } else {
    query.orderField = prop
    query.orderType = order === 'ascending' ? 'asc' : 'desc'
  }
  query.page = 1
  fetchList()
}

function handlePageChange(page: number) {
  query.page = page
  fetchList()
}

function handleSizeChange(size: number) {
  query.pagesize = size
  query.page = 1
  fetchList()
}

function requireSelection(): boolean {
  if (selected.value.length === 0) {
    ElMessage.warning('请选择数据')
    return false
  }
  return true
}

// ============ 批量操作 ============
function handleBatchDelete() {
  if (!requireSelection()) return
  const ids = selected.value.map((r) => r.id)
  ElMessageBox.confirm('确定要删除选中的拼团商品吗？删除后不可恢复。', '删除确认', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      await batchDeleteCollageProducts(ids)
      ElMessage.success('删除成功')
      fetchList()
    })
    .catch(() => {})
}

function handleBatchStatus(st: number) {
  if (!requireSelection()) return
  const ids = selected.value.map((r) => r.id)
  ElMessageBox.confirm(`确定要${st === 1 ? '上架' : '下架'}选中的商品吗？`, '操作确认', {
    confirmButtonText: '确定',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      await batchSetCollageProductStatus(ids, st)
      ElMessage.success(st === 1 ? '已上架' : '已下架')
      fetchList()
    })
    .catch(() => {})
}

// ============ 行操作 ============
function handleRowDelete(row: CollageProductRow) {
  ElMessageBox.confirm(`确定要删除拼团商品「${row.name}」吗？删除后不可恢复。`, '删除确认', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      await batchDeleteCollageProducts([row.id])
      ElMessage.success('删除成功')
      fetchList()
    })
    .catch(() => {})
}

function handleRowStatus(row: CollageProductRow) {
  const target = row.status === 1 ? 0 : 1
  ElMessageBox.confirm(`确定要${target === 1 ? '上架' : '下架'}「${row.name}」吗？`, '操作确认', {
    confirmButtonText: '确定',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      await batchSetCollageProductStatus([row.id], target)
      ElMessage.success(target === 1 ? '已上架' : '已下架')
      fetchList()
    })
    .catch(() => {})
}

function handleCheck(row: CollageProductRow, pass: boolean) {
  if (pass) {
    ElMessageBox.confirm(`确定通过商品「${row.name}」的审核吗？`, '审核确认', {
      confirmButtonText: '通过',
      cancelButtonText: '取消',
      type: 'success',
    })
      .then(async () => {
        await checkCollageProduct(row.id, 1)
        ElMessage.success('审核通过')
        fetchList()
      })
      .catch(() => {})
    return
  }
  ElMessageBox.prompt('请输入驳回原因', '驳回审核', {
    confirmButtonText: '驳回',
    cancelButtonText: '取消',
    inputType: 'textarea',
    inputPlaceholder: '请填写驳回原因',
    type: 'warning',
  })
    .then(async ({ value }) => {
      await checkCollageProduct(row.id, 2, (value || '').trim())
      ElMessage.success('已驳回')
      fetchList()
    })
    .catch(() => {})
}

// ============ 平铺编辑（viewMode） ============
const isEdit = ref(false)
const activeTab = ref('basic')
const formRef = ref<FormInstance>()
const submitting = ref(false)

interface SpecGroup {
  title: string
  items: { title: string }[]
}
interface SkuRow {
  specName: string
  procode: string
  market_price: number
  sell_price: number
  weight: number
  stock: number
  pic: string
}

const form = reactive({
  id: 0,
  goods_id: 0,
  name: '',
  pic: '',
  picsArr: [] as string[],
  sellpoint: '',
  cid: 0,
  no_discount: -1,
  freighttype: 1,
  teamnum: 3,
  buymax: 0,
  teamhour: 36,
  leadermoney: 0,
  leaderscore: 10,
  commissionset: 0,
  sales: 0,
  sort: 0,
  status: 1,
  detailModules: [] as DesignerModule[],
})

const specGroups = ref<SpecGroup[]>([{ title: '规格', items: [{ title: '默认规格' }] }])
const skuRows = ref<SkuRow[]>([])

const COMMISSION_OPTIONS = [
  { value: 0, label: '按照会员等级' },
  { value: 1, label: '单独设置提成比例' },
  { value: 2, label: '单独设置提成金额' },
  { value: 3, label: '分销送积分' },
  { value: -1, label: '不参与分销' },
]

const rules: FormRules = {
  name: [{ required: true, message: '请输入商品名称', trigger: 'blur' }],
  teamnum: [{ required: true, message: '请输入拼团人数', trigger: 'blur' }],
  teamhour: [{ required: true, message: '请输入拼团时间', trigger: 'blur' }],
}

function defaultDetailModules(): DesignerModule[] {
  return [defaultModule('richtext')]
}

function resetForm() {
  Object.assign(form, {
    id: 0,
    goods_id: 0,
    name: '',
    pic: '',
    picsArr: [],
    sellpoint: '',
    cid: 0,
    no_discount: -1,
    freighttype: 1,
    teamnum: 3,
    buymax: 0,
    teamhour: 36,
    leadermoney: 0,
    leaderscore: 10,
    commissionset: 0,
    sales: 0,
    sort: 0,
    status: 1,
    detailModules: defaultDetailModules(),
  })
  specGroups.value = [{ title: '规格', items: [{ title: '默认规格' }] }]
  skuRows.value = []
  buildDefaultSku()
}

function buildDefaultSku() {
  skuRows.value = [
    { specName: '默认规格', procode: '', market_price: 0, sell_price: 0, weight: 0, stock: 1000, pic: '' },
  ]
}

function openAdd() {
  isEdit.value = false
  resetForm()
  activeTab.value = 'basic'
  viewMode.value = 'edit'
}

async function openEdit(row: CollageProductRow) {
  isEdit.value = true
  resetForm()
  activeTab.value = 'basic'
  viewMode.value = 'edit'
  try {
    const detail = (await getCollageProductDetail(row.id)) as CollageProductDetail & {
      pics?: string
      no_discount?: number
      freighttype?: number
      leaderscore?: number
      commissionset?: number
      sales?: number
    }
    Object.assign(form, {
      id: detail.id,
      goods_id: (detail as unknown as Record<string, unknown>).goods_id || 0,
      name: detail.name,
      pic: detail.pic,
      picsArr: detail.pics ? String(detail.pics).split(',').filter(Boolean) : [],
      cid: detail.cid || 0,
      sellpoint: detail.sellpoint || '',
      no_discount: detail.no_discount ?? -1,
      freighttype: detail.freighttype ?? 1,
      teamnum: detail.teamnum,
      buymax: detail.buymax,
      teamhour: detail.teamhour,
      leadermoney: Number(detail.leadermoney) || 0,
      leaderscore: Number(detail.leaderscore ?? 10),
      commissionset: detail.commissionset ?? 0,
      sales: Number(detail.sales) || 0,
      sort: detail.sort,
      status: detail.status,
    })
    // 详情装修器：detail 为 DesignerModule[] 的 JSON
    let modules: DesignerModule[] = []
    if (detail.detail) {
      try {
        const parsed = JSON.parse(detail.detail)
        if (Array.isArray(parsed)) modules = parsed
      } catch {
        modules = []
      }
    }
    form.detailModules = modules.length ? modules : defaultDetailModules()
    // 规格 SKU
    const opts = detail.options || []
    if (opts.length) {
      skuRows.value = opts.map((o) => ({
        specName: String(o.name || ''),
        procode: String((o as unknown as Record<string, unknown>).procode || ''),
        market_price: Number(o.market_price) || 0,
        sell_price: Number(o.sell_price) || 0,
        weight: Number((o as unknown as Record<string, unknown>).weight || 0),
        stock: Number(o.stock) || 0,
        pic: String((o as unknown as Record<string, unknown>).pic || ''),
      }))
      specGroups.value = [{ title: '规格', items: opts.map((o) => ({ title: String(o.name || '') })) }]
    } else {
      buildDefaultSku()
    }
  } catch {
    viewMode.value = 'list'
  }
}

function backToList() {
  viewMode.value = 'list'
  resetForm()
  fetchList()
}

// ============ 规格分组 / SKU ============
function addGroup() {
  specGroups.value.push({ title: '', items: [{ title: '' }] })
}
function removeGroup(i: number) {
  specGroups.value.splice(i, 1)
}
function addItem(g: SpecGroup) {
  g.items.push({ title: '' })
}
function removeItem(g: SpecGroup, i: number) {
  g.items.splice(i, 1)
}

// 设置完成：按规格分组笛卡尔积展开 SKU 表，保留已填值
function refreshSku() {
  const groups = specGroups.value.filter((g) => g.title.trim() && g.items.some((it) => it.title.trim()))
  if (groups.length === 0) {
    ElMessage.warning('请先填写规格分组与规格名称')
    return
  }
  const dims = groups.map((g) => g.items.map((it) => it.title.trim()).filter(Boolean))
  if (dims.some((d) => d.length === 0)) {
    ElMessage.warning('请完整填写规格分组与规格名称')
    return
  }
  let combos: string[][] = [[]]
  for (const d of dims) {
    const next: string[][] = []
    for (const c of combos) for (const t of d) next.push([...c, t])
    combos = next
  }
  const prev = new Map(skuRows.value.map((r) => [r.specName, r]))
  skuRows.value = combos.map((names) => {
    const specName = names.join(' ')
    const old = prev.get(specName)
    return old
      ? { ...old, specName }
      : { specName, procode: '', market_price: 0, sell_price: 0, weight: 0, stock: 1000, pic: '' }
  })
  ElMessage.success('规格设置完成')
}

async function handleSubmit() {
  if (!formRef.value) return
  await formRef.value.validate(async (valid) => {
    if (!valid) return
    if (!form.pic) {
      ElMessage.warning('请上传商品主图')
      activeTab.value = 'basic'
      return
    }
    if (skuRows.value.length === 0) {
      ElMessage.warning('请设置商品规格')
      activeTab.value = 'basic'
      return
    }
    submitting.value = true
    try {
      await saveCollageProduct({
        id: form.id || undefined,
        goods_id: form.goods_id || undefined,
        name: form.name,
        pic: form.pic,
        pics: form.picsArr.join(','),
        cid: form.cid,
        sellpoint: form.sellpoint,
        no_discount: form.no_discount,
        freighttype: form.freighttype,
        teamnum: form.teamnum,
        teamhour: form.teamhour,
        leadermoney: form.leadermoney,
        buymax: form.buymax,
        leaderscore: form.leaderscore,
        commissionset: form.commissionset,
        sales: form.sales,
        sort: form.sort,
        status: form.status,
        detail: JSON.stringify(form.detailModules || []),
        options: skuRows.value.map((r) => ({
          name: r.specName,
          procode: r.procode,
          market_price: r.market_price,
          sell_price: r.sell_price,
          weight: r.weight,
          stock: r.stock,
          pic: r.pic,
        })),
      })
      ElMessage.success('保存成功')
      backToList()
    } catch {
      // 错误提示已由 axios 拦截器统一弹出
    } finally {
      submitting.value = false
    }
  })
}

// ============ 图片选择 ============
const picPickerVisible = ref(false)
const picsPickerVisible = ref(false)
function onPickPic(urls: string[]) {
  if (urls[0]) form.pic = urls[0]
}
function onPickPics(urls: string[]) {
  form.picsArr = urls.slice()
}
function removePic(idx: number) {
  form.picsArr.splice(idx, 1)
}

// SKU 行图片上传
const skuPicPickerVisible = ref(false)
let skuPicTarget: SkuRow | null = null
function openSkuPic(row: SkuRow) {
  skuPicTarget = row
  skuPicPickerVisible.value = true
}
function onPickSkuPic(urls: string[]) {
  if (skuPicTarget && urls[0]) skuPicTarget.pic = urls[0]
}

// ============ 选择商城商品 ============
const chooseVisible = ref(false)
const chooseLoading = ref(false)
const chooseKeyword = ref('')
const choosePage = ref(1)
const chooseTotal = ref(0)
const chooseList = ref<{ id: number; name: string; image: string; price: number; market_price: number }[]>([])

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
    chooseList.value = (d.list || []).map((g: any) => ({
      id: Number(g.id),
      name: String(g.name),
      image: String(g.image || ''),
      price: Number(g.price),
      market_price: Number(g.market_price),
    }))
    chooseTotal.value = d.total || 0
  } catch {
    // ignore
  } finally {
    chooseLoading.value = false
  }
}
function handleChooseSearch() {
  choosePage.value = 1
  fetchChoose()
}
function pickGoods(g: { id: number; name: string; image: string; price: number; market_price: number }) {
  form.goods_id = g.id
  form.name = g.name
  form.pic = g.image
  if (g.image) form.picsArr = [g.image]
  // 预填到 SKU：单买价=市场价，拼团价=商城价
  skuRows.value.forEach((r) => {
    r.market_price = Number(g.market_price || g.price)
    r.sell_price = Number(g.price) || r.sell_price
  })
  chooseVisible.value = false
}

onMounted(() => {
  fetchCategories()
  fetchList()
})
</script>

<template>
  <!-- ============ 列表视图 ============ -->
  <div v-if="viewMode === 'list'">
    <el-card shadow="never" class="toolbar">
      <div class="toolbar-left">
        <el-button v-if="!isMerchant" type="success" @click="openAdd">添加</el-button>
        <el-button @click="handleBatchDelete">删除</el-button>
        <el-button @click="handleBatchStatus(1)">上架</el-button>
        <el-button @click="handleBatchStatus(0)">下架</el-button>
      </div>
      <el-form inline class="toolbar-search" @submit.prevent>
        <el-form-item label="商品名称">
          <el-input
            v-model="query.keyword"
            placeholder="商品名称"
            clearable
            style="width: 120px"
            @keyup.enter="handleSearch"
          />
        </el-form-item>
        <el-form-item label="分类">
          <el-select v-model="query.cid" placeholder="全部" clearable style="width: 110px">
            <el-option-group v-for="g in categoryTree" :key="g.id" :label="g.name">
              <el-option :value="g.id" :label="g.name" />
              <el-option v-for="c in g.children" :key="c.id" :value="c.id" :label="'　' + c.name" />
            </el-option-group>
          </el-select>
        </el-form-item>
        <el-form-item label="状态">
          <el-select v-model="query.status" placeholder="全部" clearable style="width: 90px">
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

    <el-card shadow="never" class="main-card">
      <el-table
        v-loading="loading"
        :data="tableData"
        border
        header-cell-class-name="tbl-head"
        @selection-change="handleSelectionChange"
        @sort-change="handleSortChange"
      >
        <el-table-column type="selection" width="40" />
        <el-table-column prop="id" label="ID" width="60" sortable="custom" />
        <el-table-column v-if="isMerchant" prop="bname" label="所属商户" width="120" />
        <el-table-column label="商品信息" min-width="260">
          <template #default="{ row }">
            <div class="goods-info">
              <el-image
                v-if="row.pic"
                :src="row.pic"
                :preview-src-list="[row.pic]"
                fit="cover"
                class="goods-info-img"
                preview-teleported
              />
              <div v-else class="goods-info-img goods-info-img-empty">无图</div>
              <div class="goods-info-meta">
                <div class="goods-info-name">
                  <span v-if="row.cid" class="goods-info-cname">[{{ row.cname }}]</span>{{ row.name }}
                </div>
                <div class="goods-info-price">￥{{ Number(row.sell_price).toFixed(2) }}</div>
              </div>
            </div>
          </template>
        </el-table-column>
        <el-table-column prop="teamnum" label="拼团人数" width="80" />
        <el-table-column prop="stock" label="库存" width="80" />
        <el-table-column prop="sales" label="销量" width="80" sortable="custom" />
        <el-table-column prop="sort" label="序号" width="70" sortable="custom" />
        <el-table-column prop="createtime" label="创建时间" width="132" sortable="custom">
          <template #default="{ row }">{{ formatTime(row.createtime) }}</template>
        </el-table-column>
        <el-table-column label="状态" width="86">
          <template #default="{ row }">
            <span :class="row.status === 1 ? 'status-on' : 'status-off'">{{ row.status === 1 ? '已上架' : '未上架' }}</span>
          </template>
        </el-table-column>
        <el-table-column v-if="isMerchant" label="审核状态" width="86">
          <template #default="{ row }">
            <span v-if="row.ischecked === 1" class="check-on">已通过</span>
            <span v-else-if="row.ischecked === 2" class="check-off" :title="row.check_reason">已驳回</span>
            <span v-else class="check-wait">待审核</span>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="220">
          <template #default="{ row }">
            <div class="row-ops">
              <el-button link type="primary" @click="openEdit(row)">编辑</el-button>
              <el-button link type="danger" @click="handleRowDelete(row)">删除</el-button>
              <el-button link :type="row.status === 1 ? 'warning' : 'success'" @click="handleRowStatus(row)">
                {{ row.status === 1 ? '下架' : '上架' }}
              </el-button>
              <template v-if="isMerchant">
                <el-button
                  v-if="row.ischecked === 0 || row.ischecked === 2"
                  link
                  type="success"
                  @click="handleCheck(row, true)"
                >通过</el-button>
                <el-button
                  v-if="row.ischecked === 0 || row.ischecked === 1"
                  link
                  type="warning"
                  @click="handleCheck(row, false)"
                >驳回</el-button>
              </template>
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
  </div>

  <!-- ============ 平铺编辑视图 ============ -->
  <div v-else class="goods-edit">
    <div class="ge-head">
      <span class="ge-head-title">{{ isEdit ? '编辑商品' : '＋添加商品' }}</span>
      <el-button class="ge-close" circle @click="backToList"><svg viewBox="0 0 1024 1024" width="16" height="16" fill="currentColor"><path d="M764.288 214.595 512 466.883 259.712 214.595a32 32 0 0 0-45.248 45.248L466.752 512 214.464 764.288a32 32 0 1 0 45.248 45.248L512 557.117l252.288 252.419a32 32 0 0 0 45.248-45.248L557.248 512l252.288-252.157a32 32 0 0 0-45.248-45.248z"/></svg></el-button>
    </div>

    <el-form
      ref="formRef"
      :model="form"
      :rules="rules"
      label-position="right"
      label-width="116px"
      class="ge-form"
    >
      <el-tabs v-model="activeTab" class="ge-tabs">
        <!-- ===== tab1 商品基本信息 ===== -->
        <el-tab-pane label="商品基本信息" name="basic">
          <el-form-item label="商品名称" required>
            <el-input v-model="form.name" maxlength="120" style="width: 300px" placeholder="请输入商品名称" />
            <el-button style="margin-left: 10px" @click="openChoose">选择商城商品</el-button>
          </el-form-item>

          <el-form-item label="商品主图" required>
            <div class="ge-pic-row">
              <el-image v-if="form.pic" :src="form.pic" fit="cover" class="main-pic" />
              <el-button size="small" @click="picPickerVisible = true">上传图片</el-button>
              <el-button v-if="form.pic" size="small" link type="danger" @click="form.pic = ''">删除</el-button>
              <span class="ge-hint">建议尺寸：640×640像素</span>
            </div>
          </el-form-item>

          <el-form-item label="商品图片">
            <div class="ge-pic-row">
              <div v-for="(url, i) in form.picsArr" :key="url" class="ge-thumb-item">
                <el-image :src="url" fit="cover" class="pic-thumb" />
                <span class="ge-upload-del" @click="removePic(i)">×</span>
              </div>
              <el-button size="small" @click="picsPickerVisible = true">批量上传</el-button>
              <span class="ge-hint">建议尺寸：640×640像素</span>
            </div>
          </el-form-item>

          <el-form-item label="商品卖点">
            <el-input v-model="form.sellpoint" maxlength="60" show-word-limit style="width: 400px" placeholder="请输入商品卖点" />
            <span class="ge-hint">在商品详情页标题下面展示卖点信息，建议60字以内</span>
          </el-form-item>

          <el-form-item label="商品分类">
            <el-select v-model="form.cid" placeholder="--请选择--" clearable style="width: 400px">
              <el-option-group v-for="g in categoryTree" :key="g.id" :label="g.name">
                <el-option :value="g.id" :label="g.name" />
                <el-option v-for="c in g.children" :key="c.id" :value="c.id" :label="'　' + c.name" />
              </el-option-group>
            </el-select>
          </el-form-item>

          <el-form-item label="设置规格">
            <div class="spec-wrap spec-table-auto">
              <table class="gg-table">
                <thead>
                  <tr>
                    <th style="width: 150px">规格分组</th>
                    <th>规格名称</th>
                    <th style="width: 90px">操作</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="(g, gi) in specGroups" :key="gi">
                    <td>
                      <el-input v-model="g.title" style="width: 120px" placeholder="规格分组" />
                    </td>
                    <td>
                      <div v-for="(it, ii) in g.items" :key="ii" class="gg-name-line">
                        <el-input v-model="it.title" style="width: 200px" placeholder="请输入规格名称" />
                        <el-button size="small" @click="addItem(g)">+</el-button>
                        <el-button v-if="ii > 0" size="small" type="danger" plain @click="removeItem(g, ii)">×</el-button>
                      </div>
                    </td>
                    <td>
                      <el-button v-if="gi === 0" size="small" @click="addGroup">添加</el-button>
                      <el-button v-else size="small" type="danger" plain @click="removeGroup(gi)">删除</el-button>
                    </td>
                  </tr>
                </tbody>
              </table>
              <el-button size="small" style="margin-top: 8px" @click="refreshSku">设置完成</el-button>

              <table v-if="skuRows.length" class="sku-table">
                <thead>
                  <tr>
                    <th>规格</th>
                    <th>规格编号</th>
                    <th>单买价(元)</th>
                    <th>拼团价(元)</th>
                    <th>重量(克)</th>
                    <th>库存</th>
                    <th>图片</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="(row, ri) in skuRows" :key="ri">
                    <td class="sku-spec-cell">{{ row.specName }}</td>
                    <td><el-input v-model="row.procode" placeholder="编号" style="width: 110px" /></td>
                    <td><el-input-number v-model="row.market_price" :min="0" :precision="2" :controls="false" style="width: 95px" /></td>
                    <td><el-input-number v-model="row.sell_price" :min="0" :precision="2" :controls="false" style="width: 95px" /></td>
                    <td><el-input-number v-model="row.weight" :min="0" :controls="false" style="width: 85px" /></td>
                    <td><el-input-number v-model="row.stock" :min="0" :controls="false" style="width: 85px" /></td>
                    <td>
                      <el-image
                        v-if="row.pic"
                        :src="row.pic"
                        fit="cover"
                        class="sku-pic"
                        @click="openSkuPic(row)"
                      />
                      <span v-else class="sku-pic-empty" @click="openSkuPic(row)">上传</span>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </el-form-item>

          <el-form-item label="会员等级折扣">
            <el-radio-group v-model="form.no_discount">
              <el-radio :value="-1">跟随系统</el-radio>
              <el-radio :value="0">参与</el-radio>
              <el-radio :value="1">不参与</el-radio>
            </el-radio-group>
            <span class="ge-hint">跟随系统：多人拼团-系统设置-会员等级折扣</span>
          </el-form-item>

          <el-form-item label="配送模板">
            <el-radio-group v-model="form.freighttype">
              <el-radio :value="1">全部模板</el-radio>
              <el-radio :value="0">选择模板</el-radio>
              <el-radio :value="3">自动发货</el-radio>
              <el-radio :value="4">在线卡密</el-radio>
            </el-radio-group>
          </el-form-item>

          <el-form-item label="拼团人数" required>
            <el-input-number v-model="form.teamnum" :min="2" :max="100" :precision="0" :step="1" />
            <span class="ge-hint">每个团多少人</span>
          </el-form-item>
          <el-form-item label="每人限购">
            <el-input-number v-model="form.buymax" :min="0" :precision="0" :step="1" />
            <span class="ge-hint">每人最多可购买多少件，0表示不限购</span>
          </el-form-item>
          <el-form-item label="拼团时间" required>
            <el-input-number v-model="form.teamhour" :min="1" :max="720" :precision="0" :step="1" />
            <span class="ge-hint">小时，超过该时间人数未满则拼团失败，自动退款</span>
          </el-form-item>
          <el-form-item label="团长优惠金额">
            <el-input-number v-model="form.leadermoney" :min="0" :precision="2" :step="1" />
            <span class="ge-hint">元，在拼团价基础上再优惠多少钱，0表示不优惠</span>
          </el-form-item>
          <el-form-item label="团长奖励积分">
            <el-input-number v-model="form.leaderscore" :min="0" :precision="0" :step="1" />
            <span class="ge-hint">拼团成功后赠送团长多少积分</span>
          </el-form-item>

          <el-form-item label="分销设置">
            <el-radio-group v-model="form.commissionset">
              <el-radio v-for="o in COMMISSION_OPTIONS" :key="o.value" :value="o.value">{{ o.label }}</el-radio>
            </el-radio-group>
            <div class="ge-block-hint">
              按照会员等级：按照会员等级中设置的提成比例进行提成分配，提成=对应会员等级的提成比例×商品金额×购买数量<br>
              单独设置提成比例：按照单独设置的提成比例进行提成分配，提成=设置的百分比×商品金额×购买数量<br>
              单独设置提成金额：按照单独设置的提成金额进行提成分配，提成=提成金额×购买数量<br>
              分销送积分：分销提成不给佣金而是给积分，得到的积分=提成积分×购买数量
            </div>
          </el-form-item>

          <el-form-item label="销量">
            <el-input-number v-model="form.sales" :min="0" :precision="0" :step="1" />
          </el-form-item>
          <el-form-item label="序号">
            <el-input-number v-model="form.sort" :min="0" :precision="0" :step="1" />
            <span class="ge-hint">用于排序，越大越靠前</span>
          </el-form-item>
          <el-form-item label="状态">
            <el-radio-group v-model="form.status">
              <el-radio :value="1">已上架</el-radio>
              <el-radio :value="0">未上架</el-radio>
            </el-radio-group>
          </el-form-item>

          <el-form-item label=" ">
            <el-button type="primary" @click="activeTab = 'detail'">下一步:编辑商品详情</el-button>
            <el-button type="success" :loading="submitting" @click="handleSubmit">提交</el-button>
          </el-form-item>
        </el-tab-pane>

        <!-- ===== tab2 商品详情（DIY 装修器） ===== -->
        <el-tab-pane label="商品详情" name="detail">
          <GoodsDetailEditor v-model="form.detailModules" title="商品详情" />
          <el-form-item label=" " class="tab-detail-footer">
            <el-button type="primary" @click="activeTab = 'basic'">上一步:编辑商品信息</el-button>
            <el-button type="success" :loading="submitting" @click="handleSubmit">提交</el-button>
          </el-form-item>
        </el-tab-pane>
      </el-tabs>
    </el-form>

    <ImgIconPicker v-model:visible="picPickerVisible" default-tab="upload" @pick="onPickPic" />
    <ImgIconPicker v-model:visible="picsPickerVisible" default-tab="upload" multi @pick="onPickPics" />
    <ImgIconPicker v-model:visible="skuPicPickerVisible" default-tab="upload" @pick="onPickSkuPic" />

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
            <div style="display: flex; align-items: center; gap: 8px">
              <el-image v-if="row.image" :src="row.image" fit="cover" style="width: 40px; height: 40px; border-radius: 4px" />
              <span>{{ row.name }}</span>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="商城价" width="110">
          <template #default="{ row }">￥{{ Number(row.price).toFixed(2) }}</template>
        </el-table-column>
        <el-table-column label="操作" width="90" align="center">
          <template #default="{ row }">
            <el-button link type="primary" @click="pickGoods(row)">选择</el-button>
          </template>
        </el-table-column>
      </el-table>
      <el-pagination
        style="margin-top: 10px; justify-content: flex-end"
        background
        layout="prev, pager, next"
        :total="chooseTotal"
        :current-page="choosePage"
        :page-size="10"
        @current-change="(p: number) => { choosePage = p; fetchChoose() }"
      />
    </el-dialog>
  </div>
</template>

<style scoped>
.toolbar { margin-bottom: 12px; }
.toolbar :deep(.el-card__body) { padding: 10px 14px; display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: nowrap; }
.toolbar-left { display: flex; align-items: center; gap: 8px; flex: 0 0 auto; }
.toolbar-left .el-button { margin: 0; }
.toolbar-search { display: flex; align-items: center; flex: 1 1 auto; justify-content: flex-end; flex-wrap: nowrap; min-width: 0; margin: 0; }
.toolbar-search :deep(.el-form-item) { margin-bottom: 0; margin-right: 8px; }
.toolbar-search :deep(.el-form-item:last-child) { margin-right: 0; }
.toolbar-search :deep(.el-form-item__label) { padding-right: 6px; }
.search-icon-btn { padding: 8px 14px; }
.search-icon-btn svg { display: block; }

.main-card { margin: 0 3px; }
.main-card :deep(.tbl-head) { background: #e6e6e6 !important; }

.goods-info { display: flex; align-items: center; gap: 10px; }
.goods-info-img { width: 70px; height: 70px; border-radius: 4px; border: 1px solid #ebeef5; flex-shrink: 0; }
.goods-info-img-empty { display: flex; align-items: center; justify-content: center; color: #c0c4cc; font-size: 12px; background: #fafafa; }
.goods-info-meta { min-width: 0; flex: 1; }
.goods-info-name { color: #303133; font-size: 13px; line-height: 1.5; word-break: break-all; }
.goods-info-cname { color: #f56c6c; margin-right: 4px; }
.goods-info-price { color: #ff6600; font-weight: 600; font-size: 14px; margin-top: 6px; }
.status-on { color: #21ba45; }
.status-off { color: #f56c6c; }
.check-on { color: #21ba45; }
.check-off { color: #f56c6c; }
.check-wait { color: #409eff; }
.row-ops { display: flex; flex-direction: row; flex-wrap: wrap; align-items: center; gap: 4px; }
.row-ops .el-button { margin: 0; }
.pagination { margin-top: 16px; justify-content: flex-end; }

/* ===== 整页编辑 ===== */
.goods-edit { background: #fff; border-radius: 6px; padding: 16px 20px; }
.ge-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; }
.ge-head-title { font-size: 16px; font-weight: 600; color: #303133; }
.ge-close { border: none; background: transparent; color: #909399; }
.ge-close:hover { color: #303133; }
.ge-form :deep(.el-form-item) { margin-bottom: 15px; }
.ge-hint { color: #909399; font-size: 12px; line-height: 1.5; margin-left: 10px; }
.ge-block-hint { color: #909399; font-size: 12px; line-height: 1.7; margin-top: 6px; }
.ge-pic-row { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.main-pic, .pic-thumb { width: 80px; height: 80px; border-radius: 4px; border: 1px solid #e4e7ed; object-fit: cover; }
.ge-thumb-item { position: relative; }
.ge-upload-del { position: absolute; top: -6px; right: -6px; width: 18px; height: 18px; line-height: 16px; text-align: center; background: #909399; color: #fff; border-radius: 50%; font-size: 12px; cursor: pointer; z-index: 2; }

.spec-wrap { width: 100%; }
.gg-table { border-collapse: collapse; width: 600px; }
.gg-table th, .gg-table td { border: 1px solid #e4e7ed; padding: 6px 8px; font-size: 12px; text-align: left; }
.gg-table th { background: #f5f7fa; }
.gg-name-line { display: flex; align-items: center; gap: 6px; margin-bottom: 6px; }
.gg-name-line:last-child { margin-bottom: 0; }
.sku-table { border-collapse: collapse; width: 100%; margin-top: 16px; }
.sku-table th, .sku-table td { border: 1px solid #e4e7ed; padding: 6px 8px; font-size: 12px; }
.sku-table th { background: #f5f7fa; text-align: center; }
.sku-spec-cell { color: #303133; white-space: nowrap; }
.sku-pic { width: 40px; height: 40px; border-radius: 4px; border: 1px solid #e4e7ed; object-fit: cover; cursor: pointer; }
.sku-pic-empty { display: inline-flex; align-items: center; justify-content: center; width: 40px; height: 40px; border: 1px dashed #c0c4cc; border-radius: 4px; color: #909399; font-size: 12px; cursor: pointer; }
.tab-detail-footer { margin-top: 16px; }
</style>
