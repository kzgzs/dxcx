<script setup lang="ts">
import { nextTick, onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Close, Search } from '@element-plus/icons-vue'
import {
  addCashback,
  deleteCashback,
  deleteCashbackBatch,
  editCashback,
  getCashbackDetail,
  getCashbackList,
  getCashbackMemberList,
  getCashbackMemberLogList,
  type CashbackItem,
  type CashbackMemberLogRow,
  type CashbackMemberRow,
} from '../../api/cashback'
import { getCategoryList, getGoodsList } from '../../api/goods'
import { getMemberLevelList } from '../../api/member'
import type { CategoryItem, GoodsItem, MemberLevelItem } from '../../api/types'

const BACK_TYPE_OPTIONS = [
  { value: 1, label: '余额' },
  { value: 2, label: '佣金' },
  { value: 3, label: '积分' },
]
const BACK_TYPE_TEXT: Record<number, string> = { 1: '余额', 2: '佣金', 3: '积分' }

// ============ 视图切换：list / edit / record ============
const viewMode = ref<'list' | 'edit' | 'record'>('list')

// ============ 规则列表 ============
const loading = ref(false)
const list = ref<CashbackItem[]>([])
const total = ref(0)
const selected = ref<CashbackItem[]>([])
const query = reactive({ page: 1, pagesize: 10, keyword: '' })

function fmtTs(ts: number | string): string {
  if (!ts) return '-'
  const n = typeof ts === 'number' ? ts * 1000 : new Date(String(ts).replace(' ', 'T')).getTime()
  return new Date(n).toLocaleString('zh-CN', { hour12: false })
}

async function fetchList() {
  loading.value = true
  try {
    const d = await getCashbackList(query)
    list.value = d.list || []
    total.value = d.total || 0
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

function handleSelectionChange(rows: CashbackItem[]) {
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

function handleDelete(row: CashbackItem) {
  ElMessageBox.confirm(`确定删除规则「${row.name}」？`, '提示', { type: 'warning' })
    .then(async () => {
      await deleteCashback(row.id)
      ElMessage.success('删除成功')
      fetchList()
    })
    .catch(() => {})
}

function handleBatchDelete() {
  if (selected.value.length === 0) {
    ElMessage.warning('请先勾选要删除的规则')
    return
  }
  ElMessageBox.confirm(`确定删除选中的 ${selected.value.length} 个规则？`, '批量删除', { type: 'warning' })
    .then(async () => {
      await deleteCashbackBatch(selected.value.map((r) => r.id))
      ElMessage.success('删除成功')
      fetchList()
    })
    .catch(() => {})
}

function statusLabel(row: CashbackItem): { text: string; type: 'info' | 'success' | 'danger' } {
  const now = Date.now() / 1000
  if (row.starttime && now < row.starttime) return { text: '未开始', type: 'info' }
  if (row.endtime && now > row.endtime) return { text: '已结束', type: 'info' }
  return { text: '进行中', type: 'success' }
}

// ============ 编辑表单 ============
const saving = ref(false)
const isEdit = ref(false)

interface CategoryRow {
  id: number
  name: string
}
interface ProductRow {
  id: number
  name: string
}

const form = reactive({
  id: 0,
  name: '',
  back_ratio: 0,
  back_type: 1,
  fwtype: 0,
  categoryRows: [] as CategoryRow[],
  productRows: [] as ProductRow[],
  gettjArr: ['-1'] as string[],
  starttime: '',
  endtime: '',
  sort: 0,
  shuoming: '',
})

const levelOptions = ref<MemberLevelItem[]>([])
const categoryOptions = ref<CategoryItem[]>([])
const goodsOptions = ref<GoodsItem[]>([])

function onGettjChange(val: string[]) {
  if (val.includes('-1')) form.gettjArr = ['-1']
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
    name: '',
    back_ratio: 0,
    back_type: 1,
    fwtype: 0,
    categoryRows: [],
    productRows: [],
    gettjArr: ['-1'],
    starttime: '',
    endtime: '',
    sort: 0,
    shuoming: '',
  })
}

function openAdd() {
  isEdit.value = false
  resetForm()
  viewMode.value = 'edit'
}

async function openEdit(row: CashbackItem) {
  isEdit.value = true
  try {
    const d = await getCashbackDetail(row.id)
    const cids = d.categoryids ? d.categoryids.split(',').filter(Boolean).map(Number) : []
    const pids = d.productids ? d.productids.split(',').filter(Boolean).map(Number) : []
    Object.assign(form, {
      id: d.id,
      name: d.name,
      back_ratio: d.back_ratio,
      back_type: d.back_type,
      fwtype: d.fwtype,
      categoryRows: cids.map((id) => ({ id, name: categoryOptions.value.find((x) => x.id === id)?.name || '' })),
      productRows: pids.map((id) => ({ id, name: goodsOptions.value.find((x) => x.id === id)?.name || '' })),
      gettjArr: d.gettj ? d.gettj.split(',') : ['-1'],
      starttime: d.starttime ? fmtTs(d.starttime) : '',
      endtime: d.endtime ? fmtTs(d.endtime) : '',
      sort: d.sort,
      shuoming: d.shuoming || '',
    })
    viewMode.value = 'edit'
  } catch {
    // 拦截器统一提示
  }
}

function handleCancelEdit() {
  viewMode.value = 'list'
}

// ============ 选择器弹窗 ============
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
  form.categoryRows = catChecked.value.map((id) => ({ id, name: categoryOptions.value.find((x) => x.id === id)?.name || '' }))
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
  form.productRows = proChecked.value.map((id) => ({ id, name: goodsOptions.value.find((x) => x.id === id)?.name || '' }))
  proPickerVisible.value = false
}
function removeProductRow(id: number) {
  form.productRows = form.productRows.filter((r) => r.id !== id)
}

