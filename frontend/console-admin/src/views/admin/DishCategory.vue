<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import type { FormInstance, FormRules, UploadProps, UploadUserFile } from 'element-plus'
import { deleteDiningProcat, getDiningProcatList, saveDiningProcat } from '../../api/dining'
import type { DiningProcatRow } from '../../api/dining'
import type { UploadRawResponse } from '../../api/types'
import { useAuthStore } from '../../stores/auth'

const authStore = useAuthStore()

// ============ 列表（两级树） ============
interface ProcatNode extends DiningProcatRow {
  children?: ProcatNode[]
}

const loading = ref(false)
const treeData = ref<ProcatNode[]>([])
const flatList = ref<DiningProcatRow[]>([])
const keyword = ref('')

function buildTree(list: DiningProcatRow[]): ProcatNode[] {
  const map = new Map<number, ProcatNode>()
  const roots: ProcatNode[] = []
  list.forEach((item) => map.set(item.id, { ...item, children: [] }))
  list.forEach((item) => {
    const node = map.get(item.id)!
    if (item.pid > 0 && map.has(item.pid)) {
      map.get(item.pid)!.children!.push(node)
    } else {
      roots.push(node)
    }
  })
  // 清理空 children，避免表格出现空展开箭头
  map.forEach((node) => {
    if (node.children && node.children.length === 0) delete node.children
  })
  return roots
}

