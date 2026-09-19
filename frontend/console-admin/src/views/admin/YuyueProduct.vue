<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import type { FormInstance, FormRules } from 'element-plus'
import {
  getYuyueProductList,
  getYuyueProductDetail,
  addYuyueProduct,
  editYuyueProduct,
  deleteYuyueProduct,
  setYuyueProductStatus,
  checkYuyueProduct,
  copyYuyueProduct,
  getYuyueCategoryList,
  type YuyueProductListItem,
  type YuyueProductSaveParams,
} from '../../api/yuyue'
import type { YuyueCategoryItem } from '../../api/types'
import ImgIconPicker from '../../components/ImgIconPicker.vue'
import SimpleRichEditor from '../../components/SimpleRichEditor.vue'
import LinkAddressModal from '../../components/LinkAddressModal.vue'

// bid 由菜单工厂注入：有值=商户视图(showtype=2，无「添加」，显示所属商户/审核状态/行内审核)；无值=平台自营视图
const props = withDefaults(defineProps<{ bid?: string }>(), { bid: '' })
const isMerchantView = computed(() => !!props.bid)

// ============ 列表 ============
const loading = ref(false)
const tableData = ref<YuyueProductListItem[]>([])
const total = ref(0)
const selectedRows = ref<YuyueProductListItem[]>([])

const query = reactive({
  page: 1,
  pagesize: 10,
  name: '',
  status: '' as number | '',
  orderField: '',
  orderType: 'desc',
})

// 服务分类（仅编辑表单使用，列表搜索无分类下拉）
const categoryOptions = ref<YuyueCategoryItem[]>([])
async function fetchCategories() {
  try {
    const data = await getYuyueCategoryList()
    categoryOptions.value = data.list || []
  } catch {
    // 拦截器统一提示
  }
}

async function fetchList() {
  loading.value = true
  try {
    const data = await getYuyueProductList({
      page: query.page,
      pagesize: query.pagesize,
      name: query.name || undefined,
      status: query.status === '' ? undefined : query.status,
      orderField: query.orderField || undefined,
      orderType: query.orderType || undefined,
      bid: props.bid || undefined,
    })
    tableData.value = data.list || []
    total.value = data.total || 0
  } catch {
    // 拦截器统一提示
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
function handleSelectionChange(rows: YuyueProductListItem[]) {
  selectedRows.value = rows
}
function handleSortChange({ prop, order }: { prop: string; order: string | null }) {
  query.orderField = order ? prop : ''
  query.orderType = order === 'ascending' ? 'asc' : 'desc'
  query.page = 1
  fetchList()
}
function selectedIds(): number[] {
  return selectedRows.value.map((r) => r.id)
}

// ============ 批量 ============
function handleBatchDelete() {
  const ids = selectedIds()
  if (ids.length === 0) {
    ElMessage.warning('请先勾选服务')
    return
  }
  ElMessageBox.confirm(`确定要删除选中的 ${ids.length} 个服务吗？删除后不可恢复。`, '批量删除', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await deleteYuyueProduct(ids)
        ElMessage.success('删除成功')
        fetchList()
      } catch {
        // 拦截器统一提示
      }
    })
    .catch(() => {})
}

function handleBatchStatus(status: number) {
  const ids = selectedIds()
  if (ids.length === 0) {
    ElMessage.warning('请先勾选服务')
    return
  }
  const tip = status === 1 ? '上架' : '下架'
  ElMessageBox.confirm(`确定要${tip}选中的 ${ids.length} 个服务吗？`, `批量${tip}`, {
    confirmButtonText: tip,
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await setYuyueProductStatus(ids, status)
        ElMessage.success(`已${tip}`)
        fetchList()
      } catch {
        // 拦截器统一提示
      }
    })
    .catch(() => {})
}

// ============ 行操作 ============
function handleRowStatus(row: YuyueProductListItem) {
  const target = row.status === 1 ? 0 : 1
  const tip = target === 1 ? '上架' : '下架'
  ElMessageBox.confirm(`确定要${tip}服务「${row.name}」吗？`, `${tip}确认`, {
    confirmButtonText: tip,
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await setYuyueProductStatus(row.id, target)
        row.status = target
        ElMessage.success(`已${tip}`)
      } catch {
        // 拦截器统一提示
      }
    })
    .catch(() => {})
}

