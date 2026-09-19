<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import type { FormInstance, FormRules } from 'element-plus'
import {
  addPromotion,
  deletePromotion,
  editPromotion,
  getPromotionList,
  setPromotionStatus,
} from '../../api/promotion'
import type { PromotionItem } from '../../api/types'
import { formatDate, formatTime } from '../../utils/time'

// ============ 列表 ============
const loading = ref(false)
const tableData = ref<PromotionItem[]>([])
const total = ref(0)

const query = reactive({
  page: 1,
  pagesize: 10,
  keyword: '',
  status: '' as number | '',
})

async function fetchList() {
  loading.value = true
  try {
    const data = await getPromotionList({
      page: query.page,
      pagesize: query.pagesize,
      keyword: query.keyword || undefined,
      status: query.status === '' ? undefined : query.status,
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

function handleReset() {
  query.keyword = ''
  query.status = ''
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

// 优惠内容文案：满额减「满X元减Y元」/ 满件折「满X件打N折」
function benefitText(row: PromotionItem) {
  if (row.type === 1) {
    return `满${Number(row.full_money)}元减${Number(row.cut_money)}元`
  }
  return `满${Number(row.full_num)}件打${Number(row.discount)}折`
}

// 有效期区间
function periodText(row: PromotionItem) {
  return `${formatDate(row.start_time)} ~ ${formatDate(row.end_time)}`
}

// ============ 状态开关 ============
async function handleStatusChange(row: PromotionItem) {
  try {
    await setPromotionStatus(row.id, row.status)
    ElMessage.success(row.status === 1 ? '已启用' : '已停用')
  } catch {
    // 接口失败回滚开关状态；错误提示已由 axios 拦截器统一弹出
    row.status = row.status === 1 ? 0 : 1
  }
}

// ============ 删除 ============
function handleDelete(row: PromotionItem) {
  ElMessageBox.confirm(`确定要删除促销活动「${row.name}」吗？删除后不可恢复。`, '删除确认', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await deletePromotion(row.id)
        ElMessage.success('删除成功')
        fetchList()
      } catch {
        // 错误提示已由 axios 拦截器统一弹出
      }
    })
    .catch(() => {})
}

// ============ 新增 / 编辑弹窗 ============
const dialogVisible = ref(false)
const isEdit = ref(false)
const formRef = ref<FormInstance>()
const submitting = ref(false)

const form = reactive({
  id: 0,
  name: '',
  type: 1, // 1满额减 2满件折
  full_money: 0,
  full_num: 1,
  cut_money: 0,
  discount: 9,
  timeRange: null as [Date, Date] | null,
})

const rules: FormRules = {
  name: [{ required: true, message: '请输入活动名称', trigger: 'blur' }],
  full_money: [{ required: true, message: '请输入金额门槛', trigger: 'blur' }],
  cut_money: [{ required: true, message: '请输入减免金额', trigger: 'blur' }],
  full_num: [{ required: true, message: '请输入件数门槛', trigger: 'blur' }],
  discount: [{ required: true, message: '请输入折扣值', trigger: 'blur' }],
}

function openAdd() {
  isEdit.value = false
  Object.assign(form, {
    id: 0,
    name: '',
    type: 1,
    full_money: 0,
    full_num: 1,
    cut_money: 0,
    discount: 9,
    timeRange: null,
  })
  dialogVisible.value = true
}

function openEdit(row: PromotionItem) {
  isEdit.value = true
  Object.assign(form, {
    id: row.id,
    name: row.name,
    type: row.type,
    full_money: row.full_money,
    full_num: row.full_num,
    cut_money: row.cut_money,
    discount: row.discount,
    timeRange:
      row.start_time && row.end_time
        ? ([new Date(row.start_time * 1000), new Date(row.end_time * 1000)] as [Date, Date])
        : null,
  })
  dialogVisible.value = true
}

async function handleSubmit() {
  if (!formRef.value) return
  await formRef.value.validate(async (valid) => {
    if (!valid) return
    if (!form.timeRange || form.timeRange.length !== 2) {
      ElMessage.warning('请选择有效期')
      return
    }
    submitting.value = true
    try {
      const params = {
        id: form.id,
        name: form.name,
        type: form.type,
        full_money: form.type === 1 ? form.full_money : 0,
        full_num: form.type === 2 ? form.full_num : 0,
        cut_money: form.type === 1 ? form.cut_money : 0,
        discount: form.type === 2 ? form.discount : 0,
        start_time: Math.floor(form.timeRange[0].getTime() / 1000),
        end_time: Math.floor(form.timeRange[1].getTime() / 1000),
      }
      if (isEdit.value) {
        await editPromotion(params)
        ElMessage.success('保存成功')
      } else {
        await addPromotion(params)
        ElMessage.success('新增成功')
      }
      dialogVisible.value = false
      fetchList()
    } catch {
      // 错误提示已由 axios 拦截器统一弹出
    } finally {
      submitting.value = false
    }
  })
}

onMounted(fetchList)
</script>

<template>
  <div>
    <!-- 搜索工具栏 -->
    <el-card shadow="never" class="toolbar-card">
      <el-form inline @submit.prevent>
        <el-form-item label="关键词">
          <el-input
            v-model="query.keyword"
            placeholder="活动名称"
            clearable
            style="width: 200px"
            @keyup.enter="handleSearch"
          />
        </el-form-item>
        <el-form-item label="状态">
          <el-select v-model="query.status" placeholder="全部" clearable style="width: 120px">
            <el-option label="启用" :value="1" />
            <el-option label="停用" :value="0" />
          </el-select>
        </el-form-item>
        <el-form-item>
          <el-button type="primary" @click="handleSearch">搜索</el-button>
          <el-button @click="handleReset">重置</el-button>
        </el-form-item>
        <el-form-item style="float: right">
          <el-button type="success" @click="openAdd">新增促销</el-button>
        </el-form-item>
      </el-form>
    </el-card>

    <!-- 促销列表 -->
    <el-card shadow="never">
      <el-table v-loading="loading" :data="tableData" stripe>
        <el-table-column prop="id" label="ID" width="70" />
        <el-table-column prop="name" label="名称" min-width="140" show-overflow-tooltip />
        <el-table-column label="类型" width="100">
          <template #default="{ row }">
            <el-tag :type="row.type === 1 ? 'primary' : 'success'" size="small">
              {{ row.type === 1 ? '满额减' : '满件折' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="优惠内容" min-width="150">
          <template #default="{ row }">{{ benefitText(row) }}</template>
        </el-table-column>
        <el-table-column label="有效期" width="200">
          <template #default="{ row }">{{ periodText(row) }}</template>
        </el-table-column>
        <el-table-column label="状态" width="90">
          <template #default="{ row }">
            <el-switch
              v-model="row.status"
              :active-value="1"
              :inactive-value="0"
              @change="handleStatusChange(row)"
            />
          </template>
        </el-table-column>
        <el-table-column label="创建时间" width="160">
          <template #default="{ row }">{{ formatTime(row.create_time) }}</template>
        </el-table-column>
        <el-table-column label="操作" width="130" fixed="right">
          <template #default="{ row }">
            <el-button link type="primary" @click="openEdit(row)">编辑</el-button>
            <el-button link type="danger" @click="handleDelete(row)">删除</el-button>
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

    <!-- 新增 / 编辑弹窗 -->
    <el-dialog
      v-model="dialogVisible"
      :title="isEdit ? '编辑促销' : '新增促销'"
      width="480px"
    >
      <el-form
        ref="formRef"
        :model="form"
        :rules="rules"
        label-width="90px"
        label-position="left"
      >
        <el-form-item label="活动名称" prop="name">
          <el-input v-model="form.name" placeholder="请输入活动名称" />
        </el-form-item>
        <el-form-item label="促销类型">
          <el-radio-group v-model="form.type">
            <el-radio :value="1">满额减</el-radio>
            <el-radio :value="2">满件折</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item v-if="form.type === 1" label="金额门槛" prop="full_money">
          <el-input-number v-model="form.full_money" :min="0" :precision="2" :step="1" />
          <span class="form-tip">订单满多少元</span>
        </el-form-item>
        <el-form-item v-if="form.type === 1" label="减免金额" prop="cut_money">
          <el-input-number v-model="form.cut_money" :min="0" :precision="2" :step="1" />
        </el-form-item>
        <el-form-item v-if="form.type === 2" label="件数门槛" prop="full_num">
          <el-input-number v-model="form.full_num" :min="1" :precision="0" :step="1" />
          <span class="form-tip">订单满多少件</span>
        </el-form-item>
        <el-form-item v-if="form.type === 2" label="折扣值" prop="discount">
          <el-input-number v-model="form.discount" :min="1" :max="9.9" :precision="1" :step="0.1" />
          <span class="form-tip">如 8.5 表示 8.5 折</span>
        </el-form-item>
        <el-form-item label="有效期">
          <el-date-picker
            v-model="form.timeRange"
            type="datetimerange"
            range-separator="至"
            start-placeholder="开始时间"
            end-placeholder="结束时间"
            style="width: 100%"
          />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="submitting" @click="handleSubmit">
          确定
        </el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped>
.toolbar-card {
  margin-bottom: 16px;
}

.toolbar-card :deep(.el-card__body) {
  padding-bottom: 2px;
}

.pagination {
  margin-top: 16px;
  justify-content: flex-end;
}

.form-tip {
  margin-left: 8px;
  color: #909399;
  font-size: 12px;
}
</style>
