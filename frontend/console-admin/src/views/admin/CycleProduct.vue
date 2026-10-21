<script setup lang="ts">
import { computed, nextTick, onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import type { FormInstance, FormRules } from 'element-plus'
import {
  checkCycleProduct,
  deleteCycleProduct,
  getCycleCategoryList,
  getCycleProductDetail,
  getCycleProductList,
  saveCycleProduct,
  setCycleProductStatus,
} from '../../api/cycle'
import type { CycleProductRow } from '../../api/cycle'
import { getGoodsList } from '../../api/goods'
import type { DesignerModule } from '../../api/designer'
import { defaultModule } from './designerModules'
import { formatDate } from '../../utils/time'
import ImgIconPicker from '../../components/ImgIconPicker.vue'
import GoodsDetailEditor from '../../components/GoodsDetailEditor.vue'
import GoodsSpecEditor from '../../components/GoodsSpecEditor.vue'
import type { GoodsSpecGroup, GoodsSkuOption } from '../../api/types'

// 商户视图：bid 有值；自营视图：bid 无值
const props = withDefaults(defineProps<{ bid?: string }>(), { bid: '' })
const isMerchant = computed(() => !!props.bid)

/* ================= 列表 ================= */
const loading = ref(false)
const tableData = ref<CycleProductRow[]>([])
const total = ref(0)
const selectedRows = ref<CycleProductRow[]>([])

const query = reactive({
  page: 1,
  pagesize: 10,
  keyword: '',
  status: '' as number | '',
  checked: '' as number | '',
  cid: '' as number | '',
  orderField: '',
  orderType: 'desc' as 'asc' | 'desc' | '',
})

const categories = ref<{ id: number; name: string }[]>([])

async function fetchCategories() {
  try {
    const data = await getCycleCategoryList()
    categories.value = data.list || []
  } catch {
    /* 拦截器统一提示 */
  }
}

function handleSelectionChange(rows: CycleProductRow[]) {
  selectedRows.value = rows
}

function handleSortChange({ prop, order }: { prop: string; order: string | null }) {
  query.orderField = order ? prop : ''
  query.orderType = order === 'ascending' ? 'asc' : 'desc'
  query.page = 1
  fetchList()
}

async function fetchList() {
  loading.value = true
  try {
    const data = await getCycleProductList({
      page: query.page,
      pagesize: query.pagesize,
      keyword: query.keyword || undefined,
      status: query.status === '' ? undefined : query.status,
      checked: isMerchant.value && query.checked !== '' ? query.checked : undefined,
      cid: query.cid === '' ? undefined : query.cid,
      bid: props.bid || undefined,
      orderField: query.orderField || undefined,
      orderType: query.orderField ? query.orderType : undefined,
    })
    tableData.value = (data.list || []) as CycleProductRow[]
    total.value = data.total || 0
  } catch {
    /* 拦截器统一提示 */
  } finally {
    loading.value = false
  }
}

function handleSearch() {
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

function checkState(row: CycleProductRow): { text: string; cls: string } {
  if (row.ischecked === 1) return { text: '已通过', cls: 'status-on' }
  if (row.ischecked === 2) return { text: '已驳回', cls: 'check-pending' }
  return { text: '待审核', cls: 'check-pending' }
}

/* ================= 行操作 ================= */
function handleRowStatus(row: CycleProductRow) {
  const target = row.status === 1 ? 0 : 1
  const tip = target === 1 ? '上架' : '下架'
  ElMessageBox.confirm(`确定要${tip}商品「${row.name}」吗？`, `${tip}确认`, {
    confirmButtonText: tip,
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await setCycleProductStatus([row.id], target)
        row.status = target
        ElMessage.success(`已${tip}`)
      } catch {
        /* 拦截器统一提示 */
      }
    })
    .catch(() => {})
}

function handleDelete(row: CycleProductRow) {
  ElMessageBox.confirm(`确定要删除商品「${row.name}」吗？删除后不可恢复。`, '删除确认', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await deleteCycleProduct(row.id)
        ElMessage.success('删除成功')
        fetchList()
      } catch {
        /* 拦截器统一提示 */
      }
    })
    .catch(() => {})
}

function handleBatchDelete() {
  if (!selectedRows.value.length) return
  ElMessageBox.confirm(`确定要删除选中的 ${selectedRows.value.length} 个商品吗？删除后不可恢复。`, '批量删除', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await deleteCycleProduct(selectedRows.value.map((r) => r.id))
        ElMessage.success('删除成功')
        fetchList()
      } catch {
        /* 拦截器统一提示 */
      }
    })
    .catch(() => {})
}

