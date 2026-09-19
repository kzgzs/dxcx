<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  copyForm,
  deleteForm,
  formRecordDel,
  formRecordSetst,
  getFormDetail,
  getFormList,
  getFormRecord,
  saveForm,
} from '../../api/adminform'
import type { FormItem, FormOrderItem } from '../../api/adminform'

// 商户过滤视图（bid='gt0'）由 tenantMenus merchantViewOf 注入（form-index-showtype-2 双挂）
const props = withDefaults(defineProps<{ bid?: string }>(), { bid: '' })
const isMerchantView = computed(() => props.bid === 'gt0' || props.bid === 'merchant')

// ============ 列表 ============
const loading = ref(false)
const list = ref<FormItem[]>([])
const total = ref(0)
const query = reactive({ page: 1, pagesize: 10, name: '' })

async function fetchList() {
  loading.value = true
  try {
    const res = await getFormList({ page: query.page, pagesize: query.pagesize, name: query.name, bid: props.bid })
    list.value = res.list || []
    total.value = res.total || 0
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

function formatTime(ts: number | string) {
  const n = Number(ts)
  if (!n) return '-'
  const d = new Date(n * 1000)
  const p = (x: number) => String(x).padStart(2, '0')
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}`
}

// ============ 新增 / 编辑弹窗 ============
const dialogVisible = ref(false)
const isEdit = ref(false)
const submitting = ref(false)

const form = reactive({
  id: 0,
  bid: 0,
  name: '',
  starttime: '',
  endtime: '',
  content: '',
  price: '',
  maxlimit: '',
  perlimit: '',
  sort: 0,
  submit_tourl: '',
})

function resetForm() {
  Object.assign(form, { id: 0, bid: 0, name: '', starttime: '', endtime: '', content: '', price: '', maxlimit: '', perlimit: '', sort: 0, submit_tourl: '' })
}

function openAdd() {
  isEdit.value = false
  resetForm()
  dialogVisible.value = true
}

async function openEdit(row: FormItem) {
  isEdit.value = true
  resetForm()
  try {
    const detail = await getFormDetail(row.id)
    Object.assign(form, {
      id: detail.id,
      bid: detail.bid,
      name: detail.name,
      starttime: detail.starttime || '',
      endtime: detail.endtime || '',
      content: detail.content || '',
      price: detail.price || '',
      maxlimit: detail.maxlimit || '',
      perlimit: detail.perlimit || '',
      sort: Number(detail.sort) || 0,
      submit_tourl: detail.submit_tourl || '',
    })
    dialogVisible.value = true
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  }
}

async function handleSubmit() {
  if (!form.name.trim()) {
    ElMessage.warning('请填写表单名称')
    return
  }
  submitting.value = true
  try {
    await saveForm({ ...form, id: form.id || undefined })
    ElMessage.success('保存成功')
    dialogVisible.value = false
    fetchList()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    submitting.value = false
  }
}

function handleDelete(row: FormItem) {
  ElMessageBox.confirm(`确定要删除表单「${row.name}」吗？其提交记录将一并删除。`, '删除确认', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await deleteForm([row.id])
        ElMessage.success('删除成功')
        fetchList()
      } catch {
        // 错误提示已由 axios 拦截器统一弹出
      }
    })
    .catch(() => {})
}

async function handleCopy(row: FormItem) {
  try {
    await copyForm(row.id)
    ElMessage.success('复制成功')
    fetchList()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  }
}

function handleLink(row: FormItem) {
  const link = row.submit_tourl || `/pages/form/index?id=${row.id}`
  navigator.clipboard
    .writeText(link)
    .then(() => ElMessage.success('表单链接已复制'))
    .catch(() => ElMessage.info(`表单链接：${link}`))
}

// ============ 数据（提交记录）弹窗 ============
const recordVisible = ref(false)
const recordLoading = ref(false)
const recordList = ref<FormOrderItem[]>([])
const recordTotal = ref(0)
const recordPage = ref(1)
const recordTarget = ref<FormItem | null>(null)
const recordQuery = reactive({ status: '', keyword: '' })

async function openRecord(row: FormItem) {
  recordTarget.value = row
  recordPage.value = 1
  recordQuery.status = ''
  recordQuery.keyword = ''
  recordVisible.value = true
  await fetchRecord()
}

async function fetchRecord() {
  if (!recordTarget.value) return
  recordLoading.value = true
  try {
    const res = await getFormRecord({
      page: recordPage.value,
      pagesize: 10,
      formid: recordTarget.value.id,
      status: recordQuery.status,
      keyword: recordQuery.keyword,
      bid: props.bid,
    })
    recordList.value = res.list || []
    recordTotal.value = res.total || 0
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    recordLoading.value = false
  }
}

function handleRecordSearch() {
  recordPage.value = 1
  fetchRecord()
}

async function handleRecordSetst(row: FormOrderItem, st: number) {
  let reason = ''
  if (st === 2) {
    try {
      const res = await ElMessageBox.prompt('请填写驳回原因', '驳回确认', {
        confirmButtonText: '驳回',
        cancelButtonText: '取消',
        inputPlaceholder: '驳回原因',
      })
      reason = res.value || ''
    } catch {
      return
    }
  } else {
    try {
      await ElMessageBox.confirm('确定确认该条记录吗？', '操作确认', { type: 'warning' })
    } catch {
      return
    }
  }
  try {
    await formRecordSetst([row.id], st, reason)
    ElMessage.success(st === 1 ? '已确认' : '已驳回')
    fetchRecord()
    fetchList()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  }
}

async function handleRecordDel(row: FormOrderItem) {
  try {
    await ElMessageBox.confirm('确定删除该条记录吗？', '删除确认', { type: 'warning' })
  } catch {
    return
  }
  try {
    await formRecordDel([row.id])
    ElMessage.success('删除成功')
    fetchRecord()
    fetchList()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  }
}

// 记录详情弹窗（展示 form0~form50 非空字段）
const detailVisible = ref(false)
const detailRow = ref<FormOrderItem | null>(null)

function openDetail(row: FormOrderItem) {
  detailRow.value = row
  detailVisible.value = true
}

function formFields(row: FormOrderItem): { key: string; value: string }[] {
  const result: { key: string; value: string }[] = []
  for (let i = 0; i <= 50; ++i) {
    const v = row['form' + i]
    if (v !== undefined && v !== null && String(v) !== '') {
      result.push({ key: 'form' + i, value: String(v) })
    }
  }
  return result
}

onMounted(fetchList)
</script>

<template>
  <div>
    <el-card shadow="never">
      <template #header>
        <div class="card-header">
          <span>{{ isMerchantView ? '商户自定义表单' : '自定义表单' }}</span>
          <div>
            <el-input
              v-model="query.name"
              placeholder="表单名称"
              clearable
              style="width: 200px; margin-right: 8px"
              @keyup.enter="handleSearch"
              @clear="handleSearch"
            />
            <el-button @click="handleSearch">搜索</el-button>
            <el-button type="success" @click="openAdd">新增表单</el-button>
          </div>
        </div>
      </template>

      <el-alert
        type="info"
        :closable="false"
        show-icon
        title="添加完表单之后需要在 [设计] - [页面设计] 中调用「自定义表单」组件方可展示。"
        style="margin-bottom: 12px"
      />

      <el-table v-loading="loading" :data="list" stripe row-key="id">
        <el-table-column prop="id" label="ID" width="70" />
        <el-table-column v-if="isMerchantView" label="所属商户" width="100">
          <template #default="{ row }">
            <el-tag :type="row.bid > 0 ? 'warning' : 'info'" size="small">{{ row.bid > 0 ? row.bid : '平台' }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="name" label="名称" min-width="180" />
        <el-table-column label="有效时间" min-width="260">
          <template #default="{ row }">
            <span v-if="row.starttime || row.endtime">{{ formatTime(row.starttime) }} ~ {{ formatTime(row.endtime) }}</span>
            <span v-else class="text-muted">长期有效</span>
          </template>
        </el-table-column>
        <el-table-column label="创建时间" width="150">
          <template #default="{ row }">{{ formatTime(row.createtime) }}</template>
        </el-table-column>
        <el-table-column prop="sort" label="排序" width="70" />
        <el-table-column label="数据" width="90">
          <template #default="{ row }">
            <el-badge :value="row.st0count || 0" :max="99" :type="row.st0count > 0 ? 'danger' : 'info'">
              <el-button link type="primary" @click="openRecord(row)">查看</el-button>
            </el-badge>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="260" fixed="right">
          <template #default="{ row }">
            <el-button link type="primary" @click="openEdit(row)">编辑</el-button>
            <el-button link type="primary" @click="handleLink(row)">链接</el-button>
            <el-button link type="primary" @click="handleCopy(row)">复制</el-button>
            <el-button link type="danger" @click="handleDelete(row)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>

      <el-pagination
        v-if="total > 0"
        class="pager"
        v-model:current-page="query.page"
        v-model:page-size="query.pagesize"
        :total="total"
        layout="total, prev, pager, next"
        @current-change="fetchList"
      />
    </el-card>

    <!-- 新增 / 编辑弹窗 -->
    <el-dialog v-model="dialogVisible" :title="isEdit ? '编辑表单' : '新增表单'" width="640px">
      <el-form :model="form" label-width="100px" label-position="left">
        <el-form-item label="名称" required>
          <el-input v-model="form.name" placeholder="请输入表单名称" maxlength="50" />
        </el-form-item>
        <el-form-item v-if="!isMerchantView" label="商户ID">
          <el-input-number v-model="form.bid" :min="0" :precision="0" :step="1" />
          <span class="form-tip">0 为平台表单</span>
        </el-form-item>
        <el-form-item label="开始时间">
          <el-date-picker v-model="form.starttime" type="datetime" placeholder="不选为不限制" value-format="X" />
        </el-form-item>
        <el-form-item label="结束时间">
          <el-date-picker v-model="form.endtime" type="datetime" placeholder="不选为不限制" value-format="X" />
        </el-form-item>
        <el-form-item label="表单说明">
          <el-input v-model="form.content" type="textarea" :rows="4" placeholder="表单说明内容" />
        </el-form-item>
        <el-form-item label="付费金额">
          <el-input v-model="form.price" placeholder="0 或留空为免费" style="width: 200px" />
        </el-form-item>
        <el-form-item label="总提交上限">
          <el-input v-model="form.maxlimit" placeholder="0 或留空为不限制" style="width: 200px" />
        </el-form-item>
        <el-form-item label="每人上限">
          <el-input v-model="form.perlimit" placeholder="0 或留空为不限制" style="width: 200px" />
        </el-form-item>
        <el-form-item label="排序">
          <el-input-number v-model="form.sort" :min="0" :precision="0" :step="1" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="submitting" @click="handleSubmit">保存</el-button>
      </template>
    </el-dialog>

    <!-- 数据（提交记录）弹窗 -->
    <el-dialog v-model="recordVisible" :title="'数据 - ' + (recordTarget?.name || '')" width="900px">
      <div class="filter-bar">
        <el-select v-model="recordQuery.status" placeholder="状态" clearable style="width: 130px" @change="handleRecordSearch">
          <el-option value="0" label="未处理" />
          <el-option value="1" label="已确认" />
          <el-option value="2" label="已驳回" />
        </el-select>
        <el-input
          v-model="recordQuery.keyword"
          placeholder="关键词（匹配提交内容）"
          clearable
          style="width: 220px"
          @keyup.enter="handleRecordSearch"
          @clear="handleRecordSearch"
        />
        <el-button @click="handleRecordSearch">搜索</el-button>
      </div>

      <el-table v-loading="recordLoading" :data="recordList" stripe>
        <el-table-column prop="id" label="ID" width="70" />
        <el-table-column label="会员" min-width="160">
          <template #default="{ row }">
            <div class="member-cell">
              <el-avatar v-if="row.headimg" :src="row.headimg" :size="28" />
              <span>{{ row.nickname || '-' }}（ID: {{ row.mid }}）</span>
            </div>
          </template>
        </el-table-column>
        <el-table-column prop="title" label="标题" min-width="140" />
        <el-table-column label="提交时间" width="150">
          <template #default="{ row }">{{ formatTime(row.createtime) }}</template>
        </el-table-column>
        <el-table-column label="状态" width="90">
          <template #default="{ row }">
            <el-tag :type="row.status === 1 ? 'success' : row.status === 2 ? 'danger' : 'info'" size="small">
              {{ row.status === 1 ? '已确认' : row.status === 2 ? '已驳回' : '未处理' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="210" fixed="right">
          <template #default="{ row }">
            <el-button link type="primary" @click="openDetail(row)">详情</el-button>
            <el-button v-if="row.status === 0" link type="primary" @click="handleRecordSetst(row, 1)">确认</el-button>
            <el-button v-if="row.status === 0" link type="warning" @click="handleRecordSetst(row, 2)">驳回</el-button>
            <el-button link type="danger" @click="handleRecordDel(row)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>

      <el-pagination
        v-if="recordTotal > 0"
        class="pager"
        v-model:current-page="recordPage"
        :page-size="10"
        :total="recordTotal"
        layout="total, prev, pager, next"
        @current-change="fetchRecord"
      />
    </el-dialog>

    <!-- 记录详情弹窗 -->
    <el-dialog v-model="detailVisible" title="记录详情" width="560px">
      <template v-if="detailRow">
        <el-descriptions :column="1" border>
          <el-descriptions-item label="ID">{{ detailRow.id }}</el-descriptions-item>
          <el-descriptions-item label="会员">{{ detailRow.nickname || '-' }}（ID: {{ detailRow.mid }}）</el-descriptions-item>
          <el-descriptions-item label="提交时间">{{ formatTime(detailRow.createtime) }}</el-descriptions-item>
          <el-descriptions-item label="状态">
            {{ detailRow.status === 1 ? '已确认' : detailRow.status === 2 ? '已驳回' : '未处理' }}
          </el-descriptions-item>
          <el-descriptions-item v-if="detailRow.reason" label="驳回原因">{{ detailRow.reason }}</el-descriptions-item>
          <el-descriptions-item v-for="f in formFields(detailRow)" :key="f.key" :label="f.key">{{ f.value }}</el-descriptions-item>
        </el-descriptions>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped>
.card-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.pager {
  margin-top: 12px;
  justify-content: flex-end;
}

.form-tip {
  margin-left: 8px;
  color: #909399;
  font-size: 12px;
}

.filter-bar {
  display: flex;
  gap: 8px;
  margin-bottom: 12px;
}

.member-cell {
  display: flex;
  align-items: center;
  gap: 6px;
}

.text-muted {
  color: #909399;
}
</style>
