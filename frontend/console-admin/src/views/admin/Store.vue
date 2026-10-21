<script setup lang="ts">
import { onMounted, reactive, ref, computed } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import type { FormInstance, FormRules } from 'element-plus'
import { useAuthStore } from '../../stores/auth'
import { getMerchantList } from '../../api/merchant'
import { addStore, deleteStore, editStore, getStoreList } from '../../api/store'
import type { MerchantItem, StoreItem } from '../../api/types'

const authStore = useAuthStore()
const uploadUrl = '/admin/upload'
const uploadHeaders = computed(() => ({ Authorization: `Bearer ${authStore.token}` }))
function handleUploadSuccess(response: any) {
  if (response && response.code === 0 && response.data?.url) {
    form.pic = response.data.url
  } else {
    ElMessage.error(response?.msg || '上传失败')
  }
}

// ============ 商户下拉选项 ============
const merchantOptions = ref<MerchantItem[]>([])

async function fetchMerchantOptions() {
  try {
    const data = await getMerchantList({ page: 1, pagesize: 200 })
    merchantOptions.value = data.list || []
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  }
}

// ============ 列表 ============
const loading = ref(false)
const tableData = ref<StoreItem[]>([])
const total = ref(0)

const query = reactive({
  page: 1,
  pagesize: 10,
  keyword: '',
  merchant_id: '' as number | '',
})

