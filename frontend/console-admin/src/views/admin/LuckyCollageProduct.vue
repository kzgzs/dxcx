<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import type { FormInstance, FormRules } from 'element-plus'
import {
  batchDeleteLuckyCollageProducts,
  batchSetLuckyCollageProductStatus,
  checkLuckyCollageProduct,
  getLuckyCollageCategoryList,
  getLuckyCollageProductDetail,
  getLuckyCollageProductList,
  luckyCollageKaituan,
  saveLuckyCollageProduct,
} from '../../api/luckyCollage'
import type {
  LuckyCollageCategoryItem,
  LuckyCollageGuigeOption,
  LuckyCollageProductItem,
} from '../../api/luckyCollage'
import { formatTime } from '../../utils/time'
import ImgIconPicker from '../../components/ImgIconPicker.vue'

// ============ 双模式：bid 有值=商户视图(showtype=2)，无值=平台自营视图 ============
const props = withDefaults(defineProps<{ bid?: string }>(), { bid: '' })
const isMerchant = computed(() => !!props.bid)

// ============ 列表 ============
const viewMode = ref<'list' | 'edit'>('list')
const loading = ref(false)
const tableData = ref<LuckyCollageProductItem[]>([])
const total = ref(0)
const selected = ref<LuckyCollageProductItem[]>([])
const categories = ref<LuckyCollageCategoryItem[]>([])

const query = reactive({
  page: 1,
  pagesize: 10,
  keyword: '',
  cid: '' as number | '',
  status: '' as number | '',
  orderField: '',
  orderType: '',
})

async function fetchCategories() {
  try {
    const data = await getLuckyCollageCategoryList()
    categories.value = data.list || []
  } catch {
    // 忽略
  }
}

