<script setup lang="ts">
/**
 * 商户 - 商品审核（仅商户商品 bid>0）
 * 对标参考系统 ShopProduct 商品审核：
 * - 搜索：商品ID / 商品名称 / 分类 / 分组 / 审核状态 / 所属商户
 * - 列：多选、ID、所属商户、商品信息(图/名/价)、库存、显示销量、实际销量、创建时间、审核状态、操作(通过/驳回/查看商品)
 * - ischecked：0待审核(蓝) 1已通过(绿) 2已驳回(红)；通过=1，驳回=2 必填原因
 */
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { getGoodsAuditList, setGoodsAuditStatus, type GoodsAuditItem } from '../../api/goodsAudit'
import { getCategoryList, getGoodsDetail } from '../../api/goods'
import { getShopGroupList } from '../../api/shopconfig'
import { getBusinessMerchantList } from '../../api/businessMerchant'

interface Opt {
  id: number
  name: string
}

const loading = ref(false)
const tableData = ref<GoodsAuditItem[]>([])
const total = ref(0)
const selectedRows = ref<GoodsAuditItem[]>([])

const query = reactive({
  page: 1,
  pagesize: 20,
  goods_id: '' as number | '',
  name: '',
  cid: '' as number | '',
  gid: '' as number | '',
  ischecked: '' as number | '',
  bid: '' as number | '',
  field: 'id',
  order: 'desc' as 'asc' | 'desc',
})

const categoryOptions = ref<Opt[]>([])
const groupOptions = ref<Opt[]>([])
const merchantOptions = ref<Opt[]>([])

async function loadOptions() {
  try {
    const [cat, grp, mer] = await Promise.all([
      getCategoryList({ page: 1, pagesize: 500 }),
      getShopGroupList({ page: 1, pagesize: 500 }),
      getBusinessMerchantList({ page: 1, pagesize: 500 }),
    ])
    categoryOptions.value = (cat.list || []).map((c) => ({ id: c.id, name: c.name }))
    groupOptions.value = (grp.list || []).map((g) => ({ id: g.id, name: g.name }))
    merchantOptions.value = (mer.list || []).map((m) => ({ id: m.id, name: m.name }))
  } catch {
    // 下拉加载失败不阻塞列表
  }
}