function handleBatchStatus(status: number) {
  if (!selectedRows.value.length) return
  const tip = status === 1 ? '上架' : '下架'
  ElMessageBox.confirm(`确定要${tip}选中的 ${selectedRows.value.length} 个商品吗？`, `批量${tip}`, {
    confirmButtonText: tip,
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await setCycleProductStatus(selectedRows.value.map((r) => r.id), status)
        ElMessage.success(`已${tip}`)
        fetchList()
      } catch {
        /* 拦截器统一提示 */
      }
    })
    .catch(() => {})
}

function handleCheck(row: CycleProductRow, pass: boolean) {
  if (pass) {
    ElMessageBox.confirm(`确定通过「${row.name}」的审核吗？`, '审核通过', {
      confirmButtonText: '通过',
      cancelButtonText: '取消',
      type: 'success',
    })
      .then(async () => {
        try {
          await checkCycleProduct(row.id, 1)
          ElMessage.success('审核通过')
          fetchList()
        } catch {
          /* 拦截器统一提示 */
        }
      })
      .catch(() => {})
    return
  }
  ElMessageBox.prompt('请输入驳回原因', '驳回审核', {
    confirmButtonText: '驳回',
    cancelButtonText: '取消',
    inputPlaceholder: '请输入驳回原因',
    inputValidator: (v) => (v && v.trim() ? true : '驳回原因不能为空'),
  })
    .then(async ({ value }) => {
      try {
        await checkCycleProduct(row.id, 2, value.trim())
        ElMessage.success('已驳回')
        fetchList()
      } catch {
        /* 拦截器统一提示 */
      }
    })
    .catch(() => {})
}

/* ================= 编辑视图 ================= */
const viewMode = ref<'list' | 'edit'>('list')
const activeTab = ref<'info' | 'detail'>('info')
const isEdit = ref(false)
const submitting = ref(false)
const formRef = ref<FormInstance>()

// 每日一期配送日选项
const EVERYDAY_OPTIONS = [
  { value: 1, label: '每天配送' },
  { value: 2, label: '工作日配送' },
  { value: 3, label: '周末配送' },
  { value: 4, label: '隔天配送' },
]

const form = reactive({
  id: 0,
  name: '',
  pic: '',
  pics: [] as string[],
  cid: '' as number | '',
  // 第一部分
  commissionset: 0 as number,
  // 第二部分
  ps_cycle: 1 as number,
  everyday_item: [1] as number[],
  advance_pay_days: 0 as number,
  advance_pay_time: 0 as number,
  advance_extend_days: 0 as number,
  min_num: 1 as number,
  min_qsnum: 1 as number,
  freighttype: 1 as number, // 1全部模板 2选择模板
  sales: 0 as number,
  sort: 0 as number,
  status: 1 as number,
  detail: '',
  detailModules: [] as DesignerModule[],
})

// 规格分组/规格名称定义（共享规格编辑器，guigedata）
const specEditorRef = ref<InstanceType<typeof GoodsSpecEditor> | null>(null)
const specGroups = ref<GoodsSpecGroup[]>([{ title: '规格', items: [{ title: '默认规格' }] }])
// SKU 映射（cycle_guige，按笛卡尔组合 ks）
let skuMap = reactive<Record<string, GoodsSkuOption>>({})
const defaultSpecSku = { sell_price: 0, stock: 100 }

function onSpecPickImage(_current: string, done: (url: string) => void) {
  picSetter = (url: string) => {
    if (url) done(url)
  }
  singlePickerVisible.value = true
}
// 配送模板行（freighttype=2 时展示）
const freightTpls = ref<{ id: string; name: string }[]>([])

const rules = computed<FormRules>(() => ({
  name: [{ required: true, message: '请输入商品名称', trigger: 'blur' }],
  cid: [{ required: true, message: '请选择商品分类', trigger: 'change' }],
}))

function defaultDetailModules(): DesignerModule[] {
  return [defaultModule('richtext')]
}

function addFreightTpl() {
  freightTpls.value.push({ id: '', name: '' })
}
function removeFreightTpl(i: number) {
  freightTpls.value.splice(i, 1)
}

function resetForm() {
  form.id = 0
  form.name = ''
  form.pic = ''
  form.pics = []
  form.cid = ''
  form.commissionset = 0
  form.ps_cycle = 1
  form.everyday_item = [1]
  form.advance_pay_days = 0
  form.advance_pay_time = 0
  form.advance_extend_days = 0
  form.min_num = 1
  form.min_qsnum = 1
  form.freighttype = 1
  form.sales = 0
  form.sort = 0
  form.status = 1
  form.detail = ''
  form.detailModules = defaultDetailModules()
  specGroups.value = [{ title: '规格', items: [{ title: '默认规格' }] }]
  Object.keys(skuMap).forEach((k) => delete skuMap[k])
  freightTpls.value = []
}

