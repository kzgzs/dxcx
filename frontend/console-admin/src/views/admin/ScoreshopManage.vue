<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import type { FormInstance, FormRules } from 'element-plus'
import {
  closeScoreshopOrder,
  deleteScoreshopCategory,
  deleteScoreshopProduct,
  getScoreshopCategoryList,
  getScoreshopOrderList,
  getScoreshopProductList,
  getScoreshopSet,
  saveScoreshopCategory,
  saveScoreshopProduct,
  saveScoreshopSet,
  setScoreshopProductStatus,
} from '../../api/scoreshop'
import type {
  ScoreshopCategoryItem,
  ScoreshopOrderItem,
  ScoreshopProductItem,
} from '../../api/scoreshop'
import { formatTime } from '../../utils/time'
import ImgIconPicker from '../../components/ImgIconPicker.vue'

const activeTab = ref('set')

// ============ 选图（ImgIconPicker 统一选图） ============
const imgPickerVisible = ref(false)
let imgPickerSetter: ((url: string) => void) | null = null
function openImgPicker(setter: (url: string) => void) {
  imgPickerSetter = setter
  imgPickerVisible.value = true
}
function onImgPick(urls: string[]) {
  if (urls[0]) imgPickerSetter?.(urls[0])
  imgPickerSetter = null
}

// ============ 设置 ============
const setForm = reactive({
  autoclose: 60,
  autoshdays: 7,
  gettjMode: '-1',
  gettjIds: '',
  gettjtip: '',
  gettjurl: '',
  comment: 1,
  comment_check: 1,
})

async function fetchSet() {
  try {
    const data = await getScoreshopSet()
    const gettj = String(data.gettj ?? '-1')
    Object.assign(setForm, {
      autoclose: data.autoclose ?? 60,
      autoshdays: data.autoshdays ?? 7,
      gettjMode: gettj === '-1' ? '-1' : 'level',
      gettjIds: gettj === '-1' ? '' : gettj,
      gettjtip: data.gettjtip ?? '',
      gettjurl: data.gettjurl ?? '',
      comment: data.comment ?? 1,
      comment_check: data.comment_check ?? 1,
    })
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  }
}

const setSaving = ref(false)
async function handleSetSave() {
  let gettj = '-1'
  if (setForm.gettjMode === 'level') {
    const ids = setForm.gettjIds.split(/[,，]/).map((s) => s.trim()).filter((s) => s !== '')
    if (ids.length === 0 || ids.some((s) => !/^\d+$/.test(s))) {
      ElMessage.warning('请填写有效的会员等级ID，逗号分隔')
      return
    }
    gettj = ids.join(',')
  }
  setSaving.value = true
  try {
    await saveScoreshopSet({
      autoclose: setForm.autoclose,
      autoshdays: setForm.autoshdays,
      gettj,
      gettjtip: setForm.gettjtip,
      gettjurl: setForm.gettjurl,
      comment: setForm.comment,
      comment_check: setForm.comment_check,
    })
    ElMessage.success('保存成功')
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    setSaving.value = false
  }
}

// ============ 分类 ============
const catLoading = ref(false)
const catList = ref<ScoreshopCategoryItem[]>([])
const catKeyword = ref('')

async function fetchCategories() {
  catLoading.value = true
  try {
    const data = await getScoreshopCategoryList(catKeyword.value)
    catList.value = data.list || []
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    catLoading.value = false
  }
}

const catDialogVisible = ref(false)
const catForm = reactive({ id: 0, name: '', pic: '', sort: 1, status: 1 })
const catSaving = ref(false)

function openCatAdd() {
  Object.assign(catForm, { id: 0, name: '', pic: '', sort: 1, status: 1 })
  catDialogVisible.value = true
}

function openCatEdit(row: ScoreshopCategoryItem) {
  Object.assign(catForm, { id: row.id, name: row.name, pic: row.pic, sort: row.sort, status: row.status })
  catDialogVisible.value = true
}

async function handleCatSave() {
  if (!catForm.name.trim()) {
    ElMessage.warning('请输入分类名称')
    return
  }
  catSaving.value = true
  try {
    await saveScoreshopCategory({
      id: catForm.id || undefined,
      name: catForm.name,
      pic: catForm.pic,
      sort: catForm.sort,
      status: catForm.status,
    })
    ElMessage.success('保存成功')
    catDialogVisible.value = false
    fetchCategories()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    catSaving.value = false
  }
}

