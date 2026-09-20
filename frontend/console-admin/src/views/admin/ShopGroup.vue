<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import type { FormInstance, FormRules } from 'element-plus'
import { Search } from '@element-plus/icons-vue'
import {
  deleteShopGroup,
  getShopGroupList,
  saveShopGroup,
} from '../../api/shopconfig'
import type { ShopGroupRow } from '../../api/shopconfig'
import ImgIconPicker from '../../components/ImgIconPicker.vue'

// ============ 列表 ============
const loading = ref(false)
const tableData = ref<ShopGroupRow[]>([])
const total = ref(0)
const selectedIds = ref<number[]>([])

const query = reactive({
  page: 1,
  pagesize: 10,
  name: '',
  status: '' as number | '',
})

async function fetchList() {
  loading.value = true
  try {
    const data = await getShopGroupList({
      page: query.page,
      pagesize: query.pagesize,
      name: query.name || undefined,
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

function handleSelectionChange(rows: ShopGroupRow[]) {
  selectedIds.value = rows.map((r) => r.id)
}

function handleSearch() {
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

// ============ 新增 / 编辑弹窗 ============
const dialogVisible = ref(false)
const isEdit = ref(false)
const formRef = ref<FormInstance>()
const submitting = ref(false)

const form = reactive({
  id: 0,
  name: '',
  pic: '',
  sort: 0,
  status: 1,
})

const rules: FormRules = {
  name: [{ required: true, message: '请输入分组名称', trigger: 'blur' }],
}

function openAdd() {
  isEdit.value = false
  Object.assign(form, { id: 0, name: '', pic: '', sort: 0, status: 1 })
  formRef.value?.clearValidate()
  dialogVisible.value = true
}

function openEdit(row: ShopGroupRow) {
  isEdit.value = true
  Object.assign(form, { id: row.id, name: row.name, pic: row.pic, sort: row.sort, status: row.status })
  formRef.value?.clearValidate()
  dialogVisible.value = true
}

async function handleSubmit() {
  if (!formRef.value) return
  await formRef.value.validate(async (valid) => {
    if (!valid) return
    submitting.value = true
    try {
      await saveShopGroup({
        id: form.id,
        name: form.name,
        pic: form.pic,
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

// ============ 删除（单个 / 批量） ============
function doDelete(ids: number[]) {
  if (ids.length === 0) {
    ElMessage.warning('请选择数据')
    return
  }
  ElMessageBox.confirm('确定要删除吗？删除后无法恢复！', '操作确认', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await deleteShopGroup(ids)
        ElMessage.success('删除成功')
        fetchList()
      } catch {
        // 错误提示已由 axios 拦截器统一弹出
      }
    })
    .catch(() => {})
}

function handleBatchDelete() {
  doDelete(selectedIds.value)
}

function handleDelete(row: ShopGroupRow) {
  doDelete([row.id])
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
  <div>
    <!-- 工具栏：左侧操作按钮，右侧搜索（对齐点大商城商品分组） -->
    <el-card shadow="never" class="toolbar-card">
      <div class="toolbar">
        <div class="toolbar-left">
          <el-button type="success" @click="openAdd">添加</el-button>
          <el-button @click="handleBatchDelete">删除</el-button>
        </div>
        <el-form inline class="toolbar-right" @submit.prevent>
          <el-form-item label="分组名称" class="search-item">
            <el-input
              v-model="query.name"
              placeholder="分组名称"
              clearable
              style="width: 180px"
              @keyup.enter="handleSearch"
            />
          </el-form-item>
          <el-form-item label="状态" class="search-item">
            <el-select v-model="query.status" placeholder="全部" style="width: 110px">
              <el-option label="全部" :value="''" />
              <el-option label="显示" :value="1" />
              <el-option label="隐藏" :value="0" />
            </el-select>
          </el-form-item>
          <el-form-item>
            <el-button type="success" :icon="Search" @click="handleSearch" />
          </el-form-item>
        </el-form>
      </div>
    </el-card>

    <!-- 列表 -->
    <el-card shadow="never">
      <el-table
        v-loading="loading"
        :data="tableData"
        border
        @selection-change="handleSelectionChange"
      >
        <el-table-column type="selection" width="40" />
        <el-table-column prop="id" label="ID" width="80" />
        <el-table-column prop="name" label="名称" min-width="160" />
        <el-table-column label="图片" width="100">
          <template #default="{ row }">
            <el-image
              v-if="row.pic"
              :src="row.pic"
              style="width: 50px; height: 50px"
              fit="cover"
              :preview-src-list="[row.pic]"
            />
            <span v-else class="muted">-</span>
          </template>
        </el-table-column>
        <el-table-column prop="sort" label="序号" width="80" />
        <el-table-column label="状态" width="80">
          <template #default="{ row }">
            <span :style="{ color: row.status === 1 ? '#009a61' : '#f56c6c' }">
              {{ row.status === 1 ? '显示' : '隐藏' }}
            </span>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="150" fixed="right">
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
        :page-sizes="[10, 15, 30, 50]"
        @current-change="handlePageChange"
        @size-change="handleSizeChange"
      />
    </el-card>

    <!-- 新增 / 编辑弹窗 -->
    <el-dialog v-model="dialogVisible" :title="isEdit ? '编辑商品分组' : '添加商品分组'" width="480px">
      <el-form ref="formRef" :model="form" :rules="rules" label-width="90px" label-position="left">
        <el-form-item label="分组名称" prop="name">
          <el-input v-model="form.name" placeholder="请输入分组名称" />
        </el-form-item>
        <el-form-item label="分组图标">
          <div class="img-pick-row">
            <el-image v-if="form.pic" :src="form.pic" class="img-pick-thumb" fit="cover" />
            <el-button size="small" @click="openImgPicker">{{ form.pic ? '更换图片' : '上传图片' }}</el-button>
            <el-button v-if="form.pic" size="small" @click="form.pic = ''">移除</el-button>
          </div>
          <div class="form-tip">建议尺寸：400×400像素</div>
        </el-form-item>
        <el-form-item label="序号">
          <el-input-number v-model="form.sort" :min="0" :step="1" />
          <span class="form-tip" style="margin-left: 10px">用于排序，越大越靠前</span>
        </el-form-item>
        <el-form-item label="状态">
          <el-radio-group v-model="form.status">
            <el-radio :value="1">显示</el-radio>
            <el-radio :value="0">隐藏</el-radio>
          </el-radio-group>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button type="primary" :loading="submitting" @click="handleSubmit">提交</el-button>
      </template>
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

.toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 8px;
}

.toolbar-left {
  display: flex;
  align-items: center;
  gap: 8px;
}

.toolbar-right {
  display: flex;
  align-items: center;
  margin-left: auto;
}

.toolbar-right :deep(.el-form-item) {
  margin-bottom: 0;
}

.pagination {
  margin-top: 16px;
  justify-content: flex-start;
}

.muted {
  color: #c0c4cc;
}

.form-tip {
  color: #909399;
  font-size: 12px;
  line-height: 1.4;
}

.img-pick-row {
  display: flex;
  align-items: center;
  gap: 10px;
}

.img-pick-thumb {
  width: 100px;
  height: 100px;
  border-radius: 4px;
  border: 1px solid #ebeef5;
}
</style>
