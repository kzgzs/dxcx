<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import type { FormInstance, FormRules } from 'element-plus'
import {
  deleteDiningTableCategory,
  getDiningTableCategoryList,
  saveDiningTableCategory,
} from '../../api/dining'
import type { DiningTableCategoryRow } from '../../api/dining'
import ImgIconPicker from '../../components/ImgIconPicker.vue'

// ============ 列表 ============
const loading = ref(false)
const tableData = ref<DiningTableCategoryRow[]>([])
const keyword = ref('')

async function fetchList() {
  loading.value = true
  try {
    const data = await getDiningTableCategoryList(keyword.value || undefined)
    tableData.value = data.list || []
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    loading.value = false
  }
}

function handleSearch() {
  fetchList()
}

function handleReset() {
  keyword.value = ''
  fetchList()
}

// ============ 删除 ============
function handleDelete(row: DiningTableCategoryRow) {
  ElMessageBox.confirm(`确定要删除分类「${row.name}」吗？`, '删除确认', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await deleteDiningTableCategory(row.id)
        ElMessage.success('删除成功')
        fetchList()
      } catch {
        // 有餐桌引用等错误由拦截器弹出
      }
    })
    .catch(() => {})
}

// ============ 新增 / 编辑弹窗 ============
const dialogVisible = ref(false)
const formRef = ref<FormInstance>()
const submitting = ref(false)

const form = reactive({
  id: 0,
  name: '',
  pic: '',
  service_fee: 0,
  limit_fee: 0,
  booking_fee: 0,
  seat: 4,
  sort: 0,
  status: 1,
})

const rules: FormRules = {
  name: [{ required: true, message: '请输入分类名称', trigger: 'blur' }],
}

function openAdd() {
  Object.assign(form, { id: 0, name: '', pic: '', service_fee: 0, limit_fee: 0, booking_fee: 0, seat: 4, sort: 0, status: 1 })
  formRef.value?.clearValidate()
  dialogVisible.value = true
}

function openEdit(row: DiningTableCategoryRow) {
  Object.assign(form, {
    id: row.id,
    name: row.name,
    pic: row.pic,
    service_fee: row.service_fee,
    limit_fee: row.limit_fee,
    booking_fee: row.booking_fee,
    seat: row.seat,
    sort: row.sort,
    status: row.status,
  })
  formRef.value?.clearValidate()
  dialogVisible.value = true
}

async function handleSubmit() {
  if (!formRef.value) return
  await formRef.value.validate(async (valid) => {
    if (!valid) return
    submitting.value = true
    try {
      await saveDiningTableCategory({
        id: form.id,
        name: form.name,
        pic: form.pic,
        service_fee: form.service_fee,
        limit_fee: form.limit_fee,
        booking_fee: form.booking_fee,
        seat: form.seat,
        sort: form.sort,
        status: form.status,
      })
      ElMessage.success('保存成功')
      dialogVisible.value = false
      fetchList()
    } catch {
      // 错误提示已由 axios 拦截器统一弹出
    } finally {
      submitting.value = false
    }
  })
}

// ============ 图片选择（任务294：ImgIconPicker，默认素材库） ============
const imgPickerVisible = ref(false)

function openImgPicker() {
  imgPickerVisible.value = true
}

function onImgPick(urls: string[]) {
  if (urls.length) form.pic = urls[0]
}

onMounted(fetchList)
</script>