function handleCatDelete(row: ScoreshopCategoryItem) {
  ElMessageBox.confirm(`确定删除分类「${row.name}」吗？`, '删除确认', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await deleteScoreshopCategory(row.id)
        ElMessage.success('删除成功')
        fetchCategories()
      } catch {
        // 错误提示已由 axios 拦截器统一弹出
      }
    })
    .catch(() => {})
}

// ============ 商品 ============
const proLoading = ref(false)
const proList = ref<ScoreshopProductItem[]>([])
const proTotal = ref(0)
const proQuery = reactive({
  page: 1,
  pagesize: 10,
  keyword: '',
  status: '' as number | '',
  cid: undefined as number | undefined,
})

async function fetchProducts() {
  proLoading.value = true
  try {
    const data = await getScoreshopProductList({
      page: proQuery.page,
      pagesize: proQuery.pagesize,
      keyword: proQuery.keyword || undefined,
      status: proQuery.status === '' ? undefined : proQuery.status,
      cid: proQuery.cid,
    })
    proList.value = data.list || []
    proTotal.value = data.total || 0
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    proLoading.value = false
  }
}

function handleProSearch() {
  proQuery.page = 1
  fetchProducts()
}

function handleProReset() {
  proQuery.keyword = ''
  proQuery.status = ''
  proQuery.cid = undefined
  proQuery.page = 1
  fetchProducts()
}

async function handleProStatusChange(row: ScoreshopProductItem) {
  try {
    await setScoreshopProductStatus(row.id, row.status)
    ElMessage.success(row.status === 1 ? '已上架' : '已下架')
  } catch {
    row.status = row.status === 1 ? 0 : 1
  }
}

function handleProDelete(row: ScoreshopProductItem) {
  ElMessageBox.confirm(`确定删除商品「${row.name}」吗？删除后不可恢复。`, '删除确认', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await deleteScoreshopProduct(row.id)
        ElMessage.success('删除成功')
        fetchProducts()
      } catch {
        // 错误提示已由 axios 拦截器统一弹出
      }
    })
    .catch(() => {})
}

// 商品抽屉
const proDrawerVisible = ref(false)
const proIsEdit = ref(false)
const proFormRef = ref<FormInstance>()
const proSubmitting = ref(false)

interface ProGuigeRow {
  id?: number
  name: string
  score_price: number
  money_price: number
  stock: number
}

const proForm = reactive({
  id: 0,
  cid: undefined as number | undefined,
  name: '',
  pic: '',
  detail: '',
  score_price: 0,
  money_price: 0,
  buymax: 0,
  sort: 0,
  status: 1,
})

const proGuigeRows = ref<ProGuigeRow[]>([])

const proRules: FormRules = {
  cid: [{ required: true, message: '请选择商品分类', trigger: 'change' }],
  name: [{ required: true, message: '请输入商品名称', trigger: 'blur' }],
}

function resetProForm() {
  Object.assign(proForm, {
    id: 0,
    cid: undefined,
    name: '',
    pic: '',
    detail: '',
    score_price: 0,
    money_price: 0,
    buymax: 0,
    sort: 0,
    status: 1,
  })
  proGuigeRows.value = [{ name: '默认', score_price: 0, money_price: 0, stock: 0 }]
}

function openProAdd() {
  proIsEdit.value = false
  resetProForm()
  proDrawerVisible.value = true
}

function openProEdit(row: ScoreshopProductItem) {
  proIsEdit.value = true
  resetProForm()
  Object.assign(proForm, {
    id: row.id,
    cid: row.cid,
    name: row.name,
    pic: row.pic,
    detail: '',
    score_price: row.score_price,
    money_price: Number(row.money_price),
    buymax: row.buymax,
    sort: row.sort,
    status: row.status,
  })
  proGuigeRows.value = (row.guige || []).map((g) => ({
    id: g.id,
    name: g.name,
    score_price: g.score_price,
    money_price: Number(g.money_price),
    stock: g.stock,
  }))
  if (proGuigeRows.value.length === 0) {
    proGuigeRows.value = [{
      name: '默认',
      score_price: row.score_price,
      money_price: Number(row.money_price),
      stock: row.stock,
    }]
  }
  proDrawerVisible.value = true
}

