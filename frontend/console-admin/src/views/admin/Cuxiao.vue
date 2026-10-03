<script setup lang="ts">
import { computed, nextTick, onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Close, Search } from '@element-plus/icons-vue'
import {
  addCuxiao,
  deleteCuxiao,
  deleteCuxiaoBatch,
  editCuxiao,
  getCuxiaoDetail,
  getCuxiaoList,
  type CuxiaoItem,
} from '../../api/cuxiao'
import { getCategoryList, getGoodsList } from '../../api/goods'
import { getMemberLevelList } from '../../api/member'
import type { CategoryItem, GoodsItem, MemberLevelItem } from '../../api/types'

// ============ 促销类型（对标 canzhao cuxiao/edit.html） ============
// 1满额立减 2满额赠送 3加价换购 4满额打折 5满件打折 6满件立减 0无
const TYPE_OPTIONS: { value: number; label: string; tip: string }[] = [
  { value: 1, label: '满额立减', tip: '满减' },
  { value: 2, label: '满额赠送', tip: '满赠' },
  { value: 3, label: '加价换购', tip: '换购' },
  { value: 4, label: '满额打折', tip: '打折' },
  { value: 6, label: '满件立减', tip: '立减' },
  { value: 5, label: '满件打折', tip: '打折' },
  { value: 0, label: '无', tip: '特价' },
]
const TYPE_TEXT: Record<number, string> = {
  0: '无',
  1: '满额立减',
  2: '满额赠送',
  3: '加价换购',
  4: '满额打折',
  5: '满件打折',
  6: '满件立减',
}

// 条件字段显隐
const showMinprice = computed(() => [1, 2, 3, 4].includes(form.type))
const showMinnum = computed(() => [5, 6].includes(form.type))
const showMoney = computed(() => [1, 3, 6].includes(form.type))
const showZhekou = computed(() => [4, 5].includes(form.type))
const showGift = computed(() => [2, 3].includes(form.type))
const moneyLabel = computed(() => (form.type === 3 ? '加价金额' : '立减金额'))
const giftLabel = computed(() => (form.type === 2 ? '赠送商品' : '换购商品'))
const showRowZk = computed(() => [4, 5].includes(form.type))
const showRowNum = computed(() => [5, 6].includes(form.type))

// ============ 视图切换 ============
const viewMode = ref<'list' | 'edit'>('list')

// ============ 列表 ============
const loading = ref(false)
const list = ref<CuxiaoItem[]>([])
const total = ref(0)
const selected = ref<CuxiaoItem[]>([])
const query = reactive({ page: 1, pagesize: 10, keyword: '' })

async function fetchList() {
  loading.value = true
  try {
    const d = await getCuxiaoList(query)
    list.value = d.list || []
    total.value = d.total || 0
  } catch {
    // 错误提示由拦截器统一弹出
  } finally {
    loading.value = false
  }
}

function handleSearch() {
  query.page = 1
  fetchList()
}