function openAdd() {
  isEdit.value = false
  resetForm()
  activeTab.value = 'info'
  viewMode.value = 'edit'
}

async function openEdit(row: CycleProductRow) {
  isEdit.value = true
  resetForm()
  activeTab.value = 'info'
  viewMode.value = 'edit'
  try {
    const d = (await getCycleProductDetail(row.id)) as CycleProductRow
    form.id = d.id
    form.name = d.name || ''
    form.pic = d.pic || ''
    form.pics = d.pics ? String(d.pics).split(',').filter(Boolean) : []
    form.cid = d.cid || ''
    form.commissionset = Number(d.commissionset ?? 0)
    form.ps_cycle = Number(d.ps_cycle ?? 1)
    // everyday_item JSON → number[]
    let everyday: number[] = []
    if (d.everyday_item) {
      try {
        const parsed = JSON.parse(d.everyday_item)
        if (Array.isArray(parsed)) everyday = parsed.map((x: any) => Number(x.value)).filter((v: number) => !!v)
      } catch {
        everyday = []
      }
    }
    form.everyday_item = everyday.length ? everyday : [1]
    form.advance_pay_days = Number(d.advance_pay_days ?? 0)
    form.advance_pay_time = Number(d.advance_pay_time ?? 0)
    form.advance_extend_days = Number(d.advance_extend_days ?? 0)
    form.min_num = Number(d.min_num ?? 1)
    form.min_qsnum = Number(d.min_qsnum ?? 1)
    form.freighttype = Number(d.freighttype ?? 1) || 1
    form.sales = Number(d.sales ?? 0)
    form.sort = Number(d.sort ?? 0)
    form.status = d.status ?? 1
    // freightdata → 模板行
    if (form.freighttype === 2 && d.freightdata) {
      freightTpls.value = String(d.freightdata)
        .split(',')
        .filter(Boolean)
        .map((id) => ({ id, name: '' }))
    }
    // 详情装修
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
    // 规格分组（guigedata: [{group, values:[...]}]）→ 共享规格编辑器 groups
    let gd: any[] = []
    if (d.guigedata) {
      try {
        const parsed = JSON.parse(d.guigedata)
        if (Array.isArray(parsed)) gd = parsed
      } catch {
        gd = []
      }
    }
    if (gd.length) {
      specGroups.value = gd
        .filter((g: any) => g && (g.group || g.title))
        .map((g: any) => {
          const values: string[] = g.values && g.values.length
            ? g.values.map((v: unknown) => String(v))
            : (g.items || []).map((it: any) => String(it && it.title))
          return { title: String(g.group || g.title || '规格'), items: values.map((v) => ({ title: v })) }
        })
        .filter((g: GoodsSpecGroup) => g.items.length > 0)
    }
    // 规格项目表（guige_list）→ 按笛卡尔名称组合回填 skuMap
    const glist = (d.guige_list || []) as any[]
    const byName = new Map<string, any>()
    glist.forEach((g) => byName.set(String(g.name || ''), g))
    await nextTick()
    for (const { names, sku } of specEditorRef.value?.rowsToSkuList() || []) {
      const src = byName.get(names.join(' '))
      if (src) {
        sku.procode = String(src.procode || '')
        sku.market_price = Number(src.market_price || 0)
        sku.sell_price = Number(src.sell_price || 0)
        sku.weight = Number(src.weight || 0)
        sku.stock = Number(src.stock || 0)
        sku.pic = String(src.pic || '')
        sku.name = names.join(' ')
      }
    }
  } catch {
    viewMode.value = 'list'
  }
}

function backToList() {
  viewMode.value = 'list'
  fetchList()
}