function addProGuigeRow() {
  proGuigeRows.value.push({
    name: '',
    score_price: proForm.score_price,
    money_price: proForm.money_price,
    stock: 0,
  })
}

function removeProGuigeRow(index: number) {
  proGuigeRows.value.splice(index, 1)
}

async function handleProSubmit() {
  if (!proFormRef.value) return
  await proFormRef.value.validate(async (valid) => {
    if (!valid) return
    if (proForm.score_price < 1 && proForm.money_price <= 0) {
      ElMessage.warning('请设置积分价或现金价')
      return
    }
    const rows = proGuigeRows.value.filter((g) => g.name.trim() !== '')
    if (rows.length === 0) {
      ElMessage.warning('请至少填写一条规格')
      return
    }
    proSubmitting.value = true
    try {
      await saveScoreshopProduct({
        id: proForm.id || undefined,
        cid: proForm.cid as number,
        name: proForm.name,
        pic: proForm.pic,
        detail: proForm.detail,
        score_price: proForm.score_price,
        money_price: proForm.money_price,
        buymax: proForm.buymax,
        sort: proForm.sort,
        status: proForm.status,
        guige: rows,
      })
      ElMessage.success('保存成功')
      proDrawerVisible.value = false
      fetchProducts()
    } catch {
      // 错误提示已由 axios 拦截器统一弹出
    } finally {
      proSubmitting.value = false
    }
  })
}

// ============ 兑换订单 ============
const orderLoading = ref(false)
const orderList = ref<ScoreshopOrderItem[]>([])
const orderTotal = ref(0)
const orderTab = ref('')
const orderQuery = reactive({
  page: 1,
  pagesize: 10,
  ordernum: '',
  linkman: '',
  tel: '',
  dateRange: null as [string, string] | null,
})

async function fetchOrders() {
  orderLoading.value = true
  try {
    const data = await getScoreshopOrderList({
      page: orderQuery.page,
      pagesize: orderQuery.pagesize,
      status: orderTab.value === '' ? undefined : Number(orderTab.value),
      ordernum: orderQuery.ordernum || undefined,
      linkman: orderQuery.linkman || undefined,
      tel: orderQuery.tel || undefined,
      start_date: orderQuery.dateRange ? orderQuery.dateRange[0] : undefined,
      end_date: orderQuery.dateRange ? orderQuery.dateRange[1] : undefined,
    })
    orderList.value = data.list || []
    orderTotal.value = data.total || 0
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    orderLoading.value = false
  }
}

function handleOrderSearch() {
  orderQuery.page = 1
  fetchOrders()
}

function handleOrderReset() {
  orderQuery.ordernum = ''
  orderQuery.linkman = ''
  orderQuery.tel = ''
  orderQuery.dateRange = null
  orderQuery.page = 1
  fetchOrders()
}

const orderStatusMap: Record<number, { text: string; type: 'info' | 'primary' | 'success' | 'warning' | 'danger' }> = {
  0: { text: '未支付', type: 'info' },
  1: { text: '已支付', type: 'primary' },
  2: { text: '已发货', type: 'warning' },
  3: { text: '已收货', type: 'success' },
  4: { text: '已关闭', type: 'danger' },
}

function handleOrderClose(row: ScoreshopOrderItem) {
  ElMessageBox.confirm(
    `确定要关闭兑换订单「${row.ordernum}」吗？关闭后将回补库存并退回积分，不可恢复。`,
    '关单确认',
    { confirmButtonText: '关闭订单', cancelButtonText: '取消', type: 'warning' },
  )
    .then(async () => {
      try {
        await closeScoreshopOrder(row.id)
        ElMessage.success('订单已关闭')
        fetchOrders()
      } catch {
        // 错误提示已由 axios 拦截器统一弹出
      }
    })
    .catch(() => {})
}

// ============ tab 切换懒加载 ============
function handleTabChange(tab: string | number) {
  if (tab === 'category' && catList.value.length === 0) fetchCategories()
  if (tab === 'product') {
    if (catList.value.length === 0) fetchCategories()
    if (proList.value.length === 0) fetchProducts()
  }
  if (tab === 'order') fetchOrders()
}

onMounted(fetchSet)
</script>