function handleDelete(row: YuyueProductListItem) {
  ElMessageBox.confirm(`确定要删除服务「${row.name}」吗？删除后不可恢复。`, '删除确认', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await deleteYuyueProduct(row.id)
        ElMessage.success('删除成功')
        fetchList()
      } catch {
        // 拦截器统一提示
      }
    })
    .catch(() => {})
}

async function handleCopy(row: YuyueProductListItem) {
  try {
    await copyYuyueProduct(row.id)
    ElMessage.success('复制成功')
    fetchList()
  } catch {
    // 拦截器统一提示
  }
}

// ============ 查看链接（H5 链接 + 二维码） ============
const linkVisible = ref(false)
const linkTarget = reactive({ name: '', path: '' })
function handleShowLink(row: YuyueProductListItem) {
  linkTarget.name = row.name
  linkTarget.path = `/yuyue/yuyue/product?id=${row.id}`
  linkVisible.value = true
}

// ============ 审核（仅商户视图行内） ============
function handleCheckPass(row: YuyueProductListItem) {
  ElMessageBox.confirm(`确定要审核通过服务「${row.name}」吗？`, '审核通过', {
    confirmButtonText: '通过',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await checkYuyueProduct(row.id, 1)
        row.ischecked = 1
        ElMessage.success('已通过')
      } catch {
        // 拦截器统一提示
      }
    })
    .catch(() => {})
}

function handleCheckReject(row: YuyueProductListItem) {
  ElMessageBox.prompt('请输入驳回原因', '驳回', {
    inputPlaceholder: '驳回原因',
    inputValidator: (v) => !!v && v.trim() !== '' || '请输入驳回原因',
  })
    .then(async ({ value }) => {
      try {
        await checkYuyueProduct(row.id, 2, value)
        row.ischecked = 2
        row.check_reason = value
        ElMessage.success('已驳回')
      } catch {
        // 拦截器统一提示
      }
    })
    .catch(() => {})
}

// ============ 视图切换：列表 / 整页编辑 ============
const viewMode = ref<'list' | 'edit'>('list')
const isEdit = ref(false)
const activeTab = ref<'info' | 'detail'>('info')
const submitting = ref(false)
const formRef = ref<FormInstance>()

const form = reactive({
  id: 0,
  name: '',
  cid: '' as number | '',
  pic: '',
  pics: [] as string[],
  sellpoint: '',
  sell_price: 0,
  sales: 0,
  sort: 0,
  status: 1,
  detail: '',
})

const rules: FormRules = {
  name: [{ required: true, message: '请输入服务名称', trigger: 'blur' }],
  sell_price: [{ required: true, message: '请输入服务价格', trigger: 'blur' }],
}

function resetForm() {
  Object.assign(form, {
    id: 0,
    name: '',
    cid: '',
    pic: '',
    pics: [],
    sellpoint: '',
    sell_price: 0,
    sales: 0,
    sort: 0,
    status: 1,
    detail: '',
  })
}

function openAdd() {
  isEdit.value = false
  resetForm()
  activeTab.value = 'info'
  viewMode.value = 'edit'
}

async function openEdit(row: YuyueProductListItem) {
  isEdit.value = true
  try {
    const d = await getYuyueProductDetail(row.id)
    resetForm()
    Object.assign(form, {
      id: d.id,
      name: d.name || '',
      cid: d.cid ? Number(d.cid) : '',
      pic: d.pic || '',
      pics: Array.isArray(d.pics) ? d.pics : [],
      sellpoint: d.sellpoint || '',
      sell_price: Number(d.sell_price) || 0,
      sales: Number(d.sales) || 0,
      sort: Number(d.sort) || 0,
      status: Number(d.status) === 1 ? 1 : 0,
      detail: d.detail || '',
    })
    activeTab.value = 'info'
    viewMode.value = 'edit'
  } catch {
    // 拦截器统一提示
  }
}