async function fetchList() {
  loading.value = true
  try {
    const data = await getLuckyCollageProductList({
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


function handleSelectionChange(rows: LuckyCollageProductItem[]) {
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
  ElMessageBox.confirm('确定要删除选中的幸运拼团商品吗？删除后不可恢复。', '删除确认', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      await batchDeleteLuckyCollageProducts(ids)
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
      await batchSetLuckyCollageProductStatus(ids, st)
      ElMessage.success(st === 1 ? '已上架' : '已下架')
      fetchList()
    })
    .catch(() => {})
}

// ============ 行操作 ============
function handleRowDelete(row: LuckyCollageProductItem) {
  ElMessageBox.confirm(`确定要删除幸运拼团商品「${row.name}」吗？删除后不可恢复。`, '删除确认', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      await batchDeleteLuckyCollageProducts([row.id])
      ElMessage.success('删除成功')
      fetchList()
    })
    .catch(() => {})
}

function handleRowStatus(row: LuckyCollageProductItem) {
  const target = row.status === 1 ? 0 : 1
  ElMessageBox.confirm(`确定要${target === 1 ? '上架' : '下架'}「${row.name}」吗？`, '操作确认', {
    confirmButtonText: '确定',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      await batchSetLuckyCollageProductStatus([row.id], target)
      ElMessage.success(target === 1 ? '已上架' : '已下架')
      fetchList()
    })
    .catch(() => {})
}

function handleCheck(row: LuckyCollageProductItem, pass: boolean) {
  if (pass) {
    ElMessageBox.confirm(`确定通过商品「${row.name}」的审核吗？`, '审核确认', {
      confirmButtonText: '通过',
      cancelButtonText: '取消',
      type: 'success',
    })
      .then(async () => {
        await checkLuckyCollageProduct(row.id, 1)
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
      await checkLuckyCollageProduct(row.id, 2, (value || '').trim())
      ElMessage.success('已驳回')
      fetchList()
    })
    .catch(() => {})
}

function handleKaituan(row: LuckyCollageProductItem) {
  ElMessageBox.confirm(`确定要为「${row.name}」机器人开团吗？`, '机器人开团', {
    confirmButtonText: '确定',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      const res = await luckyCollageKaituan(row.id, 0)
      ElMessage.success(res.kaijiang ? '机器人已入团并触发开奖' : '机器人已入团')
      fetchList()
    })
    .catch(() => {})
}

// ============ 平铺编辑（viewMode） ============
const isEdit = ref(false)
const activeTab = ref('basic')
const formRef = ref<FormInstance>()
const submitting = ref(false)

function defaultForm() {
  return {
    id: 0,
    name: '',
    pic: '',
    picsArr: [] as string[],
    cid: '' as number | '',
    sellpoint: '',
    teamnum: 3,
    teamhour: 24,
    leadermoney: 0,
    buymax: 0,
    sort: 0,
    status: 1,
    detail: '',
    // 开奖设置（productSave 实际支持）
    gua_num: 1,
    zjids: '',
    bzids: '',
    zjmax: 0,
    zjjl_type: 1,
    zj_money: 0,
    zjscore: 0,
    zjcommission: 0,
    zj_yhqids: '',
    bzjl_type: 1,
    bzj_score: 0,
    bzj_commission: 0,
    bzj_yhqids: '',
    fy_type: 1,
    fy_money: 0,
    fy_money_val: 0,
    red_give_mode: 1,
    tklx: 1,
    failtklx: 1,
    freighttype: 1,
  }
}

const form = reactive(defaultForm())
const options = ref<LuckyCollageGuigeOption[]>([])

const rules: FormRules = {
  name: [{ required: true, message: '请输入商品名称', trigger: 'blur' }],
  teamnum: [{ required: true, message: '请输入成团人数', trigger: 'blur' }],
  teamhour: [{ required: true, message: '请输入成团时限', trigger: 'blur' }],
}

function resetForm() {
  Object.assign(form, defaultForm())
  options.value = [{ name: '默认', sell_price: 0, market_price: 0, cost_price: 0, stock: 0 }]
}

function openAdd() {
  isEdit.value = false
  resetForm()
  activeTab.value = 'basic'
  viewMode.value = 'edit'
}

async function openEdit(row: LuckyCollageProductItem) {
  isEdit.value = true
  resetForm()
  activeTab.value = 'basic'
  viewMode.value = 'edit'
  try {
    const detail = await getLuckyCollageProductDetail(row.id)
    const base = defaultForm()
    for (const key of Object.keys(base) as (keyof ReturnType<typeof defaultForm>)[]) {
      if (key === 'picsArr') continue
      if (detail[key] !== undefined && detail[key] !== null) {
        ;(form as Record<string, unknown>)[key] = detail[key]
      }
    }
    form.id = Number(detail.id || 0)
    form.cid = (detail.cid ? Number(detail.cid) : '') as number | ''
    form.picsArr = detail.pics ? String(detail.pics).split(',').filter(Boolean) : []
    form.leadermoney = Number(detail.leadermoney ?? 0)
    form.zj_money = Number(detail.zj_money ?? 0)
    form.zjscore = Number(detail.zjscore ?? 0)
    form.zjcommission = Number(detail.zjcommission ?? 0)
    form.fy_money = Number(detail.fy_money ?? 0)
    form.fy_money_val = Number(detail.fy_money_val ?? 0)
    form.bzj_commission = Number(detail.bzj_commission ?? 0)
    const opts = (detail.options as Record<string, unknown>[] | undefined) || []
    options.value = opts.map((o) => ({
      id: Number(o.id || 0) || undefined,
      name: String(o.name ?? ''),
      sell_price: Number(o.sell_price ?? 0),
      market_price: Number(o.market_price ?? 0),
      cost_price: Number(o.cost_price ?? 0),
      stock: Number(o.stock ?? 0),
    }))
    if (options.value.length === 0) {
      options.value = [{ name: '默认', sell_price: 0, market_price: 0, cost_price: 0, stock: 0 }]
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

function addOptionRow() {
  options.value.push({ name: '', sell_price: 0, market_price: 0, cost_price: 0, stock: 0 })
}

function removeOptionRow(index: number) {
  options.value.splice(index, 1)
}

async function handleSubmit() {
  if (!formRef.value) return
  await formRef.value.validate(async (valid) => {
    if (!valid) return
    if (!form.pic) {
      ElMessage.warning('请上传商品主图')
      return
    }
    const rows = options.value.filter((o) => o.name.trim() !== '')
    if (rows.length === 0) {
      ElMessage.warning('请至少填写一条规格')
      return
    }
    submitting.value = true
    try {
      const { picsArr, ...rest } = form
      await saveLuckyCollageProduct({
        ...rest,
        cid: form.cid === '' ? 0 : Number(form.cid),
        pics: picsArr.join(','),
        id: form.id || undefined,
        options: rows,
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
const imgPickerVisible = ref(false)
const pickerMulti = ref(false)
let pickerTarget: 'pic' | 'pics' = 'pic'
function openPickPic() {
  pickerTarget = 'pic'
  pickerMulti.value = false
  imgPickerVisible.value = true
}
function openPickPics() {
  pickerTarget = 'pics'
  pickerMulti.value = true
  imgPickerVisible.value = true
}
function onImgPick(urls: string[]) {
  if (pickerTarget === 'pic') {
    if (urls[0]) form.pic = urls[0]
  } else {
    form.picsArr = urls.slice()
  }
}
function removePic(idx: number) {
  form.picsArr.splice(idx, 1)
}

onMounted(() => {
  fetchCategories()
  fetchList()
})
</script>

<template>
  <!-- ============ 列表视图 ============ -->
  <div v-if="viewMode === 'list'">
    <el-alert
      v-if="isMerchant"
      type="warning"
      :closable="false"
      title="请确保商家余额充足，否则会发放奖励失败"
      style="margin-bottom: 12px"
    />

    <el-card shadow="never" class="option-bar">
      <el-button v-if="!isMerchant" type="success" @click="openAdd">添加</el-button>
      <el-button @click="handleBatchDelete">删除</el-button>
      <el-button @click="handleBatchStatus(1)">上架</el-button>
      <el-button @click="handleBatchStatus(0)">下架</el-button>
    </el-card>

    <el-card shadow="never" class="search-bar">
      <el-form inline @submit.prevent>
        <el-form-item label="商品名称">
          <el-input
            v-model="query.keyword"
            placeholder="商品名称"
            clearable
            style="width: 180px"
            @keyup.enter="handleSearch"
          />
        </el-form-item>
        <el-form-item label="分类">
          <el-select v-model="query.cid" placeholder="全部" clearable style="width: 160px">
            <el-option v-for="c in categories" :key="c.id" :label="c.name" :value="c.id" />
          </el-select>
        </el-form-item>
        <el-form-item label="状态">
          <el-select v-model="query.status" placeholder="全部" clearable style="width: 120px">
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
      <template v-if="isMerchant" #header>商品管理</template>
      <el-table
        v-loading="loading"
        :data="tableData"
        border
        @selection-change="handleSelectionChange"
        @sort-change="handleSortChange"
      >
        <el-table-column type="selection" width="45" />
        <el-table-column prop="id" label="ID" width="70" sortable="custom" />
        <el-table-column v-if="isMerchant" prop="bname" label="所属商户" width="140" />
        <el-table-column label="商品信息" min-width="300">
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
                  <span v-if="row.cname" class="goods-info-cname">[{{ row.cname }}]</span>{{ row.name }}
                </div>
                <div class="goods-info-price">￥{{ Number(row.sell_price).toFixed(2) }}</div>
              </div>
            </div>
          </template>
        </el-table-column>
        <el-table-column prop="teamnum" label="拼团人数" width="90" />
        <el-table-column prop="stock" label="库存" width="80" />
        <el-table-column prop="sales" label="销量" width="80" sortable="custom" />
        <el-table-column prop="sort" label="序号" width="70" sortable="custom" />
        <el-table-column prop="createtime" label="创建时间" width="155" sortable="custom">
          <template #default="{ row }">{{ formatTime(row.createtime) }}</template>
        </el-table-column>
        <el-table-column label="状态" width="90">
          <template #default="{ row }">
            <span :class="row.status === 1 ? 'status-on' : 'status-off'">{{ row.status === 1 ? '已上架' : '未上架' }}</span>
          </template>
        </el-table-column>
        <el-table-column v-if="isMerchant" label="审核状态" width="95">
          <template #default="{ row }">
            <span v-if="row.ischecked === 1" class="check-on">已通过</span>
            <span v-else-if="row.ischecked === 2" class="check-off" :title="row.check_reason">已驳回</span>
            <span v-else class="check-wait">待审核</span>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="120">
          <template #default="{ row }">
            <div class="row-ops">
              <el-button link type="primary" @click="openEdit(row)">编辑</el-button>
              <el-button link type="danger" @click="handleRowDelete(row)">删除</el-button>
              <el-button link :type="row.status === 1 ? 'warning' : 'success'" @click="handleRowStatus(row)">
                {{ row.status === 1 ? '下架' : '上架' }}
              </el-button>
              <el-button link type="primary" @click="handleKaituan(row)">机器人开团</el-button>
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
      <span class="ge-head-title">{{ isEdit ? '编辑商品' : '添加商品' }}</span>
      <el-button class="ge-close" @click="backToList">✕</el-button>
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
        <!-- ===== 商品基本信息 ===== -->
        <el-tab-pane label="商品基本信息" name="basic">
          <el-form-item label="商品名称" required>
            <el-input v-model="form.name" maxlength="120" style="width: 400px" placeholder="请输入商品名称" />
          </el-form-item>

          <el-form-item label="商品主图" required>
            <div class="ge-pic-row">
              <el-image v-if="form.pic" :src="form.pic" fit="cover" class="main-pic" />
              <el-button v-else size="small" @click="openPickPic">上传图片</el-button>
              <el-button v-if="form.pic" size="small" @click="openPickPic">更换</el-button>
              <el-button v-if="form.pic" size="small" link type="danger" @click="form.pic = ''">删除</el-button>
            </div>
          </el-form-item>

          <el-form-item label="轮播图">
            <div class="ge-pic-row">
              <div v-for="(url, i) in form.picsArr" :key="url" class="ge-thumb-item">
                <el-image :src="url" fit="cover" class="pic-thumb" />
                <span class="ge-upload-del" @click="removePic(i)">×</span>
              </div>
              <el-button size="small" @click="openPickPics">批量上传</el-button>
            </div>
          </el-form-item>

          <el-form-item label="商品分类" required>
            <el-select v-model="form.cid" placeholder="请选择分类" clearable style="width: 400px">
              <el-option v-for="c in categories" :key="c.id" :label="c.name" :value="c.id" />
            </el-select>
          </el-form-item>

          <el-form-item label="商品卖点">
            <el-input v-model="form.sellpoint" maxlength="60" show-word-limit style="width: 400px" placeholder="一句话卖点（选填）" />
          </el-form-item>

          <el-form-item label="规格">
            <div class="spec-editor">
              <el-table :data="options" size="small" border>
                <el-table-column label="规格名" min-width="120">
                  <template #default="{ row }">
                    <el-input v-model="row.name" placeholder="如：默认 / 红色" />
                  </template>
                </el-table-column>
                <el-table-column label="拼团价" width="110">
                  <template #default="{ row }">
                    <el-input-number v-model="row.sell_price" :min="0" :precision="2" :controls="false" size="small" style="width: 90px" />
                  </template>
                </el-table-column>
                <el-table-column label="原价" width="110">
                  <template #default="{ row }">
                    <el-input-number v-model="row.market_price" :min="0" :precision="2" :controls="false" size="small" style="width: 90px" />
                  </template>
                </el-table-column>
                <el-table-column label="成本价" width="110">
                  <template #default="{ row }">
                    <el-input-number v-model="row.cost_price" :min="0" :precision="2" :controls="false" size="small" style="width: 90px" />
                  </template>
                </el-table-column>
                <el-table-column label="库存" width="100">
                  <template #default="{ row }">
                    <el-input-number v-model="row.stock" :min="0" :precision="0" :controls="false" size="small" style="width: 80px" />
                  </template>
                </el-table-column>
                <el-table-column label="" width="60">
                  <template #default="{ $index }">
                    <el-button link type="danger" :disabled="options.length <= 1" @click="removeOptionRow($index)">删除</el-button>
                  </template>
                </el-table-column>
              </el-table>
              <el-button size="small" style="margin-top: 8px" @click="addOptionRow">+ 添加规格</el-button>
            </div>
          </el-form-item>

          <el-form-item label="拼团人数" required>
            <el-input-number v-model="form.teamnum" :min="2" :max="100" :precision="0" :step="1" />
          </el-form-item>
          <el-form-item label="拼团时间" required>
            <el-input-number v-model="form.teamhour" :min="1" :max="720" :precision="0" :step="1" />
            <span class="ge-hint">小时</span>
          </el-form-item>
          <el-form-item label="团长优惠">
            <el-input-number v-model="form.leadermoney" :min="0" :precision="2" :step="1" />
            <span class="ge-hint">元，发团团长立减</span>
          </el-form-item>
          <el-form-item label="每人限购">
            <el-input-number v-model="form.buymax" :min="0" :precision="0" :step="1" />
            <span class="ge-hint">0 不限购</span>
          </el-form-item>

          <el-divider content-position="left">开奖设置</el-divider>

          <el-form-item label="中奖名额">
            <el-input-number v-model="form.gua_num" :min="0" :max="form.teamnum" :precision="0" />
            <span class="ge-hint">每团中奖人数</span>
          </el-form-item>
          <el-form-item label="指定中奖">
            <el-input v-model="form.zjids" placeholder="会员ID，逗号分隔（选填）" style="width: 320px" />
          </el-form-item>
          <el-form-item label="指定不中">
            <el-input v-model="form.bzids" placeholder="会员ID，逗号分隔（选填）" style="width: 320px" />
          </el-form-item>
          <el-form-item label="中奖上限">
            <el-input-number v-model="form.zjmax" :min="0" :precision="0" />
            <span class="ge-hint">单人累计中奖上限，0 不限</span>
          </el-form-item>
          <el-form-item label="中奖奖励">
            <el-select v-model="form.zjjl_type" style="width: 140px">
              <el-option label="奖励余额" :value="1" />
              <el-option label="奖励积分" :value="2" />
              <el-option label="奖励佣金" :value="3" />
              <el-option label="奖励优惠券" :value="4" />
            </el-select>
            <el-input-number v-if="form.zjjl_type === 1" v-model="form.zj_money" :min="0" :precision="2" style="margin-left: 8px" />
            <el-input-number v-if="form.zjjl_type === 2" v-model="form.zjscore" :min="0" :precision="0" style="margin-left: 8px" />
            <el-input-number v-if="form.zjjl_type === 3" v-model="form.zjcommission" :min="0" :precision="2" style="margin-left: 8px" />
            <el-input v-if="form.zjjl_type === 4" v-model="form.zj_yhqids" placeholder="优惠券ID，逗号分隔" style="margin-left: 8px; width: 200px" />
          </el-form-item>
          <el-form-item label="未中奖奖励">
            <el-select v-model="form.bzjl_type" style="width: 140px">
              <el-option label="奖励余额" :value="1" />
              <el-option label="奖励积分" :value="2" />
              <el-option label="奖励佣金" :value="3" />
              <el-option label="奖励优惠券" :value="4" />
            </el-select>
            <el-input-number v-if="form.bzjl_type === 1" v-model="form.fy_money_val" :min="0" :precision="2" style="margin-left: 8px" />
            <el-input-number v-if="form.bzjl_type === 2" v-model="form.bzj_score" :min="0" :precision="0" style="margin-left: 8px" />
            <el-input-number v-if="form.bzjl_type === 3" v-model="form.bzj_commission" :min="0" :precision="2" style="margin-left: 8px" />
            <el-input v-if="form.bzjl_type === 4" v-model="form.bzj_yhqids" placeholder="优惠券ID，逗号分隔" style="margin-left: 8px; width: 200px" />
          </el-form-item>
          <el-form-item label="未中奖返还">
            <el-radio-group v-model="form.fy_type">
              <el-radio :value="1">按比例返红包</el-radio>
              <el-radio :value="2">固定红包</el-radio>
            </el-radio-group>
          </el-form-item>
          <el-form-item v-if="form.fy_type === 1" label="返还比例">
            <el-input-number v-model="form.fy_money" :min="0" :max="100" :precision="2" />
            <span class="ge-hint">%（按拼团价比例返红包）</span>
          </el-form-item>
          <el-form-item v-if="form.fy_type === 2" label="返还金额">
            <el-input-number v-model="form.fy_money_val" :min="0" :precision="2" />
            <span class="ge-hint">元</span>
          </el-form-item>
          <el-form-item label="退款路线">
            <el-select v-model="form.tklx" style="width: 180px">
              <el-option label="原路退回" :value="1" />
              <el-option label="退到余额" :value="2" />
              <el-option label="不退款" :value="3" />
            </el-select>
            <span class="ge-hint">未中奖本金处理方式</span>
          </el-form-item>
          <el-form-item label="失败退款路线">
            <el-select v-model="form.failtklx" style="width: 180px">
              <el-option label="原路退回" :value="1" />
              <el-option label="退到余额" :value="2" />
              <el-option label="不退款" :value="3" />
            </el-select>
            <span class="ge-hint">超时未成团退款方式</span>
          </el-form-item>

          <el-form-item label="序号">
            <el-input-number v-model="form.sort" :min="0" :precision="0" :step="1" />
          </el-form-item>
          <el-form-item label="状态">
            <el-switch v-model="form.status" :active-value="1" :inactive-value="0" active-text="已上架" inactive-text="未上架" />
          </el-form-item>
        </el-tab-pane>

        <!-- ===== 商品详情 ===== -->
        <el-tab-pane label="商品详情" name="detail">
          <el-form-item label="商品详情">
            <el-input v-model="form.detail" type="textarea" :rows="8" placeholder="请输入商品详情" style="width: 640px" />
          </el-form-item>
        </el-tab-pane>
      </el-tabs>

      <div class="tab-footer">
        <el-button @click="backToList">取消</el-button>
        <el-button type="primary" :loading="submitting" @click="handleSubmit">保存</el-button>
      </div>
    </el-form>

    <ImgIconPicker v-model:visible="imgPickerVisible" :multi="pickerMulti" default-tab="library" @pick="onImgPick" />
  </div>
</template>

<style scoped>
.option-bar {
  margin-bottom: 10px;
}
.option-bar :deep(.el-card__body) {
  padding: 12px 16px;
}
.option-bar .el-button {
  margin-left: 0;
  margin-right: 8px;
}
.search-bar {
  margin-bottom: 12px;
}
.search-bar :deep(.el-card__body) {
  padding: 14px 16px 2px;
}
.search-bar :deep(.el-form-item) {
  margin-bottom: 12px;
}
.search-icon-btn {
  padding: 8px 14px;
}
.search-icon-btn svg {
  display: block;
}
.goods-info {
  display: flex;
  align-items: center;
  gap: 10px;
}
.goods-info-img {
  width: 70px;
  height: 70px;
  border-radius: 4px;
  border: 1px solid #ebeef5;
  flex-shrink: 0;
}
.goods-info-img-empty {
  display: flex;
  align-items: center;
  justify-content: center;
  color: #c0c4cc;
  font-size: 12px;
  background: #fafafa;
}
.goods-info-meta {
  min-width: 0;
  flex: 1;
}
.goods-info-name {
  color: #303133;
  font-size: 13px;
  line-height: 1.5;
  word-break: break-all;
}
.goods-info-cname {
  color: #f56c6c;
  margin-right: 4px;
}
.goods-info-price {
  color: #ff6600;
  font-weight: 600;
  font-size: 14px;
  margin-top: 6px;
}
.status-on {
  color: #21ba45;
}
.status-off {
  color: #f56c6c;
}
.check-on {
  color: #21ba45;
}
.check-off {
  color: #f56c6c;
}
.check-wait {
  color: #409eff;
}
.row-ops {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  line-height: 1.4;
}
.row-ops :deep(.el-button) {
  height: auto;
  padding: 1px 0;
  margin: 0;
  font-size: 12px;
}
.pagination {
  margin-top: 16px;
  justify-content: flex-end;
}

/* ===== 整页编辑 ===== */
.goods-edit {
  background: #fff;
  border-radius: 6px;
  padding: 16px 20px;
}
.ge-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 12px;
}
.ge-head-title {
  font-size: 16px;
  font-weight: 600;
  color: #303133;
}
.ge-close {
  border: none;
  background: transparent;
  color: #909399;
  font-size: 18px;
  font-weight: bold;
}
.ge-close:hover {
  color: #303133;
}
.ge-form :deep(.el-form-item) {
  margin-bottom: 15px;
}
.ge-hint {
  color: #909399;
  font-size: 12px;
  line-height: 1.5;
  margin-left: 10px;
}
.ge-pic-row {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
}
.main-pic,
.pic-thumb {
  width: 80px;
  height: 80px;
  border-radius: 4px;
  border: 1px solid #e4e7ed;
  object-fit: cover;
}
.ge-thumb-item {
  position: relative;
}
.ge-upload-del {
  position: absolute;
  top: -6px;
  right: -6px;
  width: 18px;
  height: 18px;
  line-height: 16px;
  text-align: center;
  background: #909399;
  color: #fff;
  border-radius: 50%;
  font-size: 12px;
  cursor: pointer;
  z-index: 2;
}
.spec-editor {
  width: 100%;
}
.tab-footer {
  display: flex;
  justify-content: center;
  gap: 14px;
  padding: 16px 0 8px;
  border-top: 1px solid #ebeef5;
}
</style>
