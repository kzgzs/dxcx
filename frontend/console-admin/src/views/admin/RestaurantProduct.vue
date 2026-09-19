<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import type { FormInstance, FormRules } from 'element-plus'
import {
  addRestaurantCategory,
  addRestaurantProduct,
  deleteRestaurantCategory,
  deleteRestaurantProduct,
  editRestaurantCategory,
  editRestaurantProduct,
  getRestaurantCategoryList,
  getRestaurantProductList,
  setRestaurantProductStatus,
} from '../../api/restaurant'
import type {
  RestaurantCategoryItem,
  RestaurantProductItem,
} from '../../api/types'
import ImgIconPicker from '../../components/ImgIconPicker.vue'
import { formatTime } from '../../utils/time'

// ============ 分类选项 ============
const categoryOptions = ref<RestaurantCategoryItem[]>([])

async function fetchCategories() {
  try {
    const data = await getRestaurantCategoryList()
    categoryOptions.value = data.list || []
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  }
}

// ============ 列表 ============
const loading = ref(false)
const tableData = ref<RestaurantProductItem[]>([])
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
    const data = await getRestaurantProductList({
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
async function handleStatusChange(row: RestaurantProductItem) {
  try {
    await setRestaurantProductStatus(row.id, row.status)
    ElMessage.success(row.status === 1 ? '已上架' : '已下架')
  } catch {
    // 接口失败回滚开关状态；错误提示已由 axios 拦截器统一弹出
    row.status = row.status === 1 ? 0 : 1
  }
}

// ============ 删除菜品 ============
function handleDelete(row: RestaurantProductItem) {
  ElMessageBox.confirm(`确定要删除菜品「${row.name}」吗？删除后不可恢复。`, '删除确认', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await deleteRestaurantProduct(row.id)
        ElMessage.success('删除成功')
        fetchList()
      } catch {
        // 错误提示已由 axios 拦截器统一弹出
      }
    })
    .catch(() => {})
}

// ============ 新增 / 编辑菜品弹窗 ============
const dialogVisible = ref(false)
const isEdit = ref(false)
const formRef = ref<FormInstance>()
const submitting = ref(false)

const form = reactive({
  id: 0,
  name: '',
  category_id: '' as number | '',
  pic: '',
  price: 0,
  desc: '',
  status: 1,
})

const rules: FormRules = {
  name: [{ required: true, message: '请输入菜品名称', trigger: 'blur' }],
  category_id: [{ required: true, message: '请选择所属分类', trigger: 'change' }],
  price: [{ required: true, message: '请输入价格', trigger: 'blur' }],
}

function openAdd() {
  isEdit.value = false
  Object.assign(form, { id: 0, name: '', category_id: '', pic: '', price: 0, desc: '', status: 1 })
  dialogVisible.value = true
}

