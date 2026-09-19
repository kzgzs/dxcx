<script setup lang="ts">
/**
 * 商户 - 商户分类（business_category）
 * 对标参考系统 business/category.html + categoryedit.html：
 * - 列表：添加/删除；列 复选/ID/名称/图片/序号/状态(显示绿·隐藏红)/操作(编辑·删除)；默认序号倒序，表头可排序；无搜索区
 * - 弹窗：*分类名称、分类图标(复用统一选图弹窗，建议400×400)、序号(越大越靠前)、状态(显示/隐藏单选)
 * 商户过滤视图预留（merchantViewOf 注入 bid='gt0'）
 */
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  deleteBusinessCategory,
  getBusinessCategoryList,
  saveBusinessCategory,
  type BusinessCategoryItem,
} from '../../api/business'
import ImgIconPicker from '../../components/ImgIconPicker.vue'
import { Close, Plus } from '@element-plus/icons-vue'

withDefaults(defineProps<{ bid?: string }>(), { bid: '' })

// ============ 列表 ============
const loading = ref(false)
const tableData = ref<BusinessCategoryItem[]>([])
const total = ref(0)
const selectedRows = ref<BusinessCategoryItem[]>([])

const query = reactive({
  page: 1,
  pagesize: 20,
  field: 'sort',
  order: 'desc' as 'asc' | 'desc',
})