function backToList() {
  viewMode.value = 'list'
  fetchList()
}

async function handleSubmit() {
  if (!formRef.value) return
  await formRef.value.validate(async (valid) => {
    if (!valid) return
    submitting.value = true
    try {
      const params: YuyueProductSaveParams = {
        name: form.name,
        cid: form.cid === '' ? 0 : Number(form.cid),
        pic: form.pic,
        pics: form.pics,
        sellpoint: form.sellpoint,
        sell_price: Number(form.sell_price) || 0,
        sales: Number(form.sales) || 0,
        sort: Number(form.sort) || 0,
        status: form.status,
        detail: form.detail,
      }
      if (isEdit.value) {
        await editYuyueProduct({ ...params, id: form.id })
        ElMessage.success('保存成功')
      } else {
        await addYuyueProduct(params)
        ElMessage.success('添加成功')
      }
      viewMode.value = 'list'
      fetchList()
    } catch {
      // 拦截器统一提示
    } finally {
      submitting.value = false
    }
  })
}

// ============ 图片选择 ============
const imgPickerVisible = ref(false)
const imgPickerMulti = ref(false)
let imgPickerTarget: 'pic' | 'pics' = 'pic'
function openPickPic() {
  imgPickerMulti.value = false
  imgPickerTarget = 'pic'
  imgPickerVisible.value = true
}
function openPickPics() {
  imgPickerMulti.value = true
  imgPickerTarget = 'pics'
  imgPickerVisible.value = true
}
function onImgPick(urls: string[]) {
  if (imgPickerTarget === 'pic') {
    if (urls[0]) form.pic = urls[0]
  } else {
    urls.forEach((u) => {
      if (!form.pics.includes(u)) form.pics.push(u)
    })
  }
}

onMounted(() => {
  fetchCategories()
  fetchList()
})
</script>