async function fetchList() {
  loading.value = true
  try {
    const data = await getGoodsAuditList({
      page: query.page,
      pagesize: query.pagesize,
      goods_id: query.goods_id === '' ? undefined : query.goods_id,
      name: query.name || undefined,
      cid: query.cid === '' ? undefined : query.cid,
      gid: query.gid === '' ? undefined : query.gid,
      ischecked: query.ischecked === '' ? undefined : query.ischecked,
      bid: query.bid === '' ? undefined : query.bid,
      field: query.field,
      order: query.order,
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

function handleSortChange({ prop, order }: { prop: string; order: string | null }) {
  if (!order) {
    query.field = 'id'
    query.order = 'desc'
  } else {
    query.field = prop
    query.order = order === 'ascending' ? 'asc' : 'desc'
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

function handleSelectionChange(rows: GoodsAuditItem[]) {
  selectedRows.value = rows
}

function fmtTime(ts: number): string {
  if (!ts) return '-'
  const d = new Date(ts * 1000)
  const p = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}`
}

function money(v: number): string {
  return '¥' + Number(v || 0).toFixed(2)
}

function sortOrder(prop: string): 'ascending' | 'descending' | null {
  return query.field === prop ? (query.order === 'asc' ? 'ascending' : 'descending') : null
}

// ============ 审核 ============
async function doAudit(id: number, st: number, reason = '') {
  await setGoodsAuditStatus(id, st, reason)
  ElMessage.success(st === 1 ? '已审核通过' : '已驳回')
  fetchList()
}

function passOne(row: GoodsAuditItem) {
  ElMessageBox.confirm(`确定要审核通过商品「${row.name}」吗？`, '操作确认', {
    confirmButtonText: '通过',
    cancelButtonText: '取消',
    type: 'success',
  })
    .then(() => doAudit(row.id, 1))
    .catch(() => {})
}

function rejectOne(row: GoodsAuditItem) {
  ElMessageBox.prompt(`请填写驳回「${row.name}」的原因（将展示给商户）`, '商品驳回', {
    confirmButtonText: '确定驳回',
    cancelButtonText: '取消',
    inputType: 'textarea',
    inputPlaceholder: '请输入驳回原因',
    inputValue: row.check_reason || '',
    inputValidator: (v: string) => ((v && v.trim()) ? true : '请填写驳回原因'),
  })
    .then(({ value }) => doAudit(row.id, 2, (value || '').trim()))
    .catch(() => {})
}

// ============ 查看商品（只读） ============
const detailVisible = ref(false)
const detailLoading = ref(false)
const detail = ref<Record<string, never> | ReturnType<typeof normalizeDetail> | null>(null)

function normalizeDetail(d: {
  id: number
  name: string
  image: string
  images: string
  price: number
  market_price: number
  stock: number
  status: number
  detail: string
}) {
  const imgs = (d.images || '')
    .split(',')
    .map((s) => s.trim())
    .filter(Boolean)
  return {
    id: d.id,
    name: d.name,
    image: d.image,
    images: d.image ? [d.image, ...imgs.filter((u) => u !== d.image)] : imgs,
    price: d.price,
    market_price: d.market_price,
    stock: d.stock,
    status: d.status,
    detail: d.detail || '',
  }
}

async function viewGoods(row: GoodsAuditItem) {
  detailVisible.value = true
  detailLoading.value = true
  detail.value = null
  try {
    const d = await getGoodsDetail(row.id)
    detail.value = normalizeDetail(d)
  } catch {
    detailVisible.value = false
  } finally {
    detailLoading.value = false
  }
}

onMounted(() => {
  loadOptions()
  fetchList()
})
</script>

<template>
  <div class="ga-root">
    <!-- 搜索行 -->
    <el-card shadow="never" class="ga-filter-card">
      <el-form inline class="ga-filter-form" @submit.prevent>
        <el-form-item label="商品ID">
          <el-input v-model.number="query.goods_id" placeholder="商品ID" clearable style="width: 92px" @keyup.enter="handleSearch" />
        </el-form-item>
        <el-form-item label="商品名称">
          <el-input v-model="query.name" placeholder="商品名称" clearable style="width: 132px" @keyup.enter="handleSearch" />
        </el-form-item>
        <el-form-item label="分类">
          <el-select v-model="query.cid" placeholder="全部" clearable style="width: 116px">
            <el-option v-for="c in categoryOptions" :key="c.id" :label="c.name" :value="c.id" />
          </el-select>
        </el-form-item>
        <el-form-item label="分组">
          <el-select v-model="query.gid" placeholder="全部" clearable style="width: 116px">
            <el-option v-for="g in groupOptions" :key="g.id" :label="g.name" :value="g.id" />
          </el-select>
        </el-form-item>
        <el-form-item label="审核状态">
          <el-select v-model="query.ischecked" placeholder="全部" clearable style="width: 106px">
            <el-option label="待审核" :value="0" />
            <el-option label="已通过" :value="1" />
            <el-option label="已驳回" :value="2" />
          </el-select>
        </el-form-item>
        <el-form-item label="">
          <el-select v-model="query.bid" placeholder="请选择商户" filterable clearable style="width: 138px">
            <el-option v-for="m in merchantOptions" :key="m.id" :label="m.name" :value="m.id" />
          </el-select>
        </el-form-item>
        <el-form-item>
          <el-button type="primary" @click="handleSearch">搜索</el-button>
        </el-form-item>
      </el-form>
    </el-card>

    <!-- 列表 -->
    <el-card shadow="never">
      <el-table
        v-loading="loading"
        :data="tableData"
        stripe
        @selection-change="handleSelectionChange"
        @sort-change="handleSortChange"
      >
        <el-table-column type="selection" width="45" />
        <el-table-column prop="id" label="ID" width="85" sortable="custom" :order="sortOrder('id')" />
        <el-table-column prop="bname" label="所属商户" width="160" show-overflow-tooltip />
        <el-table-column label="商品信息" min-width="240">
          <template #default="{ row }">
            <div class="ga-goods">
              <el-image
                v-if="row.pic"
                :src="row.pic"
                fit="cover"
                class="ga-goods-img"
                :preview-src-list="[row.pic]"
                preview-teleported
              />
              <div class="ga-goods-meta">
                <div class="ga-goods-name">{{ row.name }}</div>
                <div class="ga-goods-price">{{ money(row.sell_price) }}</div>
              </div>
            </div>
          </template>
        </el-table-column>
        <el-table-column prop="stock" label="库存" width="90" sortable="custom" :order="sortOrder('stock')" />
        <el-table-column prop="sales" label="显示销量" width="95" sortable="custom" :order="sortOrder('sales')" />
        <el-table-column prop="realsalenum" label="实际销量" width="95" />
        <el-table-column label="创建时间" width="155">
          <template #default="{ row }">{{ fmtTime(row.createtime) }}</template>
        </el-table-column>
        <el-table-column label="审核状态" width="110">
          <template #default="{ row }">
            <span :class="['ga-state', 'ga-state-' + row.ischecked]">{{
              row.ischecked === 1 ? '已通过' : row.ischecked === 2 ? '已驳回' : '待审核'
            }}</span>
            <div v-if="row.ischecked === 2 && row.check_reason" class="ga-reason" :title="row.check_reason">
              {{ row.check_reason }}
            </div>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="180" fixed="right">
          <template #default="{ row }">
            <el-button v-if="row.ischecked === 0 || row.ischecked === 2" link type="success" @click="passOne(row)">
              通过
            </el-button>
            <el-button v-if="row.ischecked === 0 || row.ischecked === 1" link type="danger" @click="rejectOne(row)">
              驳回
            </el-button>
            <el-button link type="primary" @click="viewGoods(row)">查看商品</el-button>
          </template>
        </el-table-column>
      </el-table>

      <el-pagination
        class="ga-pagination"
        background
        layout="total, sizes, prev, pager, next, jumper"
        :total="total"
        :current-page="query.page"
        :page-size="query.pagesize"
        :page-sizes="[20, 50, 100]"
        @current-change="handlePageChange"
        @size-change="handleSizeChange"
      />
    </el-card>

    <!-- 查看商品（只读） -->
    <el-dialog v-model="detailVisible" title="查看商品" width="640px" append-to-body>
      <div v-loading="detailLoading">
        <template v-if="detail">
          <div class="ga-detail">
            <div class="ga-detail-row">
              <span class="ga-detail-label">商品名称：</span>{{ (detail as any).name }}
            </div>
            <div class="ga-detail-row">
              <span class="ga-detail-label">售价：</span>
              <span class="ga-goods-price">¥{{ Number((detail as any).price || 0).toFixed(2) }}</span>
              <span class="ga-detail-label" style="margin-left: 20px">库存：</span>{{ (detail as any).stock }}
              <span class="ga-detail-label" style="margin-left: 20px">状态：</span>
              {{ (detail as any).status === 1 ? '上架' : '下架' }}
            </div>
            <div class="ga-detail-label">商品图片：</div>
            <div class="ga-detail-imgs">
              <el-image
                v-for="(img, i) in (detail as any).images"
                :key="i"
                :src="img"
                fit="cover"
                class="ga-detail-img"
                :preview-src-list="(detail as any).images"
                :initial-index="i"
                preview-teleported
              />
              <span v-if="!(detail as any).images.length" class="ga-empty">无图片</span>
            </div>
          </div>
        </template>
      </div>
      <template #footer>
        <el-button @click="detailVisible = false">关 闭</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped>
.ga-filter-card {
  margin-bottom: 14px;
}

.ga-filter-card :deep(.el-card__body) {
  padding: 10px 14px 2px;
}

.ga-filter-form {
  display: flex;
  flex-wrap: nowrap;
  align-items: center;
  overflow-x: auto;
}

.ga-filter-form :deep(.el-form-item) {
  margin-right: 10px;
  margin-bottom: 8px;
  flex-shrink: 0;
}

.ga-filter-form :deep(.el-form-item__label) {
  white-space: nowrap;
  padding-right: 6px;
}

.ga-pagination {
  margin-top: 16px;
  justify-content: flex-end;
}

.ga-goods {
  display: flex;
  align-items: center;
  gap: 10px;
}

.ga-goods-img {
  width: 48px;
  height: 48px;
  border-radius: 4px;
  flex-shrink: 0;
}

.ga-goods-meta {
  min-width: 0;
}

.ga-goods-name {
  color: #303133;
  line-height: 1.4;
  word-break: break-all;
}

.ga-goods-price {
  color: #f56c6c;
  font-weight: 600;
  margin-top: 2px;
}

.ga-state-0 {
  color: #409eff;
}

.ga-state-1 {
  color: #19be6b;
}

.ga-state-2 {
  color: #f56c6c;
}

.ga-reason {
  color: #909399;
  font-size: 12px;
  margin-top: 2px;
  max-width: 100px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.ga-detail-row {
  margin-bottom: 12px;
  color: #303133;
}

.ga-detail-label {
  color: #606266;
  margin-bottom: 6px;
}

.ga-detail-imgs {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-top: 6px;
}

.ga-detail-img {
  width: 72px;
  height: 72px;
  border-radius: 4px;
}

.ga-empty {
  color: #c0c4cc;
}
</style>