<template>
  <el-card shadow="never">
    <template #header>
      <div class="card-header">
        <span class="card-title">餐桌分类</span>
        <el-button type="primary" @click="openAdd">新增分类</el-button>
      </div>
    </template>

    <!-- 搜索条 -->
    <div class="search-bar">
      <el-input v-model="keyword" placeholder="分类名称" clearable style="width: 200px" @keyup.enter="handleSearch" />
      <el-button type="primary" @click="handleSearch">查询</el-button>
      <el-button @click="handleReset">重置</el-button>
    </div>

    <el-table v-loading="loading" :data="tableData" stripe border size="small">
      <el-table-column prop="id" label="ID" width="56" class-name="compact-col" />
      <el-table-column prop="name" label="分类名称" min-width="130" show-overflow-tooltip />
      <el-table-column label="图片" width="70" class-name="compact-col">
        <template #default="{ row }">
          <el-image v-if="row.pic" :src="row.pic" :preview-src-list="[row.pic]" fit="cover" class="cell-pic" />
          <span v-else>-</span>
        </template>
      </el-table-column>
      <el-table-column label="服务费" width="82" class-name="compact-col">
        <template #default="{ row }">{{ Number(row.service_fee).toFixed(2) }}</template>
      </el-table-column>
      <el-table-column label="最低消费" width="88" class-name="compact-col">
        <template #default="{ row }">{{ Number(row.limit_fee).toFixed(2) }}</template>
      </el-table-column>
      <el-table-column label="预定费" width="82" class-name="compact-col">
        <template #default="{ row }">{{ Number(row.booking_fee).toFixed(2) }}</template>
      </el-table-column>
      <el-table-column prop="seat" label="默认座位" width="80" class-name="compact-col" />
      <el-table-column prop="table_count" label="桌台数" width="76" class-name="compact-col" />
      <el-table-column label="状态" width="72" class-name="compact-col">
        <template #default="{ row }">
          <span :class="row.status === 1 ? 'status-on' : 'status-off'">{{ row.status === 1 ? '显示' : '隐藏' }}</span>
        </template>
      </el-table-column>
      <el-table-column prop="sort" label="排序" width="60" class-name="compact-col" />
      <el-table-column label="操作" width="110" fixed="right" class-name="compact-col">
        <template #default="{ row }">
          <div class="op-wrap">
            <el-button link type="primary" @click="openEdit(row)">编辑</el-button>
            <el-button link type="danger" @click="handleDelete(row)">删除</el-button>
          </div>
        </template>
      </el-table-column>
    </el-table>
  </el-card>

  <!-- 新增 / 编辑弹窗 -->
  <el-dialog v-model="dialogVisible" :title="form.id > 0 ? '编辑分类' : '新增分类'" width="520px" destroy-on-close>
    <el-form ref="formRef" :model="form" :rules="rules" label-width="100px" class="compact-form">
      <el-form-item label="分类名称" prop="name">
        <el-input v-model="form.name" placeholder="如：大厅、包间" maxlength="50" />
      </el-form-item>
      <el-form-item label="分类图片">
        <div class="img-pick-row">
          <el-image v-if="form.pic" :src="form.pic" class="img-pick-thumb" fit="cover" />
          <el-button size="small" @click="openImgPicker">{{ form.pic ? '更换' : '选择图片' }}</el-button>
          <el-button v-if="form.pic" size="small" link type="danger" @click="form.pic = ''">删除</el-button>
        </div>
      </el-form-item>
      <el-form-item label="服务费">
        <el-input-number v-model="form.service_fee" :min="0" :precision="2" :step="1" />
        <span class="form-tip">元，0 为不收取</span>
      </el-form-item>
      <el-form-item label="最低消费">
        <el-input-number v-model="form.limit_fee" :min="0" :precision="2" :step="10" />
        <span class="form-tip">元，0 为不限制</span>
      </el-form-item>
      <el-form-item label="预定费">
        <el-input-number v-model="form.booking_fee" :min="0" :precision="2" :step="1" />
        <span class="form-tip">元，0 为不收取</span>
      </el-form-item>
      <el-form-item label="默认座位数">
        <el-input-number v-model="form.seat" :min="0" :max="999" />
      </el-form-item>
      <el-form-item label="状态">
        <el-radio-group v-model="form.status">
          <el-radio :value="1">显示</el-radio>
          <el-radio :value="0">隐藏</el-radio>
        </el-radio-group>
      </el-form-item>
      <el-form-item label="排序">
        <el-input-number v-model="form.sort" :min="0" />
      </el-form-item>
    </el-form>
    <template #footer>
      <el-button @click="dialogVisible = false">取消</el-button>
      <el-button type="primary" :loading="submitting" @click="handleSubmit">保存</el-button>
    </template>
  </el-dialog>

  <!-- 选图弹窗（任务294） -->
  <ImgIconPicker
    v-model:visible="imgPickerVisible"
    default-tab="library"
    @pick="(urls: string[]) => onImgPick(urls)"
  />
</template>

<style scoped>
.card-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.card-title {
  font-weight: 600;
}

.search-bar {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 12px;
}

/* 操作列：link 文字按钮单行排布（紧凑间距） */
.op-wrap {
  display: flex;
  align-items: center;
  flex-wrap: nowrap;
  gap: 2px;
  white-space: nowrap;
}

/* 紧凑列：收紧单元格左右内边距 */
:deep(.compact-col .cell) {
  padding-left: 6px;
  padding-right: 6px;
}

.cell-pic {
  width: 36px;
  height: 36px;
  border-radius: 4px;
}

.status-on {
  color: #67c23a;
}

.status-off {
  color: #909399;
}

.compact-form :deep(.el-form-item) {
  margin-bottom: 10px;
}

.form-tip {
  margin-left: 8px;
  color: #909399;
  font-size: 12px;
}

.img-pick-row {
  display: flex;
  align-items: center;
  gap: 10px;
}

.img-pick-thumb {
  width: 72px;
  height: 72px;
  border-radius: 6px;
  border: 1px solid #ebeef5;
}
</style>