function openEdit(row: RestaurantProductItem) {
  isEdit.value = true
  Object.assign(form, {
    id: row.id,
    name: row.name,
    category_id: row.category_id,
    pic: row.pic,
    price: row.price,
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
        price: form.price,
        desc: form.desc,
        status: form.status,
      }
      if (isEdit.value) {
        await editRestaurantProduct(params)
        ElMessage.success('保存成功')
      } else {
        await addRestaurantProduct(params)
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

// ============ 分类管理弹窗（内联） ============
const categoryDialogVisible = ref(false)
const categoryFormRef = ref<FormInstance>()
const categorySubmitting = ref(false)
const categoryForm = reactive({
  id: 0,
  name: '',
  sort: 0,
})
const categoryRules: FormRules = {
  name: [{ required: true, message: '请输入分类名称', trigger: 'blur' }],
}

function openCategoryDialog() {
  fetchCategories()
  Object.assign(categoryForm, { id: 0, name: '', sort: 0 })
  categoryDialogVisible.value = true
}

function openCategoryEdit(row: RestaurantCategoryItem) {
  Object.assign(categoryForm, { id: row.id, name: row.name, sort: row.sort })
  categoryFormRef.value?.clearValidate()
}

function cancelCategoryEdit() {
  Object.assign(categoryForm, { id: 0, name: '', sort: 0 })
  categoryFormRef.value?.clearValidate()
}

async function handleCategorySave() {
  if (!categoryFormRef.value) return
  await categoryFormRef.value.validate(async (valid) => {
    if (!valid) return
    categorySubmitting.value = true
    try {
      const params = {
        id: categoryForm.id,
        name: categoryForm.name,
        sort: categoryForm.sort,
      }
      if (categoryForm.id) {
        await editRestaurantCategory(params)
        ElMessage.success('保存成功')
      } else {
        await addRestaurantCategory(params)
        ElMessage.success('新增成功')
      }
      Object.assign(categoryForm, { id: 0, name: '', sort: 0 })
      fetchCategories()
    } catch {
      // 错误提示已由 axios 拦截器统一弹出
    } finally {
      categorySubmitting.value = false
    }
  })
}

function handleCategoryDelete(row: RestaurantCategoryItem) {
  ElMessageBox.confirm(`确定要删除分类「${row.name}」吗？`, '删除确认', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await deleteRestaurantCategory(row.id)
        ElMessage.success('删除成功')
        fetchCategories()
      } catch {
        // 错误提示已由 axios 拦截器统一弹出
      }
    })
    .catch(() => {})
}

// ============ 图片选择（任务294：ImgIconPicker，默认素材库） ============
const imgPickerVisible = ref(false)

function openImgPicker() {
  imgPickerVisible.value = true
}

function onImgPick(urls: string[]) {
  if (urls.length) form.pic = urls[0]
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
            placeholder="菜品名称"
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
          <el-button @click="openCategoryDialog">分类管理</el-button>
        </el-form-item>
        <el-form-item style="float: right">
          <el-button type="success" @click="openAdd">新增菜品</el-button>
        </el-form-item>
      </el-form>
    </el-card>

    <!-- 菜品列表 -->
    <el-card shadow="never">
      <el-table v-loading="loading" :data="tableData" stripe>
        <el-table-column prop="id" label="ID" width="70" />
        <el-table-column label="图片" width="90">
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
        <el-table-column prop="name" label="菜品名称" min-width="140" show-overflow-tooltip />
        <el-table-column prop="category_name" label="分类" width="110" />
        <el-table-column label="价格" width="100">
          <template #default="{ row }">¥{{ Number(row.price).toFixed(2) }}</template>
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

    <!-- 新增 / 编辑菜品弹窗 -->
    <el-dialog
      v-model="dialogVisible"
      :title="isEdit ? '编辑菜品' : '新增菜品'"
      width="520px"
    >
      <el-form
        ref="formRef"
        :model="form"
        :rules="rules"
        label-width="90px"
        label-position="left"
      >
        <el-form-item label="菜品名称" prop="name">
          <el-input v-model="form.name" placeholder="请输入菜品名称" />
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
        <el-form-item label="图片">
          <div class="img-pick-row">
            <el-image v-if="form.pic" :src="form.pic" class="img-pick-thumb" fit="cover" />
            <el-button size="small" @click="openImgPicker">选择图片</el-button>
            <el-button v-if="form.pic" size="small" link type="danger" @click="form.pic = ''">删除</el-button>
          </div>
        </el-form-item>
        <el-form-item label="价格" prop="price">
          <el-input-number v-model="form.price" :min="0" :precision="2" :step="1" />
        </el-form-item>
        <el-form-item label="描述">
          <el-input
            v-model="form.desc"
            type="textarea"
            :rows="3"
            placeholder="请输入菜品描述"
          />
        </el-form-item>
        <el-form-item label="状态">
          <el-switch v-model="form.status" :active-value="1" :inactive-value="0" />
          <span class="form-tip">上架后可被点单</span>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="submitting" @click="handleSubmit">
          确定
        </el-button>
      </template>
    </el-dialog>

    <!-- 分类管理弹窗 -->
    <el-dialog v-model="categoryDialogVisible" title="分类管理" width="520px">
      <el-table :data="categoryOptions" stripe max-height="320">
        <el-table-column prop="id" label="ID" width="70" />
        <el-table-column prop="name" label="分类名称" min-width="120" />
        <el-table-column prop="sort" label="排序" width="80" />
        <el-table-column prop="product_count" label="菜品数" width="80" />
        <el-table-column label="操作" width="130">
          <template #default="{ row }">
            <el-button link type="primary" @click="openCategoryEdit(row)">编辑</el-button>
            <el-button link type="danger" @click="handleCategoryDelete(row)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>

      <el-form
        ref="categoryFormRef"
        :model="categoryForm"
        :rules="categoryRules"
        inline
        class="category-form"
      >
        <el-form-item prop="name">
          <el-input v-model="categoryForm.name" placeholder="分类名称" style="width: 160px" />
        </el-form-item>
        <el-form-item>
          <el-input-number v-model="categoryForm.sort" :min="0" :precision="0" :step="1" placeholder="排序" />
        </el-form-item>
        <el-form-item>
          <el-button type="primary" :loading="categorySubmitting" @click="handleCategorySave">
            {{ categoryForm.id ? '保存' : '新增' }}
          </el-button>
          <el-button v-if="categoryForm.id" @click="cancelCategoryEdit">取消编辑</el-button>
        </el-form-item>
      </el-form>
    </el-dialog>

    <!-- 选图弹窗（任务294） -->
    <ImgIconPicker
      v-model:visible="imgPickerVisible"
      default-tab="library"
      @pick="(urls: string[]) => onImgPick(urls)"
    />
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

.category-form {
  margin-top: 16px;
  padding-top: 12px;
  border-top: 1px solid #ebeef5;
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