async function fetchList() {
  loading.value = true
  try {
    const data = await getDiningProcatList(keyword.value || undefined)
    flatList.value = data.list || []
    treeData.value = buildTree(flatList.value)
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

/** 一级分类选项（新增子分类时选择上级） */
const parentOptions = computed(() => flatList.value.filter((c) => c.pid === 0))

// ============ 删除 ============
function handleDelete(row: DiningProcatRow) {
  ElMessageBox.confirm(`确定要删除分类「${row.name}」吗？`, '删除确认', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await deleteDiningProcat(row.id)
        ElMessage.success('删除成功')
        fetchList()
      } catch {
        // 有子分类/菜品引用等错误由拦截器弹出
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
  pid: 0,
  name: '',
  pic: '',
  is_shop: 1,
  is_takeaway: 1,
  is_booking: 1,
  status: 1,
  sort: 0,
})

const rules: FormRules = {
  name: [{ required: true, message: '请输入分类名称', trigger: 'blur' }],
}

function openAdd(pid = 0) {
  Object.assign(form, { id: 0, pid, name: '', pic: '', is_shop: 1, is_takeaway: 1, is_booking: 1, status: 1, sort: 0 })
  formRef.value?.clearValidate()
  dialogVisible.value = true
}

function openEdit(row: DiningProcatRow) {
  Object.assign(form, {
    id: row.id,
    pid: row.pid,
    name: row.name,
    pic: row.pic,
    is_shop: row.is_shop,
    is_takeaway: row.is_takeaway,
    is_booking: row.is_booking,
    status: row.status,
    sort: row.sort,
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
      await saveDiningProcat({
        id: form.id,
        pid: form.pid,
        name: form.name,
        pic: form.pic,
        is_shop: form.is_shop,
        is_takeaway: form.is_takeaway,
        is_booking: form.is_booking,
        status: form.status,
        sort: form.sort,
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

// ============ 图片上传 ============
const uploadHeaders = computed(() => ({
  Authorization: `Bearer ${authStore.token}`,
}))

function parseUploadResponse(response: UploadRawResponse): string {
  if (response && response.code === 0 && response.data?.url) {
    return response.data.url
  }
  ElMessage.error(response?.msg || '上传失败')
  return ''
}

const picFileList = computed<UploadUserFile[]>(() => (form.pic ? [{ name: 'pic', url: form.pic }] : []))

const onPicSuccess: UploadProps['onSuccess'] = (response) => {
  const url = parseUploadResponse(response as UploadRawResponse)
  if (url) form.pic = url
}

const onPicRemove = () => {
  form.pic = ''
}

const onUploadError: UploadProps['onError'] = () => {
  ElMessage.error('上传失败，请重试')
}

onMounted(fetchList)
</script>

<template>
  <el-card shadow="never">
    <template #header>
      <div class="card-header">
        <span class="card-title">菜品分类</span>
        <el-button type="primary" @click="openAdd(0)">新增分类</el-button>
      </div>
    </template>

    <!-- 搜索条 -->
    <div class="search-bar">
      <el-input v-model="keyword" placeholder="分类名称" clearable style="width: 200px" @keyup.enter="handleSearch" />
      <el-button type="primary" @click="handleSearch">查询</el-button>
      <el-button @click="handleReset">重置</el-button>
    </div>

    <el-table
      v-loading="loading"
      :data="treeData"
      stripe
      border
      size="small"
      row-key="id"
      :tree-props="{ children: 'children' }"
      default-expand-all
    >
      <el-table-column prop="name" label="分类名称" min-width="160" show-overflow-tooltip />
      <el-table-column label="图片" width="70" class-name="compact-col">
        <template #default="{ row }">
          <el-image v-if="row.pic" :src="row.pic" :preview-src-list="[row.pic]" fit="cover" class="cell-pic" />
          <span v-else>-</span>
        </template>
      </el-table-column>
      <el-table-column label="店内" width="70" class-name="compact-col">
        <template #default="{ row }">
          <span :class="row.is_shop === 1 ? 'status-on' : 'status-off'">{{ row.is_shop === 1 ? '支持' : '关闭' }}</span>
        </template>
      </el-table-column>
      <el-table-column label="外卖" width="70" class-name="compact-col">
        <template #default="{ row }">
          <span :class="row.is_takeaway === 1 ? 'status-on' : 'status-off'">{{ row.is_takeaway === 1 ? '支持' : '关闭' }}</span>
        </template>
      </el-table-column>
      <el-table-column label="预定" width="70" class-name="compact-col">
        <template #default="{ row }">
          <span :class="row.is_booking === 1 ? 'status-on' : 'status-off'">{{ row.is_booking === 1 ? '支持' : '关闭' }}</span>
        </template>
      </el-table-column>
      <el-table-column prop="product_count" label="菜品数" width="76" class-name="compact-col" />
      <el-table-column label="显隐" width="70" class-name="compact-col">
        <template #default="{ row }">
          <span :class="row.status === 1 ? 'status-on' : 'status-off'">{{ row.status === 1 ? '显示' : '隐藏' }}</span>
        </template>
      </el-table-column>
      <el-table-column prop="sort" label="排序" width="60" class-name="compact-col" />
      <el-table-column label="操作" width="150" fixed="right" class-name="compact-col">
        <template #default="{ row }">
          <div class="op-wrap">
            <el-button v-if="row.pid === 0" link type="primary" @click="openAdd(row.id)">加子类</el-button>
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
      <el-form-item label="上级分类">
        <el-select v-model="form.pid" placeholder="无（一级分类）" clearable style="width: 100%">
          <el-option v-for="p in parentOptions" :key="p.id" :label="p.name" :value="p.id" :disabled="p.id === form.id" />
        </el-select>
      </el-form-item>
      <el-form-item label="分类名称" prop="name">
        <el-input v-model="form.name" placeholder="如：热菜、凉菜" maxlength="50" />
      </el-form-item>
      <el-form-item label="分类图片">
        <el-upload
          action="/admin/upload"
          name="file"
          :headers="uploadHeaders"
          list-type="picture-card"
          :file-list="picFileList"
          :limit="1"
          :on-success="onPicSuccess"
          :on-remove="onPicRemove"
          :on-error="onUploadError"
          accept="image/*"
        >
          <span>上传</span>
        </el-upload>
      </el-form-item>
      <el-form-item label="适用渠道">
        <el-checkbox v-model="form.is_shop" :true-value="1" :false-value="0">店内点餐</el-checkbox>
        <el-checkbox v-model="form.is_takeaway" :true-value="1" :false-value="0">外卖</el-checkbox>
        <el-checkbox v-model="form.is_booking" :true-value="1" :false-value="0">预定</el-checkbox>
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
</style>
