<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Plus, Search } from '@element-plus/icons-vue'
import {
  addCashback,
  deleteCashback,
  editCashback,
  getCashbackDetail,
  getCashbackList,
  getCashbackMemberList,
  getCashbackMemberLogList,
  setCashbackStatus,
  type CashbackItem,
  type CashbackMemberLogRow,
  type CashbackMemberRow,
} from '../../api/cashback'

const BACK_TYPE_OPTIONS = [
  { value: 1, label: '返余额' },
  { value: 2, label: '返佣金' },
  { value: 3, label: '返积分' },
]
const BACK_TYPE_TEXT: Record<number, string> = { 1: '余额', 2: '佣金', 3: '积分' }

function formatTs(ts: number) {
  if (!ts) return '-'
  const d = new Date(ts * 1000)
  const p = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}:${p(d.getSeconds())}`
}

// ============ 规则列表 ============
const loading = ref(false)
const list = ref<CashbackItem[]>([])
const total = ref(0)
const query = reactive({ page: 1, pagesize: 10, keyword: '', status: '' })

async function fetchList() {
  loading.value = true
  try {
    const d = await getCashbackList(query)
    list.value = d.list || []
    total.value = d.total || 0
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

async function handleStatus(row: CashbackItem, status: number) {
  try {
    await setCashbackStatus(row.id, status)
    ElMessage.success(status === 1 ? '已启用' : '已停用')
    fetchList()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  }
}

async function handleDelete(row: CashbackItem) {
  try {
    await ElMessageBox.confirm(`确定删除规则「${row.name}」？`, '提示', { type: 'warning' })
    await deleteCashback(row.id)
    ElMessage.success('删除成功')
    fetchList()
  } catch {
    // 取消/已有参与记录后端会拒绝
  }
}

// ============ 表单弹窗 ============
const dialogVisible = ref(false)
const saving = ref(false)
const isEdit = ref(false)

const form = reactive({
  id: 0,
  name: '',
  back_ratio: 0,
  back_type: 1,
  receiver_type: 1,
  goods_multiple_max: 0,
  gettj: '-1',
  fwtype: 0,
  categoryids: '',
  productids: '',
  minprice: 0,
  sort: 0,
  shuoming: '',
})
const timeRange = ref<[Date, Date] | null>(null)

function openAdd() {
  isEdit.value = false
  Object.assign(form, {
    id: 0,
    name: '',
    back_ratio: 0,
    back_type: 1,
    receiver_type: 1,
    goods_multiple_max: 0,
    gettj: '-1',
    fwtype: 0,
    categoryids: '',
    productids: '',
    minprice: 0,
    sort: 0,
    shuoming: '',
  })
  timeRange.value = null
  dialogVisible.value = true
}

async function openEdit(row: CashbackItem) {
  isEdit.value = true
  try {
    const d = await getCashbackDetail(row.id)
    Object.assign(form, {
      id: d.id,
      name: d.name,
      back_ratio: d.back_ratio,
      back_type: d.back_type,
      receiver_type: d.receiver_type,
      goods_multiple_max: d.goods_multiple_max,
      gettj: d.gettj || '-1',
      fwtype: d.fwtype,
      categoryids: d.categoryids,
      productids: d.productids,
      minprice: d.minprice,
      sort: d.sort,
      shuoming: d.shuoming || '',
    })
    timeRange.value =
      d.starttime && d.endtime ? [new Date(d.starttime * 1000), new Date(d.endtime * 1000)] : null
    dialogVisible.value = true
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  }
}

async function handleSave() {
  if (!form.name) {
    ElMessage.warning('请填写规则名称')
    return
  }
  if (form.back_ratio <= 0 || form.back_ratio > 100) {
    ElMessage.warning('返现比例必须在 0-100 之间')
    return
  }
  const payload: Partial<CashbackItem> = {
    ...form,
    starttime: timeRange.value && timeRange.value[0] ? Math.floor(timeRange.value[0].getTime() / 1000) : 0,
    endtime: timeRange.value && timeRange.value[1] ? Math.floor(timeRange.value[1].getTime() / 1000) : 0,
  }
  saving.value = true
  try {
    if (isEdit.value) {
      await editCashback(payload)
    } else {
      await addCashback(payload)
    }
    ElMessage.success('保存成功')
    dialogVisible.value = false
    fetchList()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    saving.value = false
  }
}

// ============ 参与记录 ============
const recordVisible = ref(false)
const recordTab = ref<'member' | 'log'>('member')
const recordTitle = ref('')
const recordLoading = ref(false)
const memberRows = ref<CashbackMemberRow[]>([])
const logRows = ref<CashbackMemberLogRow[]>([])
const recordTotal = ref(0)
const recordPage = ref(1)
const recordCashbackId = ref(0)

async function openRecords(row: CashbackItem) {
  recordCashbackId.value = row.id
  recordTitle.value = `参与记录 - ${row.name}`
  recordTab.value = 'member'
  recordPage.value = 1
  recordVisible.value = true
  fetchRecords()
}

async function fetchRecords() {
  recordLoading.value = true
  try {
    if (recordTab.value === 'member') {
      const d = await getCashbackMemberList({ cashback_id: recordCashbackId.value, page: recordPage.value, pagesize: 10 })
      memberRows.value = d.list || []
      recordTotal.value = d.total || 0
    } else {
      const d = await getCashbackMemberLogList({ cashback_id: recordCashbackId.value, page: recordPage.value, pagesize: 10 })
      logRows.value = d.list || []
      recordTotal.value = d.total || 0
    }
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    recordLoading.value = false
  }
}

onMounted(() => {
  fetchList()
})
</script>

<template>
  <el-card shadow="never">
    <template #header>
      <div class="card-header">
        <div class="toolbar">
          <el-input
            v-model="query.keyword"
            placeholder="规则名称"
            clearable
            style="width: 180px"
            @keyup.enter="handleSearch"
          />
          <el-select v-model="query.status" placeholder="状态" clearable style="width: 110px">
            <el-option label="启用" value="1" />
            <el-option label="停用" value="0" />
          </el-select>
          <el-button type="primary" :icon="Search" @click="handleSearch">查询</el-button>
        </div>
        <el-button type="primary" :icon="Plus" @click="openAdd">新建规则</el-button>
      </div>
    </template>

    <el-table v-loading="loading" :data="list" border>
      <el-table-column prop="id" label="ID" width="70" />
      <el-table-column prop="name" label="规则名称" min-width="140" />
      <el-table-column label="返现比例" width="100">
        <template #default="{ row }">{{ row.back_ratio }}%</template>
      </el-table-column>
      <el-table-column label="返现方式" width="90">
        <template #default="{ row }">{{ BACK_TYPE_TEXT[row.back_type] || '-' }}</template>
      </el-table-column>
      <el-table-column label="限额倍数" width="100">
        <template #default="{ row }">{{ row.goods_multiple_max > 0 ? row.goods_multiple_max + '倍' : '不限' }}</template>
      </el-table-column>
      <el-table-column label="活动时间" min-width="220">
        <template #default="{ row }">{{ formatTs(row.starttime) }} ~ {{ formatTs(row.endtime) }}</template>
      </el-table-column>
      <el-table-column label="状态" width="90">
        <template #default="{ row }">
          <el-switch
            :model-value="row.status"
            :active-value="1"
            :inactive-value="0"
            @change="(v: number) => handleStatus(row, v)"
          />
        </template>
      </el-table-column>
      <el-table-column label="操作" width="190" fixed="right">
        <template #default="{ row }">
          <el-button link type="primary" @click="openRecords(row)">参与记录</el-button>
          <el-button link type="primary" @click="openEdit(row)">编辑</el-button>
          <el-button link type="danger" @click="handleDelete(row)">删除</el-button>
        </template>
      </el-table-column>
    </el-table>

    <el-pagination
      v-model:current-page="query.page"
      v-model:page-size="query.pagesize"
      :total="total"
      layout="total, prev, pager, next, sizes"
      style="margin-top: 12px"
      @current-change="fetchList"
      @size-change="handleSearch"
    />

    <!-- 规则编辑弹窗 -->
    <el-dialog v-model="dialogVisible" :title="isEdit ? '编辑规则' : '新建规则'" width="640px">
      <el-form label-width="130px">
        <el-form-item label="规则名称">
          <el-input v-model="form.name" maxlength="50" />
        </el-form-item>
        <el-form-item label="返现比例">
          <el-input-number v-model="form.back_ratio" :min="0" :max="100" :precision="2" />
          <span class="unit">%（按商品实付金额计算）</span>
        </el-form-item>
        <el-form-item label="返现方式">
          <el-radio-group v-model="form.back_type">
            <el-radio v-for="o in BACK_TYPE_OPTIONS" :key="o.value" :value="o.value">{{ o.label }}</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item label="受益人">
          <el-radio-group v-model="form.receiver_type">
            <el-radio :value="1">下单会员</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item label="限额倍数">
          <el-input-number v-model="form.goods_multiple_max" :min="0" :precision="2" />
          <span class="unit">商品售价倍数封顶，0 表示不限</span>
        </el-form-item>
        <el-form-item label="参与人群">
          <el-input v-model="form.gettj" placeholder="-1 表示全部；多个会员等级ID用逗号分隔" style="width: 320px" />
        </el-form-item>
        <el-form-item label="适用范围">
          <el-radio-group v-model="form.fwtype">
            <el-radio :value="0">全部商品</el-radio>
            <el-radio :value="1">指定分类</el-radio>
            <el-radio :value="2">指定商品</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item v-if="form.fwtype === 1" label="分类ID">
          <el-input v-model="form.categoryids" placeholder="多个用逗号分隔" />
        </el-form-item>
        <el-form-item v-if="form.fwtype === 2" label="商品ID">
          <el-input v-model="form.productids" placeholder="多个用逗号分隔" />
        </el-form-item>
        <el-form-item label="单品门槛金额">
          <el-input-number v-model="form.minprice" :min="0" :precision="2" />
          <span class="unit">元（单品实付低于该值不返现，0 表示不限）</span>
        </el-form-item>
        <el-form-item label="活动时间">
          <el-date-picker
            v-model="timeRange"
            type="datetimerange"
            range-separator="至"
            start-placeholder="开始时间"
            end-placeholder="结束时间"
          />
        </el-form-item>
        <el-form-item label="排序">
          <el-input-number v-model="form.sort" :min="0" />
        </el-form-item>
        <el-form-item label="说明">
          <el-input v-model="form.shuoming" type="textarea" :rows="2" maxlength="200" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="saving" @click="handleSave">保存</el-button>
      </template>
    </el-dialog>

    <!-- 参与记录弹窗 -->
    <el-dialog v-model="recordVisible" :title="recordTitle" width="860px">
      <el-tabs v-model="recordTab" @tab-change="fetchRecords">
        <el-tab-pane label="会员台账" name="member">
          <el-table v-loading="recordLoading" :data="memberRows" border size="small">
            <el-table-column prop="mid" label="会员ID" width="80" />
            <el-table-column prop="phone" label="手机号" width="120" />
            <el-table-column prop="nickname" label="昵称" min-width="100" />
            <el-table-column prop="pro_num" label="参与件数" width="90" />
            <el-table-column prop="cashback_money" label="已返余额" width="100" />
            <el-table-column prop="cashback_money_max" label="限额" width="100" />
            <el-table-column prop="score" label="已返积分" width="90" />
            <el-table-column prop="commission" label="已返佣金" width="100" />
            <el-table-column prop="create_time" label="参与时间" min-width="160" />
          </el-table>
        </el-tab-pane>
        <el-tab-pane label="返现流水" name="log">
          <el-table v-loading="recordLoading" :data="logRows" border size="small">
            <el-table-column prop="mid" label="会员ID" width="80" />
            <el-table-column prop="phone" label="手机号" width="120" />
            <el-table-column prop="nickname" label="昵称" min-width="100" />
            <el-table-column prop="cashback_money" label="返余额" width="90" />
            <el-table-column prop="score" label="返积分" width="90" />
            <el-table-column prop="commission" label="返佣金" width="90" />
            <el-table-column prop="create_time" label="返现时间" min-width="160" />
          </el-table>
        </el-tab-pane>
      </el-tabs>
      <el-pagination
        v-model:current-page="recordPage"
        :total="recordTotal"
        layout="total, prev, pager, next"
        style="margin-top: 12px"
        @current-change="fetchRecords"
      />
    </el-dialog>
  </el-card>
</template>

<style scoped>
.card-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.toolbar {
  display: flex;
  gap: 8px;
}
.unit {
  margin-left: 8px;
  color: #606266;
  font-size: 12px;
}
</style>