<template>
  <div>
    <!-- ============ 列表视图 ============ -->
    <div v-if="viewMode === 'list'">
      <div class="page-title">服务商品</div>

      <!-- 操作 + 搜索 同一行 -->
      <el-card shadow="never" class="toolbar">
        <div class="toolbar-left">
          <el-button v-if="!isMerchantView" type="success" @click="openAdd">添加</el-button>
          <el-button @click="handleBatchDelete">删除</el-button>
          <el-button @click="handleBatchStatus(1)">上架</el-button>
          <el-button @click="handleBatchStatus(0)">下架</el-button>
        </div>
        <el-form inline class="toolbar-search" @submit.prevent>
          <el-form-item label="服务名称">
            <el-input v-model="query.name" placeholder="服务名称" clearable style="width: 130px" @keyup.enter="handleSearch" />
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
          <el-table-column v-if="isMerchantView" prop="bname" label="所属商户" width="120" align="center" />
          <el-table-column label="服务信息" min-width="260">
            <template #default="{ row }">
              <div class="goods-info">
                <el-image v-if="row.pic" :src="row.pic" :preview-src-list="[row.pic]" fit="cover" class="goods-info-img" preview-teleported />
                <div v-else class="goods-info-img goods-info-img-empty">无图</div>
                <div class="goods-info-meta">
                  <div class="goods-info-name">
                    <span v-if="row.cname" class="goods-info-cat">[{{ row.cname }}]</span>{{ row.name }}
                  </div>
                  <div class="goods-info-price">￥{{ Number(row.sell_price).toFixed(2) }}</div>
                </div>
              </div>
            </template>
          </el-table-column>
          <el-table-column prop="sales" label="已售" width="80" sortable="custom" align="center">
            <template #default="{ row }">{{ row.sales ?? 0 }}</template>
          </el-table-column>
          <el-table-column prop="sort" label="序号" width="70" sortable="custom" align="center" />
          <el-table-column prop="createtime" label="创建时间" width="150" sortable="custom" align="center">
            <template #default="{ row }">{{ row.create_time }}</template>
          </el-table-column>
          <el-table-column label="状态" width="90" align="center">
            <template #default="{ row }">
              <span :class="row.status === 1 ? 'status-on' : 'status-off'">{{ row.status === 1 ? '已上架' : '未上架' }}</span>
            </template>
          </el-table-column>
          <el-table-column v-if="isMerchantView" label="审核状态" width="90" align="center">
            <template #default="{ row }">
              <span v-if="row.ischecked === 0" class="check-pending">待审核</span>
              <span v-else-if="row.ischecked === 1" class="status-on">已通过</span>
              <span v-else class="check-reject" :title="row.check_reason">已驳回</span>
            </template>
          </el-table-column>
          <el-table-column label="操作" width="200" fixed="right" align="center" header-align="center">
            <template #default="{ row }">
              <div class="row-ops">
                <el-button link type="primary" @click="handleShowLink(row)">查看链接</el-button>
                <el-button link type="primary" @click="handleCopy(row)">复制</el-button>
                <el-button link type="primary" @click="openEdit(row)">编辑</el-button>
                <el-button link type="danger" @click="handleDelete(row)">删除</el-button>
                <el-button link :type="row.status === 1 ? 'warning' : 'success'" @click="handleRowStatus(row)">
                  {{ row.status === 1 ? '下架' : '上架' }}
                </el-button>
                <template v-if="isMerchantView">
                  <el-button v-if="row.ischecked === 0 || row.ischecked === 2" link type="success" @click="handleCheckPass(row)">通过</el-button>
                  <el-button v-if="row.ischecked === 0 || row.ischecked === 1" link type="warning" @click="handleCheckReject(row)">驳回</el-button>
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

    <!-- ============ 整页编辑：两页签 ============ -->
    <div v-else class="goods-edit">
      <div class="ge-head">
        <span class="ge-head-title">{{ isEdit ? '编辑服务' : '添加服务' }}</span>
        <span class="ge-close" title="关闭" @click="backToList">&times;</span>
      </div>

      <el-form ref="formRef" :model="form" :rules="rules" label-position="right" label-width="100px" class="ge-form">
        <el-tabs v-model="activeTab" class="ge-tabs">
          <!-- ===== 服务信息 ===== -->
          <el-tab-pane label="服务信息" name="info">
            <el-form-item label="服务名称" required prop="name">
              <el-input v-model="form.name" maxlength="120" style="width: 400px" placeholder="请输入服务名称" />
            </el-form-item>
            <el-form-item label="服务分类">
              <el-select v-model="form.cid" placeholder="请选择分类" clearable style="width: 300px">
                <el-option v-for="c in categoryOptions" :key="c.id" :label="c.name" :value="c.id" />
              </el-select>
            </el-form-item>
            <el-form-item label="服务主图">
              <el-button @click="openPickPic">选择图片</el-button>
              <span class="ge-hint">建议尺寸 640×640</span>
              <div class="ge-upload-box">
                <template v-if="form.pic">
                  <el-image :src="form.pic" fit="cover" class="main-pic" />
                  <span class="ge-upload-del" @click="form.pic = ''">×</span>
                </template>
                <div v-else class="ge-upload-add" @click="openPickPic">+</div>
              </div>
            </el-form-item>
            <el-form-item label="服务图集">
              <el-button @click="openPickPics">批量上传</el-button>
              <div class="ge-thumb-row">
                <div v-for="(u, i) in form.pics" :key="u" class="ge-thumb-item">
                  <el-image :src="u" fit="cover" class="pic-thumb" />
                  <span class="ge-upload-del" @click="form.pics.splice(i, 1)">×</span>
                </div>
              </div>
            </el-form-item>
            <el-form-item label="服务卖点">
              <el-input v-model="form.sellpoint" maxlength="60" show-word-limit style="width: 400px" placeholder="服务标题下展示的卖点" />
            </el-form-item>
            <el-form-item label="服务价格" required prop="sell_price">
              <el-input-number v-model="form.sell_price" :min="0" :precision="2" :step="10" />
              <span class="ge-hint">元</span>
            </el-form-item>
            <el-form-item label="虚拟销量">
              <el-input-number v-model="form.sales" :min="0" :precision="0" :step="1" />
            </el-form-item>
            <el-form-item label="序号">
              <el-input-number v-model="form.sort" :min="0" :precision="0" :step="1" />
              <span class="ge-hint">越小越靠前</span>
            </el-form-item>
            <el-form-item label="状态">
              <el-switch v-model="form.status" :active-value="1" :inactive-value="0" />
              <span class="ge-hint">上架后前台可见</span>
            </el-form-item>
          </el-tab-pane>

          <!-- ===== 服务详情 ===== -->
          <el-tab-pane label="服务详情" name="detail">
            <el-form-item label="服务详情">
              <SimpleRichEditor v-model="form.detail" />
            </el-form-item>
          </el-tab-pane>
        </el-tabs>
        <div class="tab-footer">
          <el-button @click="backToList">取消</el-button>
          <el-button type="primary" :loading="submitting" @click="handleSubmit">提交</el-button>
        </div>
      </el-form>
    </div>

    <!-- 选图弹窗 -->
    <ImgIconPicker v-model:visible="imgPickerVisible" :multi="imgPickerMulti" default-tab="upload" @pick="onImgPick" />
    <!-- 查看链接（H5 链接 + 二维码） -->
    <LinkAddressModal v-model:visible="linkVisible" :name="linkTarget.name" :path="linkTarget.path" />
  </div>