async function submitForm() {
  if (!formRef.value) return
  await formRef.value.validate(async (valid) => {
    if (!valid) return
    const skuRowsOut = specEditorRef.value?.rowsToSkuList() || []
    if (!skuRowsOut.length) {
      ElMessage.warning('请至少设置一条规格')
      return
    }
    // 复用组件校验：多规格逐行校验销售价，提示会点名具体规格组合（如 规格「黑 6」未填写销售价）
    if (specEditorRef.value?.buildMultiSpecPayload({}) === false) {
      return
    }
    // 单规格兜底（组件在单规格下跳过逐行校验；本页无主表单价格，价格全在 SKU 行）
    const badRow = skuRowsOut.find(({ sku }) => !(Number(sku.sell_price) > 0))
    if (badRow) {
      ElMessage.warning(`规格「${badRow.names.join(' ')}」未填写销售价`)
      return
    }
    if (form.ps_cycle === 1 && !form.everyday_item.length) {
      ElMessage.warning('每日一期请至少勾选一个配送日')
      return
    }
    submitting.value = true
    try {
      await saveCycleProduct({
        id: form.id || undefined,
        name: form.name,
        pic: form.pic,
        pics: form.pics.join(','),
        cid: form.cid === '' ? undefined : Number(form.cid),
        commissionset: form.commissionset,
        ps_cycle: form.ps_cycle,
        everyday_item: form.ps_cycle === 1 ? form.everyday_item : [],
        advance_pay_days: Number(form.advance_pay_days) || 0,
        advance_pay_time: Number(form.advance_pay_time) || 0,
        advance_extend_days: Number(form.advance_extend_days) || 0,
        min_num: Number(form.min_num) || 1,
        min_qsnum: Number(form.min_qsnum) || 1,
        freighttype: form.freighttype,
        freightdata: form.freighttype === 2 ? freightTpls.value.map((t) => t.id).filter(Boolean).join(',') : '',
        sales: Number(form.sales) || 0,
        sort: Number(form.sort) || 0,
        status: form.status,
        detail: JSON.stringify(form.detailModules || []),
        guigedata: JSON.stringify(
          specGroups.value
            .filter((g) => g.title.trim() && g.items.some((it) => it.title.trim()))
            .map((g) => ({ group: g.title.trim(), values: g.items.map((it) => it.title.trim()) })),
        ),
        guiges: skuRowsOut.map(({ names, sku }) => ({
          name: names.join(' '),
          procode: sku.procode ?? '',
          market_price: Number(sku.market_price) || 0,
          sell_price: Number(sku.sell_price) || 0,
          weight: Number(sku.weight) || 0,
          stock: Number(sku.stock) || 0,
          pic: sku.pic ?? '',
        })),
      })
      ElMessage.success('保存成功')
      backToList()
    } catch {
      /* 拦截器统一提示 */
    } finally {
      submitting.value = false
    }
  })
}

/* ================= 选图弹窗 ================= */
const singlePickerVisible = ref(false)
const multiPickerVisible = ref(false)
let picSetter: ((url: string) => void) | null = null
function pickMain(urls: string[]) {
  if (urls[0]) form.pic = urls[0]
}
function pickMulti(urls: string[]) {
  urls.forEach((u) => {
    if (u && !form.pics.includes(u)) form.pics.push(u)
  })
}
function onSinglePick(urls: string[]) {
  if (picSetter) {
    picSetter(urls[0] || '')
    picSetter = null
  } else {
    pickMain(urls)
  }
}

/* ================= 选择商城商品 ================= */
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
    const d = (await getGoodsList({ page: choosePage.value, pagesize: 10, keyword: chooseKeyword.value || undefined, status: 1 } as never)) as unknown as { list: any[]; total: number }
    chooseList.value = (d.list || []).map((g) => ({
      id: Number(g.id),
      name: String(g.name),
      image: String(g.image || g.pic || ''),
      price: Number(g.price ?? g.sell_price ?? 0),
    }))
    chooseTotal.value = d.total || 0
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
  if (g.image && !form.pics.includes(g.image)) form.pics.unshift(g.image)
  chooseVisible.value = false
}

onMounted(() => {
  fetchCategories()
  fetchList()
})
</script>