function toTs(s: string): number {
  if (!s) return 0
  return Math.floor(new Date(s.replace(' ', 'T')).getTime() / 1000)
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
    name: form.name,
    back_ratio: form.back_ratio,
    back_type: form.back_type,
    gettj: form.gettjArr.includes('-1') ? '-1' : form.gettjArr.join(','),
    fwtype: form.fwtype,
    categoryids: form.categoryRows.map((r) => r.id).join(','),
    productids: form.productRows.map((r) => r.id).join(','),
    starttime: toTs(form.starttime),
    endtime: toTs(form.endtime),
    sort: form.sort,
    shuoming: form.shuoming,
  }
  saving.value = true
  try {
    if (isEdit.value) {
      await editCashback(payload)
    } else {
      await addCashback(payload)
    }
    ElMessage.success('保存成功')
    viewMode.value = 'list'
    fetchList()
  } catch {
    // 拦截器统一提示
  } finally {
    saving.value = false
  }
}

// ============ 参与人列表（平铺页） ============
const recordLoading = ref(false)
const recordRows = ref<CashbackMemberRow[]>([])
const recordTotal = ref(0)
const recordQuery = reactive({ page: 1, pagesize: 10, mid: '', nickname: '', ctime: '' })
const ctimeRange = ref<[string, string] | null>(null)
const recordCashbackId = ref(0)
const recordName = ref('')

async function openRecord(row: CashbackItem) {
  recordCashbackId.value = row.id
  recordName.value = row.name
  recordQuery.page = 1
  recordQuery.mid = ''
  recordQuery.nickname = ''
  recordQuery.ctime = ''
  ctimeRange.value = null
  viewMode.value = 'record'
  await fetchRecords()
}

async function fetchRecords() {
  recordLoading.value = true
  try {
    const d = await getCashbackMemberList({
      cashback_id: recordCashbackId.value,
      page: recordQuery.page,
      pagesize: recordQuery.pagesize,
      mid: recordQuery.mid,
      nickname: recordQuery.nickname,
      ctime: recordQuery.ctime,
    })
    recordRows.value = d.list || []
    recordTotal.value = d.total || 0
  } catch {
    // 拦截器统一提示
  } finally {
    recordLoading.value = false
  }
}

function handleRecordSearch() {
  recordQuery.page = 1
  recordQuery.ctime = ctimeRange.value ? ctimeRange.value.join('~') : ''
  fetchRecords()
}

function handleRecordPageChange(p: number) {
  recordQuery.page = p
  fetchRecords()
}

function handleRecordSizeChange(s: number) {
  recordQuery.pagesize = s
  recordQuery.page = 1
  fetchRecords()
}

function quotaText(row: CashbackMemberRow): string {
  if (!row.cashback_money_max || row.cashback_money_max <= 0) return '不限'
  return String(row.cashback_money_max)
}

// 返现记录（流水）弹窗
const logVisible = ref(false)
const logLoading = ref(false)
const logRows = ref<CashbackMemberLogRow[]>([])
const logTitle = ref('')

