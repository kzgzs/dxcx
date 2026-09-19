<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import type { FormInstance } from 'element-plus'
import {
  checkKanjiaProduct,
  deleteKanjiaProduct,
  getKanjiaProductDetail,
  getKanjiaProductList,
  saveKanjiaProduct,
  setKanjiaProductStatus,
} from '../../api/kanjia'
import type { KanjiaProductKjRow, KanjiaProductRow } from '../../api/kanjia'
import ImgIconPicker from '../../components/ImgIconPicker.vue'

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
  orderField: '',
  orderType: '',
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
      orderField: query.orderField || undefined,
      orderType: query.orderType || undefined,
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
function handleSortChange({ prop, order }: { prop: string; order: string | null }) {
  query.orderField = order ? prop : ''
  query.orderType = order === 'ascending' ? 'asc' : 'desc'
  fetchList()
}
function handleSelectionChange(rows: KanjiaProductRow[]) {
  selected.value = rows
}

// 活动状态文案与颜色
function activityClass(row: KanjiaProductRow) {
  if (row.status === 0) return 'st-off'
  const now = Math.floor(Date.now() / 1000)
  if (row.starttime > 0 && now < row.starttime) return 'st-pending'
  if (row.endtime > 0 && now > row.endtime) return 'st-end'
  return 'st-on'
}
function activityText(row: KanjiaProductRow) {
  if (row.status === 0) return '未上架'
  const now = Math.floor(Date.now() / 1000)
  if (row.starttime > 0 && now < row.starttime) return '未开始'
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

// ============ 审核 ============
function handleCheck(row: KanjiaProductRow, pass: boolean) {
  if (pass) {
    ElMessageBox.confirm(`确定通过砍价商品「${row.name}」的审核吗？`, '审核确认', { type: 'success' })
      .then(async () => {
        await checkKanjiaProduct(row.id, 1)
        ElMessage.success('审核通过')
        fetchList()
      })
      .catch(() => {})
    return
  }
  ElMessageBox.prompt('请输入驳回原因', '驳回审核', {
    inputType: 'textarea',
    inputPlaceholder: '请填写驳回原因',
    type: 'warning',
  })
    .then(async ({ value }) => {
      await checkKanjiaProduct(row.id, 2, (value || '').trim())
      ElMessage.success('已驳回')
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

// ============ 编辑 ============
const isEdit = ref(false)
const formRef = ref<FormInstance>()
const submitting = ref(false)
const activeTab = ref('info')

const emptyForm = () => ({
  id: 0,
  name: '',
  pic: '',
  pics: '' as string,
  sell_price: 0 as number,
  min_price: 0 as number,
  stock: 0 as number,
  helpnum: 5,
  limitHour: 12,
  directbuy: 0,
  starttime: '' as string,
  endtime: '' as string,
  sort: 0,
  status: 1,
  detail: '',

})
const form = reactive(emptyForm())
const kjRows = ref<KanjiaProductKjRow[]>([])

function resetForm() {
  Object.assign(form, emptyForm())
  kjRows.value = [{ startnum: 1, endnum: 0, startmoney: 0, endmoney: 0 }]
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
      stock: d.stock,
      helpnum: d.helpnum,
      limitHour: d.limitHour,
      directbuy: d.directbuy,
      starttime: fmtTs(d.starttime),
      endtime: fmtTs(d.endtime),
      sort: d.sort,
      status: d.status,
      detail: d.detail || '',

    })
    const kj = (d.kjdata_list || []).map((k) => ({
      startnum: Number(k.startnum) || 0,
      endnum: k.endnum,
      startmoney: Number(k.startmoney) || 0,
      endmoney: Number(k.endmoney) || 0,
    }))
    kjRows.value = kj.length ? kj : [{ startnum: 1, endnum: 0, startmoney: 0, endmoney: 0 }]
  } catch {
    viewMode.value = 'list'
  }
}

function addKjRow() {
  kjRows.value.push({ startnum: 0, endnum: 0, startmoney: 0, endmoney: 0 })
}
function removeKjRow(i: number) {
  kjRows.value.splice(i, 1)
}

function backToList() {
  viewMode.value = 'list'
  fetchList()
}

async function handleSubmit() {
  if (!formRef.value) return
  await formRef.value.validate(async (valid) => {
    if (!valid) return
    if (!form.pic) {
      ElMessage.warning('请上传商品主图')
      return
    }
    submitting.value = true
    try {
      await saveKanjiaProduct({
        id: form.id || undefined,
        name: form.name,
        pic: form.pic,
        pics: form.pics,
        sell_price: Number(form.sell_price),
        min_price: Number(form.min_price),
        stock: Number(form.stock),
        helpnum: Number(form.helpnum),
        limitHour: Number(form.limitHour),
        directbuy: form.directbuy ? 1 : 0,
        starttime: form.starttime || undefined,
        endtime: form.endtime || undefined,
        sort: Number(form.sort),
        status: form.status ? 1 : 0,
        detail: form.detail,

        kjdata: kjRows.value.map((r) => ({
          startnum: Number(r.startnum) || 0,
          endnum: Number(r.endnum) || 0,
          startmoney: Number(r.startmoney) || 0,
          endmoney: Number(r.endmoney) || 0,
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
function openImg(setter: (u: string) => void) {
  imgSetter = setter
  imgPickerVisible.value = true
}
function onPick(urls: string[]) {
  if (urls[0] && imgSetter) imgSetter(urls[0])
  imgSetter = null
}

const multiPickerVisible = ref(false)
function openMultiPick() {
  multiPickerVisible.value = true
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
          <el-input v-model="query.keyword" placeholder="商品名称" clearable style="width: 130px" @keyup.enter="handleSearch" />
        </el-form-item>
        <el-form-item label="状态">
          <el-select v-model="query.status" placeholder="全部" clearable style="width: 96px">
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
      <el-table v-loading="loading" :data="tableData" border @selection-change="handleSelectionChange" @sort-change="handleSortChange">
        <el-table-column type="selection" width="45" align="center" />
        <el-table-column prop="id" label="ID" width="70" sortable="custom" align="center" />
        <el-table-column v-if="isMerchantView" prop="bname" label="所属商户" width="120" align="center">
          <template #default="{ row }">{{ row.bname }}</template>
        </el-table-column>
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
        <el-table-column prop="stock" label="库存" width="80" align="center" />
        <el-table-column prop="sales" label="已成功" width="80" sortable="custom" align="center" />
        <el-table-column prop="saleing" label="砍价中" width="80" sortable="custom" align="center" />
        <el-table-column label="活动时间" width="220" align="center">
          <template #default="{ row }">{{ fmtTs(row.starttime) }} ~ {{ fmtTs(row.endtime) }}</template>
        </el-table-column>
        <el-table-column label="状态" width="90" align="center">
          <template #default="{ row }">
            <span :class="activityClass(row)">{{ activityText(row) }}</span>
          </template>
        </el-table-column>
        <el-table-column v-if="isMerchantView" label="审核状态" width="90" align="center">
          <template #default="{ row }">
            <el-tooltip :content="row.check_reason" :disabled="!row.check_reason" placement="top">
              <span :class="row.ischecked === 1 ? 'ck-pass' : row.ischecked === 2 ? 'ck-reject' : 'ck-pending'">
                {{ row.ischecked === 1 ? '已通过' : row.ischecked === 2 ? '已驳回' : '待审核' }}
              </span>
            </el-tooltip>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="150" fixed="right" align="center" header-align="center">
          <template #default="{ row }">
            <div class="row-ops">
              <el-button link type="primary" @click="openEdit(row)">编辑</el-button>
              <el-button link type="danger" @click="handleDelete(row)">删除</el-button>
              <template v-if="isMerchantView">
                <el-button v-if="row.ischecked === 0 || row.ischecked === 2" link type="success" @click="handleCheck(row, true)">通过</el-button>
                <el-button v-if="row.ischecked === 0 || row.ischecked === 1" link type="warning" @click="handleCheck(row, false)">驳回</el-button>
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
        <!-- 基本信息 -->
        <el-tab-pane label="商品基本信息" name="info">
          <el-form-item label="商品名称" required>
            <el-input v-model="form.name" maxlength="120" style="width: 400px" placeholder="请输入商品名称" />
          </el-form-item>
          <el-form-item label="商品主图" required>
            <el-image v-if="form.pic" :src="form.pic" fit="cover" class="main-pic" @click="openImg((u) => (form.pic = u))" />
            <el-button v-else size="small" @click="openImg((u) => (form.pic = u))">上传图片</el-button>
            <span class="ge-hint">建议尺寸 640×640</span>
          </el-form-item>
          <el-form-item label="商品轮播图">
            <div class="pic-line">
              <div v-for="u in (form.pics ? form.pics.split(',').filter(Boolean) : [])" :key="u" class="pic-item">
                <el-image :src="u" fit="cover" class="pic-thumb" />
                <span class="pic-del" @click="form.pics = (form.pics.split(',').filter((x: string) => x !== u) as string[]).join(',')">×</span>
              </div>
              <el-button size="small" @click="openMultiPick">+ 添加轮播图</el-button>
            </div>
          </el-form-item>

          <el-form-item label="原价" required>
            <el-input-number v-model="form.sell_price" :min="0" :precision="2" :controls="false" style="width: 140px" />
            <span class="ge-unit">元</span>
          </el-form-item>
          <el-form-item label="最低价" required>
            <el-input-number v-model="form.min_price" :min="0" :precision="2" :controls="false" style="width: 140px" />
            <span class="ge-unit">元（砍价底价）</span>
          </el-form-item>
          <el-form-item label="库存">
            <el-input-number v-model="form.stock" :min="0" :controls="false" style="width: 140px" />
          </el-form-item>
          <el-form-item label="活动开始时间">
            <el-date-picker v-model="form.starttime" type="datetime" value-format="YYYY-MM-DD HH:mm" placeholder="选择开始时间" />
          </el-form-item>
          <el-form-item label="活动结束时间">
            <el-date-picker v-model="form.endtime" type="datetime" value-format="YYYY-MM-DD HH:mm" placeholder="选择结束时间" />
          </el-form-item>
          <el-form-item label="砍价时长">
            <el-input-number v-model="form.limitHour" :min="1" :max="720" :controls="false" style="width: 140px" />
            <span class="ge-unit">小时（单人砍价有效期）</span>
          </el-form-item>
          <el-form-item label="砍价人数">
            <el-input-number v-model="form.helpnum" :min="1" :max="100" :controls="false" style="width: 140px" />
            <span class="ge-unit">人</span>
          </el-form-item>
          <el-form-item label="直接购买">
            <el-switch v-model="form.directbuy" :active-value="1" :inactive-value="0" />
            <span class="ge-unit">开启后未砍到底价也可直接购买</span>
          </el-form-item>
          <el-form-item label="序号">
            <el-input-number v-model="form.sort" :min="0" :controls="false" style="width: 140px" />
          </el-form-item>
          <el-form-item label="上架状态">
            <el-switch v-model="form.status" :active-value="1" :inactive-value="0" />
          </el-form-item>
        </el-tab-pane>

        <!-- 砍价区间设置 -->
        <el-tab-pane label="砍价区间设置" name="kj">
          <div class="ge-block-hint">分段砍价金额区间：按已砍人数区间设置可砍金额，留空则走内置递减。</div>
          <table class="kj-table">
            <thead>
              <tr><th style="width:160px">已砍人数起始</th><th style="width:160px">已砍人数结束</th><th style="width:160px">开始金额</th><th style="width:160px">结束金额</th><th style="width:90px">操作</th></tr>
            </thead>
            <tbody>
              <tr v-for="(row, i) in kjRows" :key="i">
                <td><el-input-number v-model="row.startnum" :min="0" :controls="false" size="small" style="width: 140px" /></td>
                <td><el-input-number v-model="row.endnum" :min="0" :controls="false" size="small" style="width: 140px" /></td>
                <td><el-input-number v-model="row.startmoney" :min="0" :precision="2" :controls="false" size="small" style="width: 140px" /></td>
                <td><el-input-number v-model="row.endmoney" :min="0" :precision="2" :controls="false" size="small" style="width: 140px" /></td>
                <td><el-button size="small" type="danger" plain @click="removeKjRow(i)">删除</el-button></td>
              </tr>
            </tbody>
          </table>
          <el-button size="small" style="margin-top: 10px" @click="addKjRow">+ 添加区间</el-button>
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

  <ImgIconPicker v-model:visible="imgPickerVisible" default-tab="library" @pick="onPick" />
  <ImgIconPicker v-model:visible="multiPickerVisible" default-tab="library" multi @pick="onMultiPick" />
</template>

<style scoped>
.toolbar { margin-bottom: 12px; }
.toolbar :deep(.el-card__body) { padding: 12px 16px; display: flex; align-items: center; justify-content: space-between; gap: 12px 16px; flex-wrap: wrap; }
.toolbar-left { display: flex; align-items: center; gap: 8px; flex-shrink: 0; }
.toolbar-left .el-button { margin: 0; }
.toolbar-search { display: flex; align-items: center; flex-wrap: nowrap; margin: 0; }
.toolbar-search :deep(.el-form-item) { margin-bottom: 0; margin-right: 10px; }
.toolbar-search :deep(.el-form-item:last-child) { margin-right: 0; }
.search-icon-btn { padding: 8px 14px; }
.search-icon-btn svg { display: block; }

.goods-info { display: flex; align-items: center; gap: 10px; }
.goods-info-img { width: 70px; height: 70px; border-radius: 4px; border: 1px solid #ebeef5; flex-shrink: 0; }
.goods-info-img-empty { display: flex; align-items: center; justify-content: center; color: #c0c4cc; font-size: 12px; background: #fafafa; }
.goods-info-meta { min-width: 0; flex: 1; }
.goods-info-name { color: #303133; font-size: 13px; line-height: 1.5; word-break: break-all; }
.goods-info-sub { color: #999; font-size: 12px; margin-top: 2px; }
.goods-info-price { color: #ff6600; font-weight: 600; font-size: 13px; margin-top: 2px; }

.st-on { color: #5FB878; }
.st-off { color: #f55; }
.st-pending { color: #888; }
.st-end { color: #ccc; }
.ck-pending { color: #409eff; }
.ck-pass { color: #21ba45; }
.ck-reject { color: #f56c6c; }

.row-ops { display: flex; flex-wrap: wrap; align-items: center; justify-content: center; gap: 2px 10px; line-height: 1.4; }
.row-ops :deep(.el-button) { height: auto; padding: 1px 0; margin: 0; font-size: 12px; }
.pagination { margin-top: 16px; justify-content: flex-end; }

.goods-edit { background: #fff; border-radius: 6px; padding: 16px 20px; }
.ge-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; }
.ge-head-title { font-size: 16px; font-weight: 600; color: #303133; }
.ge-close { border: none; background: transparent; color: #909399; }
.ge-close:hover { color: #409eff; }
.ge-form :deep(.el-form-item) { margin-bottom: 15px; }
.ge-hint { color: #909399; font-size: 12px; line-height: 1.5; margin-left: 10px; }
.ge-unit { color: #909399; font-size: 12px; margin-left: 8px; }
.ge-block-hint { color: #909399; font-size: 12px; line-height: 1.7; margin-bottom: 10px; }
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
