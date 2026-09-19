<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import type { FormInstance, FormRules } from 'element-plus'
import {
  addCarProduct,
  deleteCarProduct,
  editCarProduct,
  getCarCategoryList,
  getCarProductList,
  setCarProductStatus,
} from '../../api/carhailing'
import type { CarCategoryItem, CarProductItem } from '../../api/types'
import { formatTime } from '../../utils/time'
import ImgIconPicker from '../../components/ImgIconPicker.vue'

// ============ 分类选项 ============
const categoryOptions = ref<CarCategoryItem[]>([])

async function fetchCategories() {
  try {
    const data = await getCarCategoryList({ page: 1, pagesize: 100 })
    categoryOptions.value = data.list || []
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  }
}

// ============ 列表 ============
const loading = ref(false)
const tableData = ref<CarProductItem[]>([])
const total = ref(0)

const query = reactive({
  page: 1,
  pagesize: 10,
  keyword: '',
  category_id: '' as number | '',
  status: '' as number | '',
})

async function fetchList() {
  loading.value = true
  try {
    const data = await getCarProductList({
      page: query.page,
      pagesize: query.pagesize,
      keyword: query.keyword || undefined,
      category_id: query.category_id === '' ? undefined : query.category_id,
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
  query.category_id = ''
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

// ============ 状态开关 ============
async function handleStatusChange(row: CarProductItem) {
  try {
    await setCarProductStatus(row.id, row.status)
    ElMessage.success(row.status === 1 ? '已上架' : '已下架')
  } catch {
    // 接口失败回滚开关状态；错误提示已由 axios 拦截器统一弹出
    row.status = row.status === 1 ? 0 : 1
  }
}

// ============ 删除 ============
function handleDelete(row: CarProductItem) {
  ElMessageBox.confirm(`确定要删除车辆「${row.name}」吗？删除后不可恢复。`, '删除确认', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await deleteCarProduct(row.id)
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
  category_id: '' as number | '',
  pic: '',
  price_day: 0,
  price_half: 0,
  car_num: 1,
  desc: '',
  status: 1,
})

const rules: FormRules = {
  name: [{ required: true, message: '请输入车辆名称', trigger: 'blur' }],
  category_id: [{ required: true, message: '请选择所属分类', trigger: 'change' }],
  price_day: [{ required: true, message: '请输入日租价', trigger: 'blur' }],
  price_half: [{ required: true, message: '请输入半日价', trigger: 'blur' }],
  car_num: [{ required: true, message: '请输入车辆数量', trigger: 'blur' }],
}

function openAdd() {
  isEdit.value = false
  Object.assign(form, {
    id: 0,
    name: '',
    category_id: '',
    pic: '',
    price_day: 0,
    price_half: 0,
    car_num: 1,
    desc: '',
    status: 1,
  })
  dialogVisible.value = true
}

function openEdit(row: CarProductItem) {
  isEdit.value = true
  Object.assign(form, {
    id: row.id,
    name: row.name,
    category_id: row.category_id,
    pic: row.pic,
    price_day: row.price_day,
    price_half: row.price_half,
    car_num: row.car_num,
    desc: row.desc || '',
    status: row.status,
  })
  dialogVisible.value = true
}

async function handleSubmit() {
  if (!formRef.value) return
  await formRef.value.validate(async (valid) => {
    if (!valid) return
    submitting.value = true
    try {
      const params = {
        id: form.id,
        name: form.name,
        category_id: Number(form.category_id),
        pic: form.pic,
        price_day: form.price_day,
        price_half: form.price_half,
        car_num: form.car_num,
        desc: form.desc,
        status: form.status,
      }
      if (isEdit.value) {
        await editCarProduct(params)
        ElMessage.success('保存成功')
      } else {
        await addCarProduct(params)
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

// ============ 选图（ImgIconPicker 统一选图） ============
const imgPickerVisible = ref(false)
let imgPickerSetter: ((url: string) => void) | null = null
function openImgPicker(setter: (url: string) => void) {
  imgPickerSetter = setter
  imgPickerVisible.value = true
}
function onImgPick(urls: string[]) {
  if (urls[0]) imgPickerSetter?.(urls[0])
  imgPickerSetter = null
}

onMounted(() => {
  fetchCategories()
  fetchList()
})
</script>

<template>
  <div>
    <!-- 搜索工具栏 -->
    <el-card shadow="never" class="toolbar-card">
      <el-form inline @submit.prevent>
        <el-form-item label="关键词">
          <el-input
            v-model="query.keyword"
            placeholder="车辆名称"
            clearable
            style="width: 180px"
            @keyup.enter="handleSearch"
          />
        </el-form-item>
        <el-form-item label="分类">
          <el-select v-model="query.category_id" placeholder="全部" clearable style="width: 140px">
            <el-option
              v-for="c in categoryOptions"
              :key="c.id"
              :label="c.name"
              :value="c.id"
            />
          </el-select>
        </el-form-item>
        <el-form-item label="状态">
          <el-select v-model="query.status" placeholder="全部" clearable style="width: 120px">
            <el-option label="上架" :value="1" />
            <el-option label="下架" :value="0" />
          </el-select>
        </el-form-item>
        <el-form-item>
          <el-button type="primary" @click="handleSearch">搜索</el-button>
          <el-button @click="handleReset">重置</el-button>
        </el-form-item>
        <el-form-item style="float: right">
          <el-button type="success" @click="openAdd">新增车辆</el-button>
        </el-form-item>
      </el-form>
    </el-card>

    <!-- 车辆列表 -->
    <el-card shadow="never">
      <el-table v-loading="loading" :data="tableData" stripe>
        <el-table-column prop="id" label="ID" width="70" />
        <el-table-column label="主图" width="90">
          <template #default="{ row }">
            <el-image
              v-if="row.pic"
              :src="row.pic"
              fit="cover"
              style="width: 60px; height: 40px; border-radius: 4px"
              :preview-src-list="[row.pic]"
              preview-teleported
            />
            <span v-else>-</span>
          </template>
        </el-table-column>
        <el-table-column prop="name" label="名称" min-width="140" show-overflow-tooltip />
        <el-table-column prop="category_name" label="分类" width="110" />
        <el-table-column label="日租价" width="100">
          <template #default="{ row }">¥{{ Number(row.price_day).toFixed(2) }}</template>
        </el-table-column>
        <el-table-column label="半日价" width="100">
          <template #default="{ row }">¥{{ Number(row.price_half).toFixed(2) }}</template>
        </el-table-column>
        <el-table-column prop="car_num" label="车辆数" width="80" />
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
      :title="isEdit ? '编辑车辆' : '新增车辆'"
      width="520px"
    >
      <el-form
        ref="formRef"
        :model="form"
        :rules="rules"
        label-width="90px"
        label-position="left"
      >
        <el-form-item label="车辆名称" prop="name">
          <el-input v-model="form.name" placeholder="请输入车辆名称" />
        </el-form-item>
        <el-form-item label="所属分类" prop="category_id">
          <el-select v-model="form.category_id" placeholder="请选择分类" style="width: 100%">
            <el-option
              v-for="c in categoryOptions"
              :key="c.id"
              :label="c.name"
              :value="c.id"
            />
          </el-select>
        </el-form-item>
        <el-form-item label="主图">
          <el-image
            v-if="form.pic"
            :src="form.pic"
            fit="cover"
            style="width: 80px; height: 80px; border-radius: 4px; margin-right: 8px"
          />
          <el-button size="small" @click="openImgPicker((u) => (form.pic = u))">
            {{ form.pic ? '更换' : '选择图片' }}
          </el-button>
        </el-form-item>
        <el-form-item label="日租价" prop="price_day">
          <el-input-number v-model="form.price_day" :min="0" :precision="2" :step="10" />
          <span class="form-tip">元 / 天</span>
        </el-form-item>
        <el-form-item label="半日价" prop="price_half">
          <el-input-number v-model="form.price_half" :min="0" :precision="2" :step="10" />
          <span class="form-tip">元 / 半天</span>
        </el-form-item>
        <el-form-item label="车辆数量" prop="car_num">
          <el-input-number v-model="form.car_num" :min="0" :precision="0" :step="1" />
        </el-form-item>
        <el-form-item label="描述">
          <el-input
            v-model="form.desc"
            type="textarea"
            :rows="3"
            placeholder="请输入车辆描述"
          />
        </el-form-item>
        <el-form-item label="状态">
          <el-switch v-model="form.status" :active-value="1" :inactive-value="0" />
          <span class="form-tip">上架后可被预订</span>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="submitting" @click="handleSubmit">
          确定
        </el-button>
      </template>
    </el-dialog>

    <ImgIconPicker v-model:visible="imgPickerVisible" default-tab="library" @pick="onImgPick" />
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