async function fetchList() {
  loading.value = true
  try {
    const data = await getBusinessCategoryList({
      page: query.page,
      pagesize: query.pagesize,
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

/** 表头排序 */
function handleSortChange({ prop, order }: { prop: string; order: string | null }) {
  if (!order) {
    query.field = 'sort'
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

function handleSelectionChange(rows: BusinessCategoryItem[]) {
  selectedRows.value = rows
}

// ============ 新增 / 编辑 ============
const dialogVisible = ref(false)
const saving = ref(false)
const form = reactive({
  id: 0,
  name: '',
  pic: '',
  sort: 0,
  status: 1,
})

function resetForm() {
  form.id = 0
  form.name = ''
  form.pic = ''
  form.sort = 0
  form.status = 1
}

function openAdd() {
  resetForm()
  dialogVisible.value = true
}

function openEdit(row: BusinessCategoryItem) {
  form.id = row.id
  form.name = row.name
  form.pic = row.pic || ''
  form.sort = row.sort
  form.status = row.status
  dialogVisible.value = true
}

async function submitSave() {
  if (!form.name.trim()) {
    ElMessage.warning('请填写分类名称')
    return
  }
  saving.value = true
  try {
    await saveBusinessCategory({
      id: form.id || undefined,
      name: form.name.trim(),
      pic: form.pic,
      sort: Number(form.sort) || 0,
      status: form.status,
    })
    ElMessage.success('操作成功')
    dialogVisible.value = false
    fetchList()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    saving.value = false
  }
}

// ============ 删除 ============
function handleDelete(ids: number[]) {
  if (!ids.length) {
    ElMessage.warning('请选择数据')
    return
  }
  ElMessageBox.confirm('确定要删除吗？删除后无法恢复！', '操作确认', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      await deleteBusinessCategory(ids)
      ElMessage.success('删除成功')
      fetchList()
    })
    .catch(() => {})
}

// ============ 统一选图弹窗（上传图片 / 选择图标） ============
const imgPickerVisible = ref(false)
function openImgPicker() {
  imgPickerVisible.value = true
}
function onImgPick(urls: string[]) {
  if (urls[0]) form.pic = urls[0]
}
function removePic() {
  form.pic = ''
}

onMounted(fetchList)
</script>

<template>
  <div class="bc-root">
    <el-card shadow="never" class="bc-toolbar-card">
      <div class="bc-actions">
        <el-button type="primary" @click="openAdd">添加</el-button>
        <el-button @click="handleDelete(selectedRows.map((r) => r.id))">删除</el-button>
      </div>
    </el-card>

    <el-card shadow="never">
      <el-table
        v-loading="loading"
        :data="tableData"
        stripe
        @selection-change="handleSelectionChange"
        @sort-change="handleSortChange"
      >
        <el-table-column type="selection" width="45" />
        <el-table-column
          prop="id"
          label="ID"
          width="90"
          sortable="custom"
          :order="query.field === 'id' ? (query.order === 'asc' ? 'ascending' : 'descending') : null"
        />
        <el-table-column prop="name" label="名称" min-width="180" show-overflow-tooltip />
        <el-table-column label="图片" width="120">
          <template #default="{ row }">
            <el-image
              v-if="row.pic"
              :src="row.pic"
              fit="cover"
              class="bc-thumb"
              :preview-src-list="[row.pic]"
              preview-teleported
            />
            <span v-else class="bc-empty">-</span>
          </template>
        </el-table-column>
        <el-table-column
          prop="sort"
          label="序号"
          width="100"
          sortable="custom"
          :order="query.field === 'sort' ? (query.order === 'asc' ? 'ascending' : 'descending') : null"
        />
        <el-table-column label="状态" width="90">
          <template #default="{ row }">
            <span :class="row.status === 1 ? 'bc-show' : 'bc-hide'">{{ row.status === 1 ? '显示' : '隐藏' }}</span>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="150" fixed="right">
          <template #default="{ row }">
            <el-button link type="primary" @click="openEdit(row)">编辑</el-button>
            <el-button link type="danger" @click="handleDelete([row.id])">删除</el-button>
          </template>
        </el-table-column>
      </el-table>

      <el-pagination
        class="bc-pagination"
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

    <!-- 添加 / 编辑 商家分类 -->
    <el-dialog
      v-model="dialogVisible"
      :title="form.id ? '编辑商家分类' : '+添加商家分类'"
      width="560px"
      :close-on-click-modal="false"
      append-to-body
    >
      <el-form label-width="92px" label-position="right" class="bc-form">
        <el-form-item required>
          <template #label><span class="bc-req">*</span>分类名称：</template>
          <el-input v-model="form.name" placeholder="请输入分类名称" maxlength="50" style="width: 260px" />
        </el-form-item>

        <el-form-item label="分类图标：">
          <div class="bc-upload">
            <div class="bc-upload-line">
              <el-button @click="openImgPicker">上传图片</el-button>
              <span class="bc-upload-tip">建议尺寸：400×400像素</span>
            </div>
            <div class="bc-preview" @click="openImgPicker">
              <el-image v-if="form.pic" :src="form.pic" fit="cover" class="bc-preview-img" />
              <div v-else class="bc-preview-empty">
                <el-icon><Plus /></el-icon>
              </div>
              <span v-if="form.pic" class="bc-preview-del" @click.stop="removePic">
                <el-icon><Close /></el-icon>
              </span>
            </div>
          </div>
        </el-form-item>

        <el-form-item label="序号：">
          <el-input v-model.number="form.sort" type="number" style="width: 160px" />
          <span class="bc-form-tip">用于排序,越大越靠前</span>
        </el-form-item>

        <el-form-item label="状态：">
          <el-radio-group v-model="form.status">
            <el-radio :value="1">显示</el-radio>
            <el-radio :value="0">隐藏</el-radio>
          </el-radio-group>
        </el-form-item>

        <el-form-item>
          <el-button type="primary" :loading="saving" @click="submitSave">提 交</el-button>
        </el-form-item>
      </el-form>
    </el-dialog>

    <ImgIconPicker v-model:visible="imgPickerVisible" default-tab="upload" @pick="onImgPick" />
  </div>
</template>


<style scoped>
.bc-toolbar-card {
  margin-bottom: 14px;
}

.bc-actions {
  display: flex;
  gap: 8px;
}

.bc-pagination {
  margin-top: 16px;
  justify-content: flex-end;
}

.bc-thumb {
  width: 50px;
  height: 50px;
  border-radius: 4px;
}

.bc-empty {
  color: #c0c4cc;
}

.bc-show {
  color: #19be6b;
}

.bc-hide {
  color: #f56c6c;
}

.bc-req {
  color: #f56c6c;
  margin-right: 2px;
}

.bc-upload-line {
  display: flex;
  align-items: center;
  gap: 10px;
}

.bc-upload-tip {
  color: #909399;
  font-size: 12px;
}

.bc-preview {
  position: relative;
  margin-top: 10px;
  width: 96px;
  height: 96px;
  border: 1px dashed #dcdfe6;
  border-radius: 4px;
  cursor: pointer;
  overflow: hidden;
}

.bc-preview-img {
  width: 96px;
  height: 96px;
  display: block;
}

.bc-preview-empty {
  width: 100%;
  height: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #c0c4cc;
  font-size: 22px;
}

.bc-preview-del {
  position: absolute;
  top: 2px;
  right: 2px;
  width: 18px;
  height: 18px;
  border-radius: 50%;
  background: rgba(0, 0, 0, 0.55);
  color: #fff;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 12px;
}

.bc-form-tip {
  margin-left: 10px;
  color: #909399;
  font-size: 12px;
}
</style>