<template>
  <div v-if="viewMode === 'list'">
    <!-- 工具栏与搜索同一行 -->
    <el-card class="toolbar" shadow="never">
      <div class="toolbar-left">
        <el-button v-if="!isMerchant" type="success" @click="openAdd">添加</el-button>
        <el-button @click="handleBatchDelete">删除</el-button>
        <el-button @click="handleBatchStatus(1)">上架</el-button>
        <el-button @click="handleBatchStatus(0)">下架</el-button>
      </div>
      <el-form :inline="true" class="toolbar-search" @submit.prevent>
        <el-form-item label="商品名称">
          <el-input v-model="query.keyword" placeholder="商品名称" clearable style="width: 130px" @keyup.enter="handleSearch" />
        </el-form-item>
        <el-form-item label="分类">
          <el-select v-model="query.cid" placeholder="全部" clearable style="width: 110px">
            <el-option v-for="c in categories" :key="c.id" :label="c.name" :value="c.id" />
          </el-select>
        </el-form-item>
        <el-form-item label="状态">
          <el-select v-model="query.status" placeholder="全部" clearable style="width: 100px">
            <el-option label="已上架" :value="1" />
            <el-option label="未上架" :value="0" />
          </el-select>
        </el-form-item>
        <el-form-item v-if="isMerchant" label="审核状态">
          <el-select v-model="query.checked" placeholder="全部" clearable style="width: 100px">
            <el-option label="待审核" :value="0" />
            <el-option label="已通过" :value="1" />
            <el-option label="已驳回" :value="2" />
          </el-select>
        </el-form-item>
        <el-form-item>
          <el-button type="success" class="search-icon-btn" @click="handleSearch">
            <svg viewBox="0 0 1024 1024" width="16" height="16" fill="currentColor">
              <path d="M795.904 750.72l124.928 124.928a32 32 0 0 1-45.248 45.248L750.656 795.904a416 416 0 1 1 45.248-45.248zM480 832a352 352 0 1 0 0-704 352 352 0 0 0 0 704z" />
            </svg>
          </el-button>
        </el-form-item>
      </el-form>
    </el-card>

    <!-- 表格 -->
    <el-card shadow="never">
      <el-table v-loading="loading" :data="tableData" border @selection-change="handleSelectionChange" @sort-change="handleSortChange">
        <el-table-column type="selection" width="40" align="center" />
        <el-table-column prop="id" label="ID" width="70" sortable="custom" align="center" />
        <el-table-column label="商品信息" min-width="240">
          <template #default="{ row }">
            <div class="goods-info">
              <el-image v-if="row.pic" :src="row.pic" fit="cover" class="goods-info-img" />
              <div v-else class="goods-info-img goods-info-img-empty">无图</div>
              <div class="goods-info-meta">
                <div class="goods-info-name">
                  <span v-if="row.cname" class="goods-info-cat">[{{ row.cname }}]</span>{{ row.name }}
                </div>
                <div class="goods-info-price">¥{{ row.sell_price }}</div>
              </div>
            </div>
          </template>
        </el-table-column>
        <el-table-column prop="stock" label="库存" width="80" align="center" />
        <el-table-column prop="sales" label="销量" width="80" sortable="custom" align="center" />
        <el-table-column prop="sort" label="序号" width="70" sortable="custom" align="center" />
        <el-table-column label="创建时间" width="150" sortable="custom" align="center">
          <template #default="{ row }">{{ formatDate(row.createtime) }}</template>
        </el-table-column>
        <el-table-column label="状态" width="90" align="center">
          <template #default="{ row }">
            <span :class="row.status === 1 ? 'status-on' : 'status-off'">{{ row.status === 1 ? '已上架' : '未上架' }}</span>
          </template>
        </el-table-column>
        <el-table-column v-if="isMerchant" label="审核状态" width="86" align="center">
          <template #default="{ row }">
            <span :class="checkState(row).cls" :title="row.ischecked === 2 ? row.check_reason : ''">{{ checkState(row).text }}</span>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="120">
          <template #default="{ row }">
            <div class="row-ops">
              <el-button link type="primary" @click="openEdit(row)">编辑</el-button>
              <el-button link type="danger" @click="handleDelete(row)">删除</el-button>
              <el-button link :type="row.status === 1 ? 'warning' : 'success'" @click="handleRowStatus(row)">
                {{ row.status === 1 ? '下架' : '上架' }}
              </el-button>
              <template v-if="isMerchant">
                <el-button v-if="row.ischecked === 0 || row.ischecked === 2" link type="success" @click="handleCheck(row, true)">通过</el-button>
                <el-button v-if="row.ischecked === 0 || row.ischecked === 1" link type="warning" @click="handleCheck(row, false)">驳回</el-button>
              </template>
            </div>
          </template>
        </el-table-column>
      </el-table>
      <el-pagination
        class="pagination"
        :current-page="query.page"
        :page-size="query.pagesize"
        :total="total"
        layout="total, sizes, prev, pager, next, jumper"
        @current-change="handlePageChange"
        @size-change="handleSizeChange"
      />
    </el-card>

    <ImgIconPicker v-model:visible="singlePickerVisible" default-tab="library" @pick="onSinglePick" />
    <ImgIconPicker v-model:visible="multiPickerVisible" default-tab="library" multi @pick="pickMulti" />

    <!-- 选择商城商品 -->
    <el-dialog v-model="chooseVisible" title="选择商城商品" width="760px">
      <div class="choose-bar">
        <el-input v-model="chooseKeyword" placeholder="输入商品名称搜索" clearable style="width: 240px" @keyup.enter="handleChooseSearch" />
        <el-button type="primary" @click="handleChooseSearch">搜索</el-button>
      </div>
      <el-table v-loading="chooseLoading" :data="chooseList" size="small" border style="margin-top: 10px">
        <el-table-column label="商品" min-width="280">
          <template #default="{ row }">
            <div class="choose-goods">
              <el-image v-if="row.image" :src="row.image" fit="cover" class="choose-img" />
              <div class="choose-goods-name">{{ row.name }}</div>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="价格" width="100" align="center">
          <template #default="{ row }">¥{{ row.price }}</template>
        </el-table-column>
        <el-table-column label="操作" width="90" align="center">
          <template #default="{ row }">
            <el-button type="primary" link @click="pickGoods(row)">选择</el-button>
          </template>
        </el-table-column>
      </el-table>
      <el-pagination
        class="pagination"
        small
        :current-page="choosePage"
        :page-size="10"
        :total="chooseTotal"
        layout="prev, pager, next"
        @current-change="(p: number) => { choosePage = p; fetchChoose() }"
      />
    </el-dialog>
  </div>

  <!-- 平铺编辑主区域 + 右上X -->
  <div v-else class="goods-edit">
    <div class="ge-head">
      <div class="ge-head-title">{{ isEdit ? '编辑商品' : '添加商品' }}</div>
      <div class="ge-head-close" @click="backToList">
        <svg viewBox="0 0 1024 1024" width="18" height="18" fill="currentColor">
          <path d="M764.288 214.595 512 466.883 259.712 214.595a32 32 0 0 0-45.248 45.248L466.752 512 214.464 764.288a32 32 0 1 0 45.248 45.248L512 557.117l252.288 252.419a32 32 0 0 0 45.248-45.248L557.248 512l252.288-252.157a32 32 0 0 0-45.248-45.248z" />
        </svg>
      </div>
    </div>

    <el-tabs v-model="activeTab" class="ge-tabs">
      <!-- 商品基本信息 -->
      <el-tab-pane label="商品基本信息" name="info">
        <el-form ref="formRef" :model="form" :rules="rules" label-width="120px" class="ge-form">
          <el-form-item label="商品名称" required>
            <el-input v-model="form.name" class="field-md" maxlength="60" />
            <el-button style="margin-left: 10px" @click="openChoose">选择商城商品</el-button>
          </el-form-item>

          <el-form-item label="商品主图" required>
            <el-button @click="singlePickerVisible = true">上传图片</el-button>
            <span class="ge-tip">建议尺寸：640×640像素</span>
            <div v-if="form.pic" class="ge-upload-box">
              <el-image :src="form.pic" fit="cover" class="main-pic" />
              <span class="ge-upload-del" @click="form.pic = ''">×</span>
            </div>
            <div v-else class="ge-upload-box">
              <div class="ge-upload-empty"></div>
            </div>
          </el-form-item>

          <el-form-item label="商品图片">
            <el-button @click="multiPickerVisible = true">批量上传</el-button>
            <span class="ge-tip">建议尺寸：640×640像素</span>
            <div class="ge-thumb-row">
              <div v-for="(url, i) in form.pics" :key="url" class="ge-thumb-item">
                <el-image :src="url" fit="cover" class="pic-thumb" />
                <span class="ge-upload-del" @click="form.pics.splice(i, 1)">×</span>
              </div>
            </div>
          </el-form-item>

          <el-form-item label="商品分类" required>
            <el-select v-model="form.cid" placeholder="-请选择-" class="field-md">
              <el-option v-for="c in categories" :key="c.id" :label="c.name" :value="c.id" />
            </el-select>
          </el-form-item>

          <!-- 设置规格（共享规格编辑器，笛卡尔积自动生成规格项目表） -->
          <el-form-item label="设置规格">
            <div class="ge-spec spec-table-auto">
              <GoodsSpecEditor
                ref="specEditorRef"
                v-model:groups="specGroups"
                v-model:skus="skuMap"
                :default-sku="defaultSpecSku"
                :show-cost-price="false"
                :show-givescore="false"
                @pick-image="onSpecPickImage"
              />
            </div>
          </el-form-item>

          <!-- 会员等级折扣 -->
          <el-form-item label="会员等级折扣">
            <el-radio-group v-model="form.commissionset">
              <el-radio :value="0">跟随系统</el-radio>
              <el-radio :value="1">参与</el-radio>
              <el-radio :value="2">不参与</el-radio>
            </el-radio-group>
            <span class="ge-tip">跟随系统：周期购-系统设置-会员等级折扣</span>
          </el-form-item>

          <!-- 第二部分 -->
          <el-form-item label="配送周期">
            <el-radio-group v-model="form.ps_cycle">
              <el-radio :value="1">每日一期</el-radio>
              <el-radio :value="2">每周一期</el-radio>
              <el-radio :value="3">每月一期</el-radio>
            </el-radio-group>
          </el-form-item>
          <el-form-item v-if="form.ps_cycle === 1" label="配送日">
            <el-checkbox-group v-model="form.everyday_item">
              <el-checkbox v-for="o in EVERYDAY_OPTIONS" :key="o.value" :value="o.value">{{ o.label }}</el-checkbox>
            </el-checkbox-group>
          </el-form-item>

          <el-form-item label="提前支付" required>
            <span class="ge-inline">买家需提前</span>
            <el-input-number v-model="form.advance_pay_days" :min="0" :precision="0" :controls="false" style="width: 90px" />
            <span class="ge-inline">天支付，并在</span>
            <el-input-number v-model="form.advance_pay_time" :min="0" :max="23" :precision="0" :controls="false" style="width: 80px" />
            <span class="ge-inline">时前下单支付，才能在最近的配送时间配送</span>
          </el-form-item>

          <el-form-item label="提前顺延" required>
            <span class="ge-inline">买家需提前</span>
            <el-input-number v-model="form.advance_extend_days" :min="0" :precision="0" :controls="false" style="width: 90px" />
            <span class="ge-inline">天顺延</span>
          </el-form-item>

          <el-form-item label="最小配送期数" required>
            <el-input-number v-model="form.min_num" :min="1" :precision="0" :controls="false" style="width: 120px" />
            <span class="ge-inline">期</span>
          </el-form-item>

          <el-form-item label="每期起订数" required>
            <el-input-number v-model="form.min_qsnum" :min="1" :precision="0" :controls="false" style="width: 120px" />
            <span class="ge-inline">件</span>
          </el-form-item>

          <el-form-item label="配送模板">
            <el-radio-group v-model="form.freighttype">
              <el-radio :value="1">全部模板</el-radio>
              <el-radio :value="2">选择模板</el-radio>
            </el-radio-group>
          </el-form-item>
          <el-form-item v-if="form.freighttype === 2" label=" ">
            <div class="ge-tpl spec-table-auto">
              <table class="spec-item-table">
                <thead><tr><th>模板ID</th><th>模板名称</th><th style="width: 60px">操作</th></tr></thead>
                <tbody>
                  <tr v-for="(t, i) in freightTpls" :key="i">
                    <td><el-input v-model="t.id" size="small" style="width: 120px" /></td>
                    <td><el-input v-model="t.name" size="small" style="width: 200px" /></td>
                    <td><el-button size="small" link type="danger" @click="removeFreightTpl(i)">删除</el-button></td>
                  </tr>
                </tbody>
              </table>
              <el-button size="small" style="margin-top: 8px" @click="addFreightTpl">添加</el-button>
            </div>
          </el-form-item>

          <el-form-item label="销量">
            <el-input-number v-model="form.sales" :min="0" :precision="0" :controls="false" class="field-sm" />
          </el-form-item>

          <el-form-item label="序号">
            <el-input-number v-model="form.sort" :min="0" :precision="0" :controls="false" class="field-sm" />
            <span class="ge-tip">用于排序，越大越靠前</span>
          </el-form-item>

          <el-form-item label="状态">
            <el-radio-group v-model="form.status">
              <el-radio :value="1">已上架</el-radio>
              <el-radio :value="0">未上架</el-radio>
            </el-radio-group>
          </el-form-item>

          <div class="tab-footer">
            <el-button type="primary" @click="activeTab = 'detail'">下一步：编辑商品详情</el-button>
            <el-button type="warning" :loading="submitting" @click="submitForm">提交</el-button>
          </div>
        </el-form>
      </el-tab-pane>

      <!-- 商品详情（DIY 装修） -->
      <el-tab-pane label="商品详情" name="detail">
        <GoodsDetailEditor v-model="form.detailModules" title="商品详情" />
        <div class="tab-footer">
          <el-button type="primary" @click="activeTab = 'info'">上一步：编辑商品信息</el-button>
          <el-button type="warning" :loading="submitting" @click="submitForm">提交</el-button>
        </div>
      </el-tab-pane>
    </el-tabs>

    <ImgIconPicker v-model:visible="singlePickerVisible" default-tab="library" @pick="onSinglePick" />
    <ImgIconPicker v-model:visible="multiPickerVisible" default-tab="library" multi @pick="pickMulti" />
  </div>