async function openLog(row: CashbackMemberRow) {
  logTitle.value = `返现记录 - ${row.nickname}(ID:${row.mid})`
  logVisible.value = true
  logLoading.value = true
  try {
    const d = await getCashbackMemberLogList({
      cashback_id: recordCashbackId.value,
      page: 1,
      pagesize: 50,
    })
    // 按 会员+商品 过滤
    logRows.value = (d.list || []).filter((l) => l.mid === row.mid && l.pro_id === row.pro_id)
  } catch {
    // 拦截器统一提示
  } finally {
    logLoading.value = false
  }
}

onMounted(() => {
  fetchList()
  loadCandidates()
})
</script>

<template>
  <div class="page-wrap">
    <!-- ============ 规则列表 ============ -->
    <template v-if="viewMode === 'list'">
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
          <el-table-column prop="name" label="活动名称" min-width="140" show-overflow-tooltip />
          <el-table-column label="返现比例(%)" width="110">
            <template #default="{ row }">{{ row.back_ratio }}</template>
          </el-table-column>
          <el-table-column label="返现类型" width="90">
            <template #default="{ row }">{{ BACK_TYPE_TEXT[row.back_type] || '-' }}</template>
          </el-table-column>
          <el-table-column label="活动时间" min-width="220" show-overflow-tooltip>
            <template #default="{ row }">{{ fmtTs(row.starttime) }} ~ {{ fmtTs(row.endtime) }}</template>
          </el-table-column>
          <el-table-column label="创建时间" width="150">
            <template #default="{ row }">{{ fmtTs(row.create_time) }}</template>
          </el-table-column>
          <el-table-column label="状态" width="90">
            <template #default="{ row }">
              <el-tag :type="statusLabel(row).type" size="small">{{ statusLabel(row).text }}</el-tag>
            </template>
          </el-table-column>
          <el-table-column label="操作" width="180">
            <template #default="{ row }">
              <div class="op-flex">
                <el-button link type="primary" @click="openEdit(row)">编辑</el-button>
                <el-button link type="danger" @click="handleDelete(row)">删除</el-button>
                <el-button link type="primary" @click="openRecord(row)">参与人列表</el-button>
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

    <!-- ============ 添加/编辑 ============ -->
    <el-card v-else-if="viewMode === 'edit'" shadow="never" class="edit-card">
      <template #header>
        <div class="edit-header">
          <span>{{ isEdit ? '编辑购物返现' : '添加购物返现' }}</span>
          <el-button link :icon="Close" @click="handleCancelEdit" />
        </div>
      </template>

      <el-form label-width="110px" class="compact-form">
        <el-form-item label="活动名称：">
          <el-input v-model="form.name" maxlength="50" class="w-name" />
        </el-form-item>

        <el-form-item label="返现比例：">
          <el-input v-model.number="form.back_ratio" class="w-num" />
          <span class="unit">%</span>
        </el-form-item>

        <el-form-item label="返现类型：">
          <el-radio-group v-model="form.back_type">
            <el-radio v-for="o in BACK_TYPE_OPTIONS" :key="o.value" :value="o.value">{{ o.label }}</el-radio>
          </el-radio-group>
          <span class="aux">注：确认收货后返还到用户的余额/佣金/积分账户</span>
        </el-form-item>

        <el-form-item label="选择活动商品：">
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
            <el-table-column label="操作" width="100">
              <template #default="{ row }">
                <el-button link type="danger" @click="removeCategoryRow(row.id)">删除</el-button>
              </template>
            </el-table-column>
          </el-table>
          <el-button size="small" class="inner-add" @click="openCatPicker">添加</el-button>
        </el-form-item>

        <el-form-item v-if="form.fwtype === 2" label-width="110px">
          <el-table :data="form.productRows" size="small" class="inner-table" :header-cell-style="{ background: '#e6e6e6' }">
            <el-table-column prop="id" label="商品ID" width="120" />
            <el-table-column prop="name" label="商品名称" />
            <el-table-column label="操作" width="100">
              <template #default="{ row }">
                <el-button link type="danger" @click="removeProductRow(row.id)">删除</el-button>
              </template>
            </el-table-column>
          </el-table>
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

        <el-form-item label="返现说明：">
          <el-input v-model="form.shuoming" type="textarea" :rows="3" class="w-name" maxlength="500" />
        </el-form-item>

        <el-form-item>
          <el-button type="success" :loading="saving" @click="handleSave">提 交</el-button>
        </el-form-item>
      </el-form>
    </el-card>

    <!-- ============ 参与人列表（平铺页） ============ -->
    <el-card v-else shadow="never" class="edit-card">
      <template #header>
        <div class="edit-header">
          <span>参与列表 - {{ recordName }}</span>
          <el-button link :icon="Close" @click="viewMode = 'list'" />
        </div>
      </template>

      <div class="tip-bar">同一会员购买同一商品时，购买数量会自动叠加；购买不同商品时，会分别生成独立额度记录。</div>

      <div class="record-search">
        <span class="label">用户ID</span>
        <el-input v-model="recordQuery.mid" clearable class="w-search" @keyup.enter="handleRecordSearch" />
        <span class="label">昵称</span>
        <el-input v-model="recordQuery.nickname" clearable class="w-search" @keyup.enter="handleRecordSearch" />
        <span class="label">时间</span>
        <el-date-picker v-model="ctimeRange" type="daterange" value-format="YYYY-MM-DD" class="w-search" />
        <el-button circle type="success" :icon="Search" @click="handleRecordSearch" />
      </div>

      <el-table
        v-loading="recordLoading"
        :data="recordRows"
        :header-cell-style="{ background: '#e6e6e6' }"
        :cell-style="{ textAlign: 'left' }"
      >
        <el-table-column type="selection" width="42" />
        <el-table-column prop="id" label="ID" width="70" sortable />
        <el-table-column label="用户信息" min-width="180">
          <template #default="{ row }">
            <img v-if="row.headimg" :src="row.headimg" style="width:32px;height:32px;border-radius:50%;vertical-align:middle" />
            {{ row.nickname }}(ID:{{ row.mid }})
          </template>
        </el-table-column>
        <el-table-column label="额度" width="100">
          <template #default="{ row }">{{ quotaText(row) }}</template>
        </el-table-column>
        <el-table-column label="已返金额" width="100">
          <template #default="{ row }">{{ row.back_type === 1 ? row.cashback_money : row.cashback_money }}</template>
        </el-table-column>
        <el-table-column label="已返佣金" width="100">
          <template #default="{ row }">{{ row.commission }}</template>
        </el-table-column>
        <el-table-column label="已返积分" width="100">
          <template #default="{ row }">{{ row.score }}</template>
        </el-table-column>
        <el-table-column prop="pro_num" label="购买数量" width="90" />
        <el-table-column label="时间" width="160">
          <template #default="{ row }">{{ row.create_time }}</template>
        </el-table-column>
        <el-table-column label="操作" width="110">
          <template #default="{ row }">
            <el-button link type="primary" @click="openLog(row)">返现记录</el-button>
          </template>
        </el-table-column>
      </el-table>

      <el-pagination
        class="pagination"
        background
        layout="total, sizes, prev, pager, next, jumper"
        :total="recordTotal"
        :current-page="recordQuery.page"
        :page-size="recordQuery.pagesize"
        :page-sizes="[10, 20, 50, 100]"
        @current-change="handleRecordPageChange"
        @size-change="handleRecordSizeChange"
      />
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

    <!-- 返现流水 -->
    <el-dialog v-model="logVisible" :title="logTitle" width="720px">
      <el-table v-loading="logLoading" :data="logRows" size="small">
        <el-table-column prop="id" label="ID" width="70" />
        <el-table-column label="金额" width="100">
          <template #default="{ row }">{{ row.cashback_money }}</template>
        </el-table-column>
        <el-table-column label="佣金" width="100">
          <template #default="{ row }">{{ row.commission }}</template>
        </el-table-column>
        <el-table-column label="积分" width="100">
          <template #default="{ row }">{{ row.score }}</template>
        </el-table-column>
        <el-table-column prop="create_time" label="时间" min-width="160" />
      </el-table>
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
  margin-bottom: 8px;
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
.w-name { width: 320px; }
.w-num { width: 160px; }
.unit { margin: 0 8px 0 4px; color: #606266; }
.aux { margin-left: 12px; color: #909399; font-size: 12px; }
.inner-table { width: 560px; margin-bottom: 6px; }
.inner-add { margin-top: 4px; }
.record-search {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
  margin-bottom: 8px;
}
.w-search { width: 160px; }
</style>
