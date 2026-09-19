<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import type { FormInstance, FormRules } from 'element-plus'
import {
  deleteShortvideoCategory,
  getShortvideoCategoryList,
  saveShortvideoCategory,
} from '../../api/shortvideo'
import type { ShortvideoCategoryItem } from '../../api/shortvideo'

// ============ 列表 ============
const loading = ref(false)
const list = ref<ShortvideoCategoryItem[]>([])
const total = ref(0)
const page = ref(1)
const pagesize = ref(10)
const keyword = ref('')

// 商户过滤视图（bid='gt0'）由 tenantMenus merchantViewOf 注入
const props = withDefaults(defineProps<{ bid?: string }>(), { bid: '' })

async function fetchList() {
  loading.value = true
  try {
    const res = await getShortvideoCategoryList({
      page: page.value,
      pagesize: pagesize.value,
      keyword: keyword.value,
      bid: props.bid,
    })
    list.value = res.list
    total.value = res.total
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    loading.value = false
  }
}

function handleSearch() {
  page.value = 1
  fetchList()
}

function formatTime(ts: number) {
  if (!ts) return '-'
  const d = new Date(ts * 1000)
  const p = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}`
}

// ============ 新增 / 编辑弹窗 ============
const dialogVisible = ref(false)
const isEdit = ref(false)
const formRef = ref<FormInstance>()
const submitting = ref(false)

const form = reactive({
  id: 0,
  name: '',
  status: 1,
  sort: 0,
  bid: 0,
})

const rules: FormRules = {
  name: [{ required: true, message: '请输入分类名称', trigger: 'blur' }],
}

function resetForm() {
  Object.assign(form, { id: 0, name: '', status: 1, sort: 0, bid: 0 })
}

function openAdd() {
  isEdit.value = false
  resetForm()
  dialogVisible.value = true
}

function openEdit(row: ShortvideoCategoryItem) {
  isEdit.value = true
  resetForm()
  Object.assign(form, { id: row.id, name: row.name, status: row.status, sort: row.sort, bid: row.bid })
  dialogVisible.value = true
}

async function handleSubmit() {
  if (!formRef.value) return
  await formRef.value.validate(async (valid) => {
    if (!valid) return
    submitting.value = true
    try {
      await saveShortvideoCategory({
        id: form.id || undefined,
        name: form.name,
        status: form.status,
        sort: form.sort,
        bid: form.bid,
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

// ============ 删除 ============
function handleDelete(row: ShortvideoCategoryItem) {
  ElMessageBox.confirm(`确定要删除分类「${row.name}」吗？`, '删除确认', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await deleteShortvideoCategory(row.id)
        ElMessage.success('删除成功')
        fetchList()
      } catch {
        // 错误提示已由 axios 拦截器统一弹出
      }
    })
    .catch(() => {})
}

const isMerchantView = computed(() => props.bid === 'gt0' || props.bid === 'merchant')

onMounted(fetchList)
</script>

<template>
  <div>
    <el-card shadow="never">
      <template #header>
        <div class="card-header">
          <span>短视频分类</span>
          <div>
            <el-input
              v-model="keyword"
              placeholder="分类名称"
              clearable
              style="width: 200px; margin-right: 8px"
              @keyup.enter="handleSearch"
              @clear="handleSearch"
            />
            <el-button @click="handleSearch">搜索</el-button>
            <el-button type="success" @click="openAdd">新增分类</el-button>
          </div>
        </div>
      </template>

      <el-table v-loading="loading" :data="list" stripe row-key="id">
        <el-table-column prop="id" label="ID" width="70" />
        <el-table-column prop="name" label="分类名称" min-width="160" />
        <el-table-column label="归属" width="120">
          <template #default="{ row }">
            <el-tag :type="row.bid > 0 ? 'warning' : 'info'" size="small">{{ row.bname }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="videonum" label="视频数" width="90" />
        <el-table-column prop="sort" label="排序" width="80" />
        <el-table-column label="状态" width="90">
          <template #default="{ row }">
            <el-tag :type="row.status === 1 ? 'success' : 'info'" size="small">
              {{ row.status === 1 ? '显示' : '隐藏' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="创建时间" width="150">
          <template #default="{ row }">{{ formatTime(row.createtime) }}</template>
        </el-table-column>
        <el-table-column label="操作" width="150" fixed="right">
          <template #default="{ row }">
            <el-button link type="primary" @click="openEdit(row)">编辑</el-button>
            <el-button link type="danger" @click="handleDelete(row)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>

      <el-pagination
        v-if="total > 0"
        class="pager"
        v-model:current-page="page"
        v-model:page-size="pagesize"
        :total="total"
        layout="total, prev, pager, next"
        @current-change="fetchList"
      />
    </el-card>

    <!-- 新增 / 编辑弹窗 -->
    <el-dialog v-model="dialogVisible" :title="isEdit ? '编辑分类' : '新增分类'" width="480px">
      <el-form ref="formRef" :model="form" :rules="rules" label-width="90px" label-position="left">
        <el-form-item label="分类名称" prop="name">
          <el-input v-model="form.name" placeholder="请输入分类名称" />
        </el-form-item>
        <el-form-item v-if="!isMerchantView" label="商户ID">
          <el-input-number v-model="form.bid" :min="0" :precision="0" :step="1" />
          <span class="form-tip">0 为平台分类，大于 0 归属对应商户</span>
        </el-form-item>
        <el-form-item label="排序">
          <el-input-number v-model="form.sort" :min="0" :precision="0" :step="1" />
        </el-form-item>
        <el-form-item label="状态">
          <el-switch v-model="form.status" :active-value="1" :inactive-value="0" />
          <span class="form-tip">隐藏后 C 端不显示</span>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="submitting" @click="handleSubmit">保存</el-button>
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
</style>