function handleSelectionChange(rows: CuxiaoItem[]) {
  selected.value = rows
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

function handleDelete(row: CuxiaoItem) {
  ElMessageBox.confirm(`确定删除活动「${row.name}」？`, '提示', { type: 'warning' })
    .then(async () => {
      await deleteCuxiao(row.id)
      ElMessage.success('删除成功')
      fetchList()
    })
    .catch(() => {})
}

function handleBatchDelete() {
  if (selected.value.length === 0) {
    ElMessage.warning('请先勾选要删除的活动')
    return
  }
  ElMessageBox.confirm(`确定删除选中的 ${selected.value.length} 个活动？`, '批量删除', { type: 'warning' })
    .then(async () => {
      await deleteCuxiaoBatch(selected.value.map((r) => r.id))
      ElMessage.success('删除成功')
      fetchList()
    })
    .catch(() => {})
}

// 状态：按活动起止时间推算（对标 canzhao index）
function statusLabel(row: CuxiaoItem): { text: string; type: 'info' | 'success' | 'danger' } {
  const now = Date.now() / 1000
  const start = row.starttime ? new Date(row.starttime.replace(' ', 'T')).getTime() / 1000 : 0
  const end = row.endtime ? new Date(row.endtime.replace(' ', 'T')).getTime() / 1000 : 0
  if (start > 0 && now < start) return { text: '未开始', type: 'info' }
  if (end > 0 && now > end) return { text: '已结束', type: 'info' }
  return { text: '进行中', type: 'success' }
}

// ============ 编辑表单 ============
const saving = ref(false)
const isEdit = ref(false)

interface ProductRow {
  id: number
  name: string
  zk: string
  num: string
}
interface CategoryRow {
  id: number
  name: string
}

const form = reactive({
  id: 0,
  type: 1,
  tip: '满减',
  name: '',
  minprice: 0,
  minnum: 1,
  money: 0,
  zhekou: 0,
  proid: 0,
  ggid: 0,
  fwtype: 0,
  categoryRows: [] as CategoryRow[],
  productRows: [] as ProductRow[],
  gettjArr: ['-1'] as string[],
  starttime: '',
  endtime: '',
  sort: 0,
})

const levelOptions = ref<MemberLevelItem[]>([])
const categoryOptions = ref<CategoryItem[]>([])
const goodsOptions = ref<GoodsItem[]>([])

// 参与条件：勾选“所有人”时互斥
function onGettjChange(val: string[]) {
  if (val.includes('-1')) {
    form.gettjArr = ['-1']
  }
}

// 促销类型切换：写入 type 并自动回填活动标识
function onTypeChange(val: number) {
  form.type = val
  const opt = TYPE_OPTIONS.find((o) => o.value === val)
  if (opt) form.tip = opt.tip
}

async function loadCandidates() {
  try {
    const [cats, goods, levels] = await Promise.all([
      getCategoryList({ page: 1, pagesize: 200 }),
      getGoodsList({ page: 1, pagesize: 200 }),
      getMemberLevelList(),
    ])
    categoryOptions.value = cats.list || []
    goodsOptions.value = goods.list || []
    levelOptions.value = levels.list || []
  } catch {
    // 候选加载失败不阻断
  }
}

function resetForm() {
  Object.assign(form, {
    id: 0,
    type: 1,
    tip: '满减',
    name: '',
    minprice: 0,
    minnum: 1,
    money: 0,
    zhekou: 0,
    proid: 0,
    ggid: 0,
    fwtype: 0,
    categoryRows: [],
    productRows: [],
    gettjArr: ['-1'],
    starttime: '',
    endtime: '',
    sort: 0,
  })
}

function openAdd() {
  isEdit.value = false
  resetForm()
  viewMode.value = 'edit'
}

async function openEdit(row: CuxiaoItem) {
  isEdit.value = true
  try {
    const d = await getCuxiaoDetail(row.id)
    const pidArr = d.productids ? d.productids.split(',').filter(Boolean).map(Number) : []
    const zkArr = d.prozk ? d.prozk.split(',') : []
    const numArr = d.pronum ? d.pronum.split(',') : []
    Object.assign(form, {
      id: d.id,
      type: d.type,
      tip: d.tip || '',
      name: d.name,
      minprice: d.minprice,
      minnum: d.minnum,
      money: d.money,
      zhekou: d.zhekou || 0,
      proid: d.proid,
      ggid: d.ggid,
      fwtype: d.fwtype,
      categoryRows: d.categoryids
        ? d.categoryids
            .split(',')
            .filter(Boolean)
            .map(Number)
            .map((id) => {
              const c = categoryOptions.value.find((x) => x.id === id)
              return { id, name: c ? c.name : '' }
            })
        : [],
      productRows: pidArr.map((id, i) => {
        const g = goodsOptions.value.find((x) => x.id === id)
        return { id, name: g ? g.name : '', zk: zkArr[i] || '', num: numArr[i] || '' }
      }),
      gettjArr: d.gettj ? d.gettj.split(',') : ['-1'],
      starttime: d.starttime || '',
      endtime: d.endtime || '',
      sort: d.sort,
    })
    viewMode.value = 'edit'
  } catch {
    // 错误提示由拦截器统一弹出
  }
}

function handleCancelEdit() {
  viewMode.value = 'list'
}

// ============ 选择器弹窗（类目 / 商品） ============
const catPickerVisible = ref(false)
const catChecked = ref<number[]>([])
const proPickerVisible = ref(false)
const proChecked = ref<number[]>([])
const catTableRef = ref<{ toggleRowSelection: (row: CategoryItem, selected?: boolean) => void } | null>(null)
const proTableRef = ref<{ toggleRowSelection: (row: GoodsItem, selected?: boolean) => void } | null>(null)

function openCatPicker() {
  catChecked.value = form.categoryRows.map((r) => r.id)
  catPickerVisible.value = true
  nextTick(() => {
    categoryOptions.value.forEach((c) => {
      if (catChecked.value.includes(c.id)) catTableRef.value?.toggleRowSelection(c, true)
    })
  })
}
function onCatSelectionChange(rows: CategoryItem[]) {
  catChecked.value = rows.map((x) => x.id)
}
function confirmCatPicker() {
  form.categoryRows = catChecked.value.map((id) => {
    const c = categoryOptions.value.find((x) => x.id === id)
    return { id, name: c ? c.name : '' }
  })
  catPickerVisible.value = false
}
function removeCategoryRow(id: number) {
  form.categoryRows = form.categoryRows.filter((r) => r.id !== id)
}

function openProPicker() {
  proChecked.value = form.productRows.map((r) => r.id)
  proPickerVisible.value = true
  nextTick(() => {
    goodsOptions.value.forEach((g) => {
      if (proChecked.value.includes(g.id)) proTableRef.value?.toggleRowSelection(g, true)
    })
  })
}
function onProSelectionChange(rows: GoodsItem[]) {
  proChecked.value = rows.map((x) => x.id)
}
function confirmProPicker() {
  // 保留已有行的单独折扣/件数
  const oldMap = new Map(form.productRows.map((r) => [r.id, r]))
  form.productRows = proChecked.value.map((id) => {
    const old = oldMap.get(id)
    if (old) return old
    const g = goodsOptions.value.find((x) => x.id === id)
    return { id, name: g ? g.name : '', zk: '', num: '' }
  })
  proPickerVisible.value = false
}
function removeProductRow(id: number) {
  form.productRows = form.productRows.filter((r) => r.id !== id)
}

async function handleSave() {
  if (!form.name) {
    ElMessage.warning('请填写活动名称')
    return
  }
  if (!form.starttime || !form.endtime) {
    ElMessage.warning('请选择活动起止时间')
    return
  }
  const payload = {
    id: form.id,
    type: form.type,
    tip: form.tip,
    name: form.name,
    minprice: form.minprice,
    minnum: form.minnum,
    money: form.money,
    zhekou: form.zhekou,
    proid: showGift.value ? form.proid : 0,
    ggid: showGift.value ? form.ggid : 0,
    fwtype: form.fwtype,
    categoryids: form.categoryRows.map((r) => r.id).join(','),
    productids: form.productRows.map((r) => r.id).join(','),
    prozk: form.productRows.map((r) => r.zk).join(','),
    pronum: form.productRows.map((r) => r.num).join(','),
    gettj: form.gettjArr.includes('-1') ? '-1' : form.gettjArr.join(','),
    starttime: form.starttime,
    endtime: form.endtime,
    sort: form.sort,
  }
  saving.value = true
  try {
    if (isEdit.value) {
      await editCuxiao(payload)
    } else {
      await addCuxiao(payload)
    }
    ElMessage.success('保存成功')
    viewMode.value = 'list'
    fetchList()
  } catch {
    // 错误提示由拦截器统一弹出
  } finally {
    saving.value = false
  }
}

onMounted(() => {
  fetchList()
  loadCandidates()
})
</script>

<template>
  <div class="page-wrap">
    <template v-if="viewMode === 'list'">
      <div class="tip-bar">可设置商品满减活动、满赠活动、加价换购活动，设置后会在商品详情页面显示正在进行的促销活动信息</div>

      <el-card shadow="never" class="toolbar-card">
        <div class="toolbar-row">
          <div class="toolbar-left">
            <el-button type="success" @click="openAdd">添加</el-button>
            <el-button @click="handleBatchDelete">删除</el-button>
          </div>
          <div class="toolbar-right">
            <span class="label">活动名称</span>
            <el-input v-model="query.keyword" clearable class="w-keyword" @keyup.enter="handleSearch" />
            <el-button circle type="success" :icon="Search" @click="handleSearch" />
          </div>
        </div>
      </el-card>

      <el-card shadow="never" class="table-card">
        <el-table
          v-loading="loading"
          :data="list"
          :header-cell-style="{ background: '#e6e6e6' }"
          :cell-style="{ textAlign: 'left' }"
          @selection-change="handleSelectionChange"
        >
          <el-table-column type="selection" width="42" />
          <el-table-column prop="id" label="ID" width="70" sortable />
          <el-table-column label="活动类型" width="100">
            <template #default="{ row }">{{ TYPE_TEXT[row.type] || '-' }}</template>
          </el-table-column>
          <el-table-column prop="tip" label="活动标识" width="90" />
          <el-table-column prop="name" label="活动名称" min-width="150" show-overflow-tooltip />
          <el-table-column label="活动时间" min-width="220" show-overflow-tooltip>
            <template #default="{ row }">{{ row.starttime }} ~ {{ row.endtime }}</template>
          </el-table-column>
          <el-table-column label="创建时间" width="150">
            <template #default="{ row }">{{ row.create_time ? new Date(row.create_time * 1000).toLocaleString('zh-CN', { hour12: false }) : '-' }}</template>
          </el-table-column>
          <el-table-column label="状态" width="90">
            <template #default="{ row }">
              <el-tag :type="statusLabel(row).type" size="small">{{ statusLabel(row).text }}</el-tag>
            </template>
          </el-table-column>
          <el-table-column label="操作" width="130">
            <template #default="{ row }">
              <div class="op-flex">
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
    </template>

    <el-card v-else shadow="never" class="edit-card">
      <template #header>
        <div class="edit-header">
          <span>{{ isEdit ? '编辑商品促销' : '添加商品促销' }}</span>
          <el-button link :icon="Close" @click="handleCancelEdit" />
        </div>
      </template>

      <el-form label-width="110px" class="compact-form">
        <el-form-item label="促销类型：">
          <el-radio-group :model-value="form.type" @change="onTypeChange">
            <el-radio v-for="o in TYPE_OPTIONS" :key="o.value" :value="o.value">{{ o.label }}</el-radio>
          </el-radio-group>
          <div class="aux">选择"无"时仅用于显示活动名称到商品详情</div>
        </el-form-item>

        <el-form-item label="活动标识：">
          <el-input v-model="form.tip" class="w-tip" />
        </el-form-item>

        <el-form-item label="活动名称：">
          <el-input v-model="form.name" maxlength="50" class="w-name" />
          <div class="aux">如：购物满100元减20元，购物满100元赠送玫瑰花一束，购物满100元加10元换购玫瑰花一束等</div>
        </el-form-item>

        <el-form-item v-if="showMinprice" label="最低消费金额：">
          <el-input v-model.number="form.minprice" class="w-num" />
          <span class="unit">元</span>
          <span class="aux">注：购物金额（不含运费）达到最低消费金额才可参与</span>
        </el-form-item>

        <el-form-item v-if="showMinnum" label="最低购买件数：">
          <el-input v-model.number="form.minnum" class="w-num" />
          <span class="unit">件</span>
          <span class="aux">注：购买商品数量达到最低购买件数才可参与</span>
        </el-form-item>

        <el-form-item v-if="showMoney" :label="moneyLabel + '：'">
          <el-input v-model.number="form.money" class="w-num" />
          <span class="unit">元</span>
        </el-form-item>

        <el-form-item v-if="showZhekou" label="折扣：">
          <el-input v-model.number="form.zhekou" class="w-num" />
          <span class="unit">折</span>
          <span class="aux">输入0.01~9.99的数字</span>
        </el-form-item>

        <el-form-item v-if="showGift" :label="giftLabel + '：'">
          <el-select v-model="form.proid" filterable placeholder="请选择商品" class="w-name">
            <el-option v-for="g in goodsOptions" :key="g.id" :label="g.name" :value="g.id" />
          </el-select>
        </el-form-item>

        <el-form-item label="适用范围：">
          <el-radio-group v-model="form.fwtype">
            <el-radio :value="0">所有商品</el-radio>
            <el-radio :value="1">指定类目</el-radio>
            <el-radio :value="2">指定商品</el-radio>
          </el-radio-group>
        </el-form-item>

        <el-form-item v-if="form.fwtype === 1" label-width="110px">
          <el-table :data="form.categoryRows" size="small" class="inner-table" :header-cell-style="{ background: '#e6e6e6' }">
            <el-table-column prop="id" label="分类ID" width="120" />
            <el-table-column prop="name" label="分类名称" />
            <el-table-column label="操作" width="120">
              <template #default="{ row }">
                <el-button link type="danger" @click="removeCategoryRow(row.id)">删除</el-button>
              </template>
            </el-table-column>
          </el-table>
          <el-button size="small" class="inner-add" @click="openCatPicker">添加</el-button>
        </el-form-item>

        <el-form-item v-if="form.fwtype === 2" label-width="110px">
          <el-table :data="form.productRows" size="small" class="inner-table" :header-cell-style="{ background: '#e6e6e6' }">
            <el-table-column prop="id" label="商品ID" width="100" />
            <el-table-column prop="name" label="商品名称" />
            <el-table-column v-if="showRowZk" label="单独折扣" width="140">
              <template #default="{ row }">
                <el-input v-model="row.zk" size="small" placeholder="0.01~9.99" />
              </template>
            </el-table-column>
            <el-table-column v-if="showRowNum" label="单独最低件数" width="150">
              <template #default="{ row }">
                <el-input v-model="row.num" size="small" placeholder="设置后如未达到不打折" />
              </template>
            </el-table-column>
            <el-table-column label="操作" width="90">
              <template #default="{ row }">
                <el-button link type="danger" @click="removeProductRow(row.id)">删除</el-button>
              </template>
            </el-table-column>
          </el-table>
          <div v-if="showRowZk" class="aux">注：不单独设置商品折扣则按照统一的折扣计算</div>
          <el-button size="small" class="inner-add" @click="openProPicker">添加</el-button>
        </el-form-item>

        <el-form-item label="参与条件：">
          <el-checkbox-group v-model="form.gettjArr" @change="onGettjChange">
            <el-checkbox value="-1">所有人</el-checkbox>
            <el-checkbox v-for="lv in levelOptions" :key="lv.id" :value="String(lv.id)">{{ lv.name }}</el-checkbox>
          </el-checkbox-group>
        </el-form-item>

        <el-form-item label="开始时间：">
          <el-date-picker v-model="form.starttime" type="datetime" value-format="YYYY-MM-DD HH:mm:ss" placeholder="活动开始时间" />
        </el-form-item>

        <el-form-item label="结束时间：">
          <el-date-picker v-model="form.endtime" type="datetime" value-format="YYYY-MM-DD HH:mm:ss" placeholder="活动结束时间" />
        </el-form-item>

        <el-form-item label="序号：">
          <el-input v-model.number="form.sort" class="w-num" />
          <span class="aux">用于排序,越大越靠前</span>
        </el-form-item>

        <el-form-item>
          <el-button type="success" :loading="saving" @click="handleSave">提 交</el-button>
        </el-form-item>
      </el-form>
    </el-card>

    <!-- 类目选择 -->
    <el-dialog v-model="catPickerVisible" title="选择商品分类" width="640px">
      <el-table ref="catTableRef" :data="categoryOptions" size="small" row-key="id" @selection-change="onCatSelectionChange">
        <el-table-column type="selection" width="42" />
        <el-table-column prop="id" label="分类ID" width="100" />
        <el-table-column prop="name" label="分类名称" />
      </el-table>
      <template #footer>
        <el-button @click="catPickerVisible = false">取消</el-button>
        <el-button type="success" @click="confirmCatPicker">确定</el-button>
      </template>
    </el-dialog>

    <!-- 商品选择 -->
    <el-dialog v-model="proPickerVisible" title="选择商品" width="760px">
      <el-table ref="proTableRef" :data="goodsOptions" size="small" row-key="id" @selection-change="onProSelectionChange">
        <el-table-column type="selection" width="42" />
        <el-table-column prop="id" label="商品ID" width="100" />
        <el-table-column prop="name" label="商品名称" show-overflow-tooltip />
      </el-table>
      <template #footer>
        <el-button @click="proPickerVisible = false">取消</el-button>
        <el-button type="success" @click="confirmProPicker">确定</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped>
.page-wrap {
  margin: 3px;
}
.tip-bar {
  background: #f5f7fa;
  border-left: 3px solid #5fb878;
  padding: 8px 12px;
  margin-bottom: 3px;
  font-size: 13px;
  color: #606266;
}
.toolbar-card { margin-bottom: 3px; }
.toolbar-card :deep(.el-card__body) { padding: 5px; }
.toolbar-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  flex-wrap: nowrap;
}
.toolbar-left { display: flex; gap: 8px; flex-wrap: nowrap; }
.toolbar-right { display: flex; align-items: center; gap: 6px; flex-wrap: nowrap; }
.label { font-size: 13px; color: #606266; }
.w-keyword { width: 180px; }
.table-card :deep(.el-card__body) { padding: 5px; }
.table-card :deep(.el-table .cell) { text-align: left; padding: 3px 0; }
.pagination { margin-top: 3px; justify-content: flex-end; }
.op-flex { display: flex; flex-direction: row; flex-wrap: wrap; gap: 4px; }
.edit-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  font-weight: 600;
}
.compact-form :deep(.el-form-item) { margin-bottom: 10px; }
.compact-form :deep(.el-radio) { margin-right: 12px; }
.w-tip { width: 160px; }
.w-name { width: 320px; }
.w-num { width: 160px; }
.unit { margin: 0 8px 0 4px; color: #606266; }
.aux { margin-left: 12px; color: #909399; font-size: 12px; }
.inner-table { width: 560px; margin-bottom: 6px; }
.inner-add { margin-top: 4px; }
</style>