async function fetchList() {
  loading.value = true
  try {
    const data = await getStoreList({
      page: query.page,
      pagesize: query.pagesize,
      keyword: query.keyword || undefined,
      merchant_id: query.merchant_id === '' ? undefined : query.merchant_id,
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
  query.merchant_id = ''
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

// 地址拼接：省市区 + 详细地址
function fullAddress(row: StoreItem) {
  const region = `${row.province || ''}${row.city || ''}${row.district || ''}`
  return `${region} ${row.address || ''}`.trim() || '-'
}

// ============ 状态开关（复用编辑接口） ============
async function handleStatusChange(row: StoreItem) {
  try {
    await editStore({
      id: row.id,
      merchant_id: row.merchant_id,
      name: row.name,
      contacts: row.contacts,
      phone: row.phone,
      province: row.province,
      city: row.city,
      district: row.district,
      address: row.address,
      lng: row.lng ?? '',
      lat: row.lat ?? '',
      status: row.status,
    })
    ElMessage.success(row.status === 1 ? '已启用' : '已停用')
  } catch {
    // 接口失败回滚开关状态；错误提示已由 axios 拦截器统一弹出
    row.status = row.status === 1 ? 0 : 1
  }
}

// ============ 删除 ============
function handleDelete(row: StoreItem) {
  ElMessageBox.confirm(`确定要删除门店「${row.name}」吗？删除后不可恢复。`, '删除确认', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await deleteStore(row.id)
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
  merchant_id: '' as number | '',
  name: '',
  pic: '',
  contacts: '',
  phone: '',
  province: '',
  city: '',
  district: '',
  address: '',
  lng: '',
  lat: '',
  status: 1,
})

const rules: FormRules = {
  merchant_id: [{ required: true, message: '请选择所属商户', trigger: 'change' }],
  name: [{ required: true, message: '请输入门店名称', trigger: 'blur' }],
}

function openAdd() {
  isEdit.value = false
  Object.assign(form, {
    id: 0,
    merchant_id: '',
    name: '',
    pic: '',
    contacts: '',
    phone: '',
    province: '',
    city: '',
    district: '',
    address: '',
    lng: '',
    lat: '',
    status: 1,
  })
  dialogVisible.value = true
}

function openEdit(row: StoreItem) {
  isEdit.value = true
  Object.assign(form, {
    id: row.id,
    merchant_id: row.merchant_id,
    name: row.name,
    pic: (row as any).pic ?? '',
    contacts: row.contacts,
    phone: row.phone,
    province: row.province,
    city: row.city,
    district: row.district,
    address: row.address,
    lng: row.lng ?? '',
    lat: row.lat ?? '',
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
        merchant_id: Number(form.merchant_id),
        name: form.name,
        pic: form.pic,
        contacts: form.contacts,
        phone: form.phone,
        province: form.province,
        city: form.city,
        district: form.district,
        address: form.address,
        lng: form.lng,
        lat: form.lat,
        status: form.status,
      }
      if (isEdit.value) {
        await editStore(params)
        ElMessage.success('保存成功')
      } else {
        await addStore(params)
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

onMounted(() => {
  fetchList()
  fetchMerchantOptions()
})
</script>

<template>
  <div>
    <!-- 搜索工具栏 -->
    <el-card shadow="never" class="toolbar-card">
      <el-form inline @submit.prevent>
        <el-form-item label="所属商户">
          <el-select
            v-model="query.merchant_id"
            placeholder="全部"
            clearable
            filterable
            style="width: 180px"
          >
            <el-option
              v-for="m in merchantOptions"
              :key="m.id"
              :label="m.name"
              :value="m.id"
            />
          </el-select>
        </el-form-item>
        <el-form-item label="关键词">
          <el-input
            v-model="query.keyword"
            placeholder="门店名称 / 联系人 / 电话"
            clearable
            style="width: 220px"
            @keyup.enter="handleSearch"
          />
        </el-form-item>
        <el-form-item>
          <el-button type="primary" @click="handleSearch">搜索</el-button>
          <el-button @click="handleReset">重置</el-button>
        </el-form-item>
        <el-form-item style="float: right">
          <el-button type="success" @click="openAdd">新增门店</el-button>
        </el-form-item>
      </el-form>
    </el-card>

    <!-- 门店列表 -->
    <el-card shadow="never">
      <el-table v-loading="loading" :data="tableData" stripe>
        <el-table-column prop="id" label="ID" width="70" />
        <el-table-column prop="name" label="门店名称" min-width="150" show-overflow-tooltip />
        <el-table-column prop="merchant_name" label="所属商户" min-width="130" show-overflow-tooltip />
        <el-table-column prop="contacts" label="联系人" width="100" />
        <el-table-column prop="phone" label="电话" width="130" />
        <el-table-column label="地址" min-width="220" show-overflow-tooltip>
          <template #default="{ row }">{{ fullAddress(row) }}</template>
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
        <el-table-column label="操作" width="140" fixed="right">
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
      :title="isEdit ? '编辑门店' : '新增门店'"
      width="520px"
    >
      <el-form
        ref="formRef"
        :model="form"
        :rules="rules"
        label-width="80px"
        label-position="left"
      >
        <el-form-item label="所属商户" prop="merchant_id">
          <el-select
            v-model="form.merchant_id"
            placeholder="请选择商户"
            filterable
            style="width: 100%"
          >
            <el-option
              v-for="m in merchantOptions"
              :key="m.id"
              :label="m.name"
              :value="m.id"
            />
          </el-select>
        </el-form-item>
        <el-form-item label="门店名称" prop="name">
          <el-input v-model="form.name" placeholder="请输入门店名称" />
          <span class="form-help">填写该门店所在的社区名称，方便客户寻找；若没有可不用填写</span>
        </el-form-item>
        <el-form-item label="门店图片">
          <el-upload
            class="store-uploader"
            :action="uploadUrl"
            :headers="uploadHeaders"
            :show-file-list="false"
            :on-success="handleUploadSuccess"
            accept="image/*"
          >
            <img v-if="form.pic" :src="form.pic" class="store-preview" />
            <div v-else class="store-upload-placeholder">上传图片</div>
          </el-upload>
          <span class="form-help">建议尺寸：640×640像素</span>
        </el-form-item>
        <el-form-item label="联系人">
          <el-input v-model="form.contacts" placeholder="请输入联系人" />
        </el-form-item>
        <el-form-item label="电话">
          <el-input v-model="form.phone" placeholder="请输入联系电话" />
        </el-form-item>
        <el-form-item label="所在地区">
          <div class="region-inputs">
            <el-input v-model="form.province" placeholder="省" />
            <el-input v-model="form.city" placeholder="市" />
            <el-input v-model="form.district" placeholder="区 / 县" />
          </div>
        </el-form-item>
        <el-form-item label="详细地址">
          <el-input v-model="form.address" placeholder="请输入详细地址" />
        </el-form-item>
        <el-form-item label="经纬度">
          <div class="region-inputs">
            <el-input v-model="form.lng" placeholder="经度 lng（选填）" />
            <el-input v-model="form.lat" placeholder="纬度 lat（选填）" />
          </div>
        </el-form-item>
        <el-form-item label="状态">
          <el-switch
            v-model="form.status"
            :active-value="1"
            :inactive-value="0"
            active-text="正常"
            inactive-text="停用"
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

.region-inputs {
  display: flex;
  gap: 8px;
  width: 100%;
}

.form-help {
  color: #909399;
  font-size: 12px;
  margin-left: 10px;
  line-height: 1.6;
}

.store-uploader :deep(.el-upload) {
  border: 1px solid #dcdfe6;
  border-radius: 4px;
  cursor: pointer;
  width: 120px;
  height: 120px;
  display: flex;
  align-items: center;
  justify-content: center;
  overflow: hidden;
}

.store-upload-placeholder {
  color: #909399;
  font-size: 13px;
}

.store-preview {
  width: 120px;
  height: 120px;
  object-fit: cover;
}
</style>