<template>
  <div>
    <el-card shadow="never">
      <el-tabs v-model="activeTab" @tab-change="handleTabChange">
        <!-- ============ 设置 ============ -->
        <el-tab-pane label="系统设置" name="set">
          <el-form label-width="130px" label-position="left" style="max-width: 620px">
            <el-form-item label="自动关单">
              <el-input-number v-model="setForm.autoclose" :min="1" :max="10080" :precision="0" :step="5" />
              <span class="form-tip">分钟，兑换下单后超时未支付自动关闭并回补库存</span>
            </el-form-item>
            <el-form-item label="自动收货天数">
              <el-input-number v-model="setForm.autoshdays" :min="1" :max="90" :precision="0" :step="1" />
              <span class="form-tip">发货后 N 天自动确认收货</span>
            </el-form-item>
            <el-form-item label="进入条件">
              <el-radio-group v-model="setForm.gettjMode">
                <el-radio value="-1">所有会员</el-radio>
                <el-radio value="level">指定会员等级</el-radio>
              </el-radio-group>
            </el-form-item>
            <el-form-item v-if="setForm.gettjMode === 'level'" label="会员等级ID">
              <el-input v-model="setForm.gettjIds" placeholder="如：1,2,3（逗号分隔）" />
            </el-form-item>
            <el-form-item label="不满足提示">
              <el-input v-model="setForm.gettjtip" placeholder="不满足条件时的提示语" />
            </el-form-item>
            <el-form-item label="跳转链接">
              <el-input v-model="setForm.gettjurl" placeholder="不满足条件时跳转的页面链接（选填）" />
            </el-form-item>
            <el-form-item label="允许评价">
              <el-switch v-model="setForm.comment" :active-value="1" :inactive-value="0" />
            </el-form-item>
            <el-form-item label="评价审核">
              <el-switch v-model="setForm.comment_check" :active-value="1" :inactive-value="0" />
              <span class="form-tip">开启后评价需审核通过才展示</span>
            </el-form-item>
            <el-form-item>
              <el-button type="primary" :loading="setSaving" @click="handleSetSave">保存设置</el-button>
            </el-form-item>
          </el-form>
        </el-tab-pane>

        <!-- ============ 分类 ============ -->
        <el-tab-pane label="商品分类" name="category">
          <el-form inline @submit.prevent>
            <el-form-item label="关键词">
              <el-input
                v-model="catKeyword"
                placeholder="分类名称"
                clearable
                style="width: 180px"
                @keyup.enter="fetchCategories"
              />
            </el-form-item>
            <el-form-item>
              <el-button type="primary" @click="fetchCategories">搜索</el-button>
            </el-form-item>
            <el-form-item style="float: right">
              <el-button type="success" @click="openCatAdd">新增分类</el-button>
            </el-form-item>
          </el-form>
          <el-table v-loading="catLoading" :data="catList" stripe>
            <el-table-column prop="id" label="ID" width="70" />
            <el-table-column prop="name" label="分类名称" min-width="160" />
            <el-table-column label="图标" width="80">
              <template #default="{ row }">
                <el-image
                  v-if="row.pic"
                  :src="row.pic"
                  fit="cover"
                  style="width: 36px; height: 36px; border-radius: 4px"
                />
                <span v-else class="text-muted">-</span>
              </template>
            </el-table-column>
            <el-table-column prop="goods_count" label="商品数" width="90" />
            <el-table-column prop="sort" label="排序" width="80" />
            <el-table-column label="状态" width="90">
              <template #default="{ row }">
                <el-tag :type="row.status === 1 ? 'success' : 'info'" size="small">
                  {{ row.status === 1 ? '显示' : '隐藏' }}
                </el-tag>
              </template>
            </el-table-column>
            <el-table-column label="创建时间" width="160">
              <template #default="{ row }">{{ formatTime(row.createtime) }}</template>
            </el-table-column>
            <el-table-column label="操作" width="140" fixed="right">
              <template #default="{ row }">
                <el-button link type="primary" @click="openCatEdit(row)">编辑</el-button>
                <el-button link type="danger" @click="handleCatDelete(row)">删除</el-button>
              </template>
            </el-table-column>
          </el-table>
        </el-tab-pane>

        <!-- ============ 商品 ============ -->
        <el-tab-pane label="商品管理" name="product">
          <el-form inline @submit.prevent>
            <el-form-item label="关键词">
              <el-input
                v-model="proQuery.keyword"
                placeholder="商品名称"
                clearable
                style="width: 180px"
                @keyup.enter="handleProSearch"
              />
            </el-form-item>
            <el-form-item label="分类">
              <el-select v-model="proQuery.cid" placeholder="全部" clearable style="width: 140px">
                <el-option v-for="c in catList" :key="c.id" :label="c.name" :value="c.id" />
              </el-select>
            </el-form-item>
            <el-form-item label="状态">
              <el-select v-model="proQuery.status" placeholder="全部" clearable style="width: 110px">
                <el-option label="上架" :value="1" />
                <el-option label="下架" :value="0" />
              </el-select>
            </el-form-item>
            <el-form-item>
              <el-button type="primary" @click="handleProSearch">搜索</el-button>
              <el-button @click="handleProReset">重置</el-button>
            </el-form-item>
            <el-form-item style="float: right">
              <el-button type="success" @click="openProAdd">新增商品</el-button>
            </el-form-item>
          </el-form>
          <el-table v-loading="proLoading" :data="proList" stripe>
            <el-table-column prop="id" label="ID" width="70" />
            <el-table-column label="商品" min-width="200">
              <template #default="{ row }">
                <div class="product-cell">
                  <el-image
                    v-if="row.pic"
                    :src="row.pic"
                    fit="cover"
                    style="width: 40px; height: 40px; border-radius: 4px"
                  />
                  <span class="product-name">{{ row.name }}</span>
                </div>
              </template>
            </el-table-column>
            <el-table-column prop="cname" label="分类" width="100" />
            <el-table-column label="兑换价" width="140">
              <template #default="{ row }">
                {{ row.score_price }}积分
                <span v-if="Number(row.money_price) > 0">+￥{{ Number(row.money_price).toFixed(2) }}</span>
              </template>
            </el-table-column>
            <el-table-column prop="stock" label="库存" width="80" />
            <el-table-column prop="sales" label="已兑" width="80" />
            <el-table-column label="上架" width="80">
              <template #default="{ row }">
                <el-switch
                  v-model="row.status"
                  :active-value="1"
                  :inactive-value="0"
                  @change="handleProStatusChange(row)"
                />
              </template>
            </el-table-column>
            <el-table-column label="操作" width="140" fixed="right">
              <template #default="{ row }">
                <el-button link type="primary" @click="openProEdit(row)">编辑</el-button>
                <el-button link type="danger" @click="handleProDelete(row)">删除</el-button>
              </template>
            </el-table-column>
          </el-table>
          <el-pagination
            class="pagination"
            background
            layout="total, sizes, prev, pager, next, jumper"
            :total="proTotal"
            :current-page="proQuery.page"
            :page-size="proQuery.pagesize"
            :page-sizes="[10, 20, 50, 100]"
            @current-change="(p: number) => { proQuery.page = p; fetchProducts() }"
            @size-change="(s: number) => { proQuery.pagesize = s; proQuery.page = 1; fetchProducts() }"
          />
        </el-tab-pane>

        <!-- ============ 兑换订单 ============ -->
        <el-tab-pane label="兑换订单" name="order">
          <el-tabs v-model="orderTab" @tab-change="() => { orderQuery.page = 1; fetchOrders() }">
            <el-tab-pane label="全部" name="" />
            <el-tab-pane label="未支付" name="0" />
            <el-tab-pane label="已支付" name="1" />
            <el-tab-pane label="已发货" name="2" />
            <el-tab-pane label="已收货" name="3" />
            <el-tab-pane label="已关闭" name="4" />
          </el-tabs>
          <el-form inline @submit.prevent>
            <el-form-item label="订单号">
              <el-input
                v-model="orderQuery.ordernum"
                placeholder="订单号"
                clearable
                style="width: 180px"
                @keyup.enter="handleOrderSearch"
              />
            </el-form-item>
            <el-form-item label="收货人">
              <el-input
                v-model="orderQuery.linkman"
                placeholder="收货人"
                clearable
                style="width: 130px"
                @keyup.enter="handleOrderSearch"
              />
            </el-form-item>
            <el-form-item label="手机号">
              <el-input
                v-model="orderQuery.tel"
                placeholder="手机号"
                clearable
                style="width: 140px"
                @keyup.enter="handleOrderSearch"
              />
            </el-form-item>
            <el-form-item label="下单时间">
              <el-date-picker
                v-model="orderQuery.dateRange"
                type="daterange"
                value-format="YYYY-MM-DD"
                range-separator="至"
                start-placeholder="开始日期"
                end-placeholder="结束日期"
                style="width: 240px"
              />
            </el-form-item>
            <el-form-item>
              <el-button type="primary" @click="handleOrderSearch">搜索</el-button>
              <el-button @click="handleOrderReset">重置</el-button>
            </el-form-item>
          </el-form>
          <el-table v-loading="orderLoading" :data="orderList" stripe>
            <el-table-column prop="ordernum" label="订单号" width="190" />
            <el-table-column label="兑换商品" min-width="220">
              <template #default="{ row }">
                <div v-for="(g, i) in row.goods" :key="i" class="order-product">
                  <el-image
                    v-if="g.pic"
                    :src="g.pic"
                    fit="cover"
                    style="width: 32px; height: 32px; border-radius: 4px"
                  />
                  <span class="product-name">{{ g.name }}</span>
                  <span class="text-muted">× {{ g.num }}</span>
                </div>
              </template>
            </el-table-column>
            <el-table-column label="买家" min-width="110">
              <template #default="{ row }">{{ row.nickname || `会员${row.mid}` }}</template>
            </el-table-column>
            <el-table-column label="消耗" width="140">
              <template #default="{ row }">
                {{ row.totalscore }}积分
                <span v-if="Number(row.totalmoney) > 0">+￥{{ Number(row.totalmoney).toFixed(2) }}</span>
              </template>
            </el-table-column>
            <el-table-column label="收货人" width="130">
              <template #default="{ row }">
                <div>{{ row.linkman }}</div>
                <div class="text-muted">{{ row.tel }}</div>
              </template>
            </el-table-column>
            <el-table-column label="状态" width="90">
              <template #default="{ row }">
                <el-tag :type="orderStatusMap[row.status]?.type || 'info'" size="small">
                  {{ orderStatusMap[row.status]?.text || '未知' }}
                </el-tag>
              </template>
            </el-table-column>
            <el-table-column label="下单时间" width="160">
              <template #default="{ row }">{{ formatTime(row.createtime) }}</template>
            </el-table-column>
            <el-table-column label="操作" width="120" fixed="right">
              <template #default="{ row }">
                <el-button v-if="row.status === 0" link type="danger" @click="handleOrderClose(row)">
                  关单
                </el-button>
                <span v-else class="text-muted">-</span>
              </template>
            </el-table-column>
          </el-table>
          <el-pagination
            class="pagination"
            background
            layout="total, sizes, prev, pager, next, jumper"
            :total="orderTotal"
            :current-page="orderQuery.page"
            :page-size="orderQuery.pagesize"
            :page-sizes="[10, 20, 50, 100]"
            @current-change="(p: number) => { orderQuery.page = p; fetchOrders() }"
            @size-change="(s: number) => { orderQuery.pagesize = s; orderQuery.page = 1; fetchOrders() }"
          />
        </el-tab-pane>
      </el-tabs>
    </el-card>

    <!-- 分类编辑弹窗 -->
    <el-dialog v-model="catDialogVisible" :title="catForm.id ? '编辑分类' : '新增分类'" width="480px">
      <el-form label-width="90px" label-position="left">
        <el-form-item label="分类名称">
          <el-input v-model="catForm.name" placeholder="请输入分类名称" />
        </el-form-item>
        <el-form-item label="分类图标">
          <el-image
            v-if="catForm.pic"
            :src="catForm.pic"
            fit="cover"
            style="width: 80px; height: 80px; border-radius: 4px; margin-right: 8px"
          />
          <el-button size="small" @click="openImgPicker((u) => (catForm.pic = u))">
            {{ catForm.pic ? '更换' : '选择图片' }}
          </el-button>
        </el-form-item>
        <el-form-item label="排序">
          <el-input-number v-model="catForm.sort" :min="0" :precision="0" :step="1" />
        </el-form-item>
        <el-form-item label="显示">
          <el-switch v-model="catForm.status" :active-value="1" :inactive-value="0" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="catDialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="catSaving" @click="handleCatSave">保存</el-button>
      </template>
    </el-dialog>

    <!-- 商品编辑抽屉 -->
    <el-drawer
      v-model="proDrawerVisible"
      :title="proIsEdit ? '编辑兑换商品' : '新增兑换商品'"
      size="640px"
    >
      <el-form
        ref="proFormRef"
        :model="proForm"
        :rules="proRules"
        label-width="100px"
        label-position="left"
      >
        <el-form-item label="商品分类" prop="cid">
          <el-select v-model="proForm.cid" placeholder="请选择分类" style="width: 240px">
            <el-option v-for="c in catList" :key="c.id" :label="c.name" :value="c.id" />
          </el-select>
        </el-form-item>
        <el-form-item label="商品名称" prop="name">
          <el-input v-model="proForm.name" placeholder="请输入商品名称" />
        </el-form-item>
        <el-form-item label="主图">
          <el-image
            v-if="proForm.pic"
            :src="proForm.pic"
            fit="cover"
            style="width: 80px; height: 80px; border-radius: 4px; margin-right: 8px"
          />
          <el-button size="small" @click="openImgPicker((u) => (proForm.pic = u))">
            {{ proForm.pic ? '更换' : '选择图片' }}
          </el-button>
        </el-form-item>
        <el-form-item label="积分价">
          <el-input-number v-model="proForm.score_price" :min="0" :precision="0" :step="10" />
          <span class="form-tip">积分，0 表示纯现金兑换</span>
        </el-form-item>
        <el-form-item label="现金价">
          <el-input-number v-model="proForm.money_price" :min="0" :precision="2" :step="1" />
          <span class="form-tip">元，0 表示纯积分兑换</span>
        </el-form-item>
        <el-form-item label="限购数量">
          <el-input-number v-model="proForm.buymax" :min="0" :precision="0" :step="1" />
          <span class="form-tip">0 不限购，每人累计兑换上限</span>
        </el-form-item>
        <el-form-item label="排序">
          <el-input-number v-model="proForm.sort" :min="0" :precision="0" :step="1" />
        </el-form-item>
        <el-form-item label="上架">
          <el-switch v-model="proForm.status" :active-value="1" :inactive-value="0" />
        </el-form-item>

        <el-form-item label="规格库存">
          <div style="width: 100%">
            <el-table :data="proGuigeRows" size="small" border>
              <el-table-column label="规格名" min-width="110">
                <template #default="{ row }">
                  <el-input v-model="row.name" placeholder="如：默认 / 红色" />
                </template>
              </el-table-column>
              <el-table-column label="积分" width="100">
                <template #default="{ row }">
                  <el-input-number
                    v-model="row.score_price"
                    :min="0"
                    :precision="0"
                    :controls="false"
                    size="small"
                    style="width: 80px"
                  />
                </template>
              </el-table-column>
              <el-table-column label="现金" width="110">
                <template #default="{ row }">
                  <el-input-number
                    v-model="row.money_price"
                    :min="0"
                    :precision="2"
                    :controls="false"
                    size="small"
                    style="width: 90px"
                  />
                </template>
              </el-table-column>
              <el-table-column label="库存" width="100">
                <template #default="{ row }">
                  <el-input-number
                    v-model="row.stock"
                    :min="0"
                    :precision="0"
                    :controls="false"
                    size="small"
                    style="width: 80px"
                  />
                </template>
              </el-table-column>
              <el-table-column label="" width="60">
                <template #default="{ $index }">
                  <el-button
                    link
                    type="danger"
                    :disabled="proGuigeRows.length <= 1"
                    @click="removeProGuigeRow($index)"
                  >
                    删除
                  </el-button>
                </template>
              </el-table-column>
            </el-table>
            <el-button size="small" style="margin-top: 8px" @click="addProGuigeRow">
              + 添加规格
            </el-button>
          </div>
        </el-form-item>

        <el-form-item label="商品详情">
          <el-input
            v-model="proForm.detail"
            type="textarea"
            :rows="4"
            placeholder="请输入商品详情"
          />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="proDrawerVisible = false">取消</el-button>
        <el-button type="primary" :loading="proSubmitting" @click="handleProSubmit">保存</el-button>
      </template>
    </el-drawer>

    <ImgIconPicker v-model:visible="imgPickerVisible" default-tab="library" @pick="onImgPick" />
  </div>
</template>

<style scoped>
.pagination {
  margin-top: 16px;
  justify-content: flex-end;
}

.product-cell {
  display: flex;
  align-items: center;
  gap: 8px;
}

.product-name {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  max-width: 220px;
}

.order-product {
  display: flex;
  align-items: center;
  gap: 6px;
}

.form-tip {
  margin-left: 8px;
  color: #909399;
  font-size: 12px;
}

.text-muted {
  color: #909399;
  font-size: 12px;
}
</style>