</template>

<style scoped>
.page-title {
  font-size: 16px;
  font-weight: 600;
  color: #303133;
  margin-bottom: 12px;
}
.toolbar { margin-bottom: 12px; }
.toolbar :deep(.el-card__body) { padding: 12px 16px; display: flex; align-items: center; justify-content: space-between; gap: 12px 16px; flex-wrap: wrap; }
.toolbar-left { display: flex; align-items: center; gap: 8px; flex-shrink: 0; }
.toolbar-left .el-button { margin: 0; }
.toolbar-search { display: flex; align-items: center; flex-wrap: nowrap; margin: 0; }
.toolbar-search :deep(.el-form-item) { margin-bottom: 0; margin-right: 10px; }
.toolbar-search :deep(.el-form-item:last-child) { margin-right: 0; }
.search-icon-btn { padding: 8px 14px; }
.search-icon-btn svg { display: block; }
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
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}
.goods-info-cat {
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
.check-pending {
  color: #409eff;
}
.check-reject {
  color: #f56c6c;
  cursor: pointer;
}
.row-ops {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: center;
  gap: 2px 10px;
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
  font-size: 22px;
  line-height: 1;
  color: #909399;
  cursor: pointer;
  padding: 2px 10px;
  border: none;
  background: transparent;
}
.ge-close:hover {
  color: #f56c6c;
}
.ge-form :deep(.el-form-item) {
  margin-bottom: 16px;
}
.ge-hint {
  color: #909399;
  font-size: 12px;
  margin-left: 8px;
}
.ge-upload-box {
  display: inline-flex;
  align-items: center;
  margin-left: 10px;
  vertical-align: middle;
}
.main-pic {
  width: 72px;
  height: 72px;
  border-radius: 6px;
  border: 1px solid #ebeef5;
}
.ge-upload-add {
  width: 72px;
  height: 72px;
  border: 1px dashed #d9d9d9;
  border-radius: 6px;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #c0c4cc;
  font-size: 24px;
  cursor: pointer;
}
.ge-upload-del {
  position: relative;
  margin-left: -8px;
  top: -30px;
  left: 6px;
  width: 18px;
  height: 18px;
  line-height: 16px;
  text-align: center;
  background: rgba(0, 0, 0, 0.5);
  color: #fff;
  border-radius: 50%;
  font-size: 12px;
  cursor: pointer;
}
.ge-thumb-row {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  margin-top: 8px;
}
.ge-thumb-item {
  position: relative;
}
.pic-thumb {
  width: 72px;
  height: 72px;
  border-radius: 6px;
  border: 1px solid #ebeef5;
}
.tab-footer {
  display: flex;
  justify-content: center;
  gap: 14px;
  padding: 16px 0 8px;
  border-top: 1px solid #ebeef5;
}
</style>