</template>

<style scoped>
.toolbar { margin-bottom: 3px; }
.toolbar :deep(.el-card__body) { padding: 10px 14px; display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: nowrap; }
.toolbar-left { display: flex; align-items: center; gap: 8px; flex: 0 0 auto; }
.toolbar-left .el-button { margin: 0; }
.toolbar-search { display: flex; align-items: center; flex: 1 1 auto; justify-content: flex-end; flex-wrap: nowrap; min-width: 0; margin: 0; }
.toolbar-search :deep(.el-form-item) { margin-bottom: 0; margin-right: 8px; }
.toolbar-search :deep(.el-form-item:last-child) { margin-right: 0; }
.toolbar-search :deep(.el-form-item__label) { padding-right: 6px; }
.search-icon-btn { padding: 8px 14px; }
.search-icon-btn svg { display: block; }

.goods-info { display: flex; align-items: center; gap: 10px; }
.goods-info-img { width: 60px; height: 60px; border-radius: 4px; border: 1px solid #ebeef5; flex-shrink: 0; }
.goods-info-img-empty { display: flex; align-items: center; justify-content: center; color: #c0c4cc; font-size: 12px; background: #fafafa; }
.goods-info-meta { min-width: 0; flex: 1; }
.goods-info-name { color: #303133; font-size: 13px; line-height: 1.5; word-break: break-all; }
.goods-info-cat { color: #f56c6c; margin-right: 4px; }
.goods-info-price { color: #ff6600; font-weight: 600; font-size: 14px; margin-top: 6px; }

.status-on { color: #21ba45; }
.status-off { color: #f56c6c; }
.check-pending { color: #409eff; }

.row-ops { display: flex; flex-direction: column; align-items: flex-start; gap: 2px; line-height: 1.5; }
.row-ops .el-button { margin: 0; height: auto; min-height: 0; padding: 0; font-size: 12px; }
.pagination { margin-top: 16px; justify-content: flex-end; }

/* 编辑 */
.goods-edit { background: #fff; border-radius: 6px; padding: 16px 20px; }
.ge-head { display: flex; align-items: center; gap: 14px; margin-bottom: 12px; }
.ge-head-title { font-size: 16px; font-weight: 600; color: #303133; flex: 1; }
.ge-head-close { cursor: pointer; color: #909399; display: flex; align-items: center; }
.ge-head-close:hover { color: #409eff; }
.ge-tabs { margin-bottom: 8px; }
.ge-form :deep(.el-form-item) { margin-bottom: 18px; }
.ge-tip { color: #909399; font-size: 12px; margin-left: 8px; }
.ge-inline { color: #606266; margin: 0 4px; }
.ge-upload-box { margin-top: 8px; }
.ge-upload-empty { width: 80px; height: 80px; border: 1px solid #e4e7ed; background: #fafafa; }
.main-pic { width: 80px; height: 80px; border-radius: 4px; border: 1px solid #e4e7ed; object-fit: cover; }
.ge-upload-del { position: relative; margin-left: 6px; color: #f56c6c; cursor: pointer; font-size: 16px; }
.ge-thumb-row { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 8px; }
.ge-thumb-item { display: flex; flex-direction: column; align-items: center; }
.pic-thumb { width: 80px; height: 80px; border-radius: 4px; border: 1px solid #e4e7ed; object-fit: cover; }

.ge-spec { width: auto; }
.spec-group-table, .spec-item-table { width: auto; border-collapse: collapse; }
.spec-group-table th, .spec-group-table td,
.spec-item-table th, .spec-item-table td { border: 1px solid #ebeef5; padding: 6px 8px; font-size: 12px; text-align: left; }
.spec-group-table th, .spec-item-table th { background: #fafafa; color: #909399; font-weight: normal; }
.guige-pic { width: 40px; height: 40px; border-radius: 3px; border: 1px solid #e4e7ed; cursor: pointer; }
.ge-tpl { width: auto; }
.tab-footer { display: flex; justify-content: flex-start; gap: 14px; padding: 16px 0 8px; border-top: 1px solid #ebeef5; }

.choose-bar { display: flex; gap: 8px; align-items: center; }
.choose-goods { display: flex; align-items: center; gap: 10px; }
.choose-img { width: 44px; height: 44px; border-radius: 4px; border: 1px solid #ebeef5; }
.choose-goods-name { font-size: 13px; color: #303133; }
</style>
