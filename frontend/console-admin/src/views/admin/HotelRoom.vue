<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import type { FormInstance, FormRules } from 'element-plus'
import {
  addHotelRoom,
  deleteHotelRoom,
  editHotelRoom,
  getHotelList,
  getHotelRoomList,
  setHotelRoomStatus,
} from '../../api/hotel'
import type { HotelItem, HotelRoomItem } from '../../api/types'
import { formatTime } from '../../utils/time'
import ImgIconPicker from '../../components/ImgIconPicker.vue'

// ============ 酒店选项 ============
const hotelOptions = ref<HotelItem[]>([])

async function fetchHotels() {
  try {
    const data = await getHotelList({ page: 1, pagesize: 100 })
    hotelOptions.value = data.list || []
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  }
}

// ============ 列表 ============
const loading = ref(false)
const tableData = ref<HotelRoomItem[]>([])
const total = ref(0)

const query = reactive({
  hotel_id: '' as number | '',
  page: 1,
  pagesize: 10,
})

async function fetchList() {
  if (query.hotel_id === '') {
    tableData.value = []
    total.value = 0
    return
  }
  loading.value = true
  try {
    const data = await getHotelRoomList({
      hotel_id: query.hotel_id,
      page: query.page,
      pagesize: query.pagesize,
    })
    tableData.value = data.list || []
    total.value = data.total || 0
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    loading.value = false
  }
}

function handleHotelChange() {
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
async function handleStatusChange(row: HotelRoomItem) {
  try {
    await setHotelRoomStatus(row.id, row.status)
    ElMessage.success(row.status === 1 ? '已启用' : '已停用')
  } catch {
    // 接口失败回滚开关状态；错误提示已由 axios 拦截器统一弹出
    row.status = row.status === 1 ? 0 : 1
  }
}

// ============ 删除 ============
function handleDelete(row: HotelRoomItem) {
  ElMessageBox.confirm(`确定要删除房型「${row.name}」吗？删除后不可恢复。`, '删除确认', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await deleteHotelRoom(row.id)
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
  hotel_id: '' as number | '',
  name: '',
  bed_type: '',
  area: 0,
  price: 0,
  deposit: 0,
  room_num: 1,
  pic: '',
  desc: '',
  status: 1,
})

const rules: FormRules = {
  hotel_id: [{ required: true, message: '请选择所属酒店', trigger: 'change' }],
  name: [{ required: true, message: '请输入房型名称', trigger: 'blur' }],
  bed_type: [{ required: true, message: '请输入床型', trigger: 'blur' }],
  price: [{ required: true, message: '请输入价格', trigger: 'blur' }],
  room_num: [{ required: true, message: '请输入房间数量', trigger: 'blur' }],
}

function openAdd() {
  isEdit.value = false
  Object.assign(form, {
    id: 0,
    hotel_id: query.hotel_id,
    name: '',
    bed_type: '',
    area: 0,
    price: 0,
    deposit: 0,
    room_num: 1,
    pic: '',
    desc: '',
    status: 1,
  })
  dialogVisible.value = true
}

function openEdit(row: HotelRoomItem) {
  isEdit.value = true
  Object.assign(form, {
    id: row.id,
    hotel_id: row.hotel_id,
    name: row.name,
    bed_type: row.bed_type,
    area: row.area,
    price: row.price,
    deposit: row.deposit,
    room_num: row.room_num,
    pic: row.pic || '',
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
        hotel_id: Number(form.hotel_id),
        name: form.name,
        bed_type: form.bed_type,
        area: form.area,
        price: form.price,
        deposit: form.deposit,
        room_num: form.room_num,
        pic: form.pic,
        desc: form.desc,
        status: form.status,
      }
      if (isEdit.value) {
        await editHotelRoom(params)
        ElMessage.success('保存成功')
      } else {
        await addHotelRoom(params)
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

// ============ 图片选择（任务294：ImgIconPicker，默认素材库） ============
const imgPickerVisible = ref(false)

function openImgPicker() {
  imgPickerVisible.value = true
}

function onImgPick(urls: string[]) {
  if (urls.length) form.pic = urls[0]
}

onMounted(() => {
  fetchHotels()
})
</script>

<template>
  <div>
    <!-- 工具栏：酒店筛选必选 -->
    <el-card shadow="never" class="toolbar-card">
      <el-form inline @submit.prevent>
        <el-form-item label="所属酒店" required>
          <el-select
            v-model="query.hotel_id"
            placeholder="请先选择酒店"
            clearable
            filterable
            style="width: 260px"
            @change="handleHotelChange"
          >
            <el-option
              v-for="h in hotelOptions"
              :key="h.id"
              :label="h.name"
              :value="h.id"
            />
          </el-select>
        </el-form-item>
        <el-form-item style="float: right">
          <el-button type="success" :disabled="query.hotel_id === ''" @click="openAdd">
            新增房型
          </el-button>
        </el-form-item>
      </el-form>
    </el-card>

    <!-- 房型列表 -->
    <el-card shadow="never">
      <el-empty v-if="query.hotel_id === ''" description="请先在上方选择酒店" />
      <template v-else>
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
          <el-table-column prop="name" label="房型名称" min-width="130" show-overflow-tooltip />
          <el-table-column prop="bed_type" label="床型" width="110" />
          <el-table-column label="面积" width="90">
            <template #default="{ row }">{{ row.area }}㎡</template>
          </el-table-column>
          <el-table-column label="价格" width="100">
            <template #default="{ row }">¥{{ Number(row.price).toFixed(2) }}</template>
          </el-table-column>
          <el-table-column label="押金" width="100">
            <template #default="{ row }">¥{{ Number(row.deposit).toFixed(2) }}</template>
          </el-table-column>
          <el-table-column prop="room_num" label="房间数" width="80" />
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
      </template>
    </el-card>

    <!-- 新增 / 编辑弹窗 -->
    <el-dialog
      v-model="dialogVisible"
      :title="isEdit ? '编辑房型' : '新增房型'"
      width="520px"
    >
      <el-form
        ref="formRef"
        :model="form"
        :rules="rules"
        label-width="90px"
        label-position="left"
      >
        <el-form-item label="所属酒店" prop="hotel_id">
          <el-select v-model="form.hotel_id" placeholder="请选择酒店" filterable style="width: 100%">
            <el-option
              v-for="h in hotelOptions"
              :key="h.id"
              :label="h.name"
              :value="h.id"
            />
          </el-select>
        </el-form-item>
        <el-form-item label="房型名称" prop="name">
          <el-input v-model="form.name" placeholder="如：豪华大床房" />
        </el-form-item>
        <el-form-item label="床型" prop="bed_type">
          <el-input v-model="form.bed_type" placeholder="如：大床 / 双床 / 圆床" />
        </el-form-item>
        <el-form-item label="面积">
          <el-input-number v-model="form.area" :min="0" :precision="0" :step="1" />
          <span class="form-tip">㎡</span>
        </el-form-item>
        <el-form-item label="价格" prop="price">
          <el-input-number v-model="form.price" :min="0" :precision="2" :step="10" />
          <span class="form-tip">元 / 晚</span>
        </el-form-item>
        <el-form-item label="押金">
          <el-input-number v-model="form.deposit" :min="0" :precision="2" :step="10" />
        </el-form-item>
        <el-form-item label="房间数量" prop="room_num">
          <el-input-number v-model="form.room_num" :min="0" :precision="0" :step="1" />
        </el-form-item>
        <el-form-item label="图片">
          <div class="img-pick-row">
            <el-image v-if="form.pic" :src="form.pic" class="img-pick-thumb" fit="cover" />
            <el-button size="small" @click="openImgPicker">选择图片</el-button>
            <el-button v-if="form.pic" size="small" link type="danger" @click="form.pic = ''">删除</el-button>
          </div>
        </el-form-item>
        <el-form-item label="描述">
          <el-input
            v-model="form.desc"
            type="textarea"
            :rows="3"
            placeholder="请输入房型描述"
          />
        </el-form-item>
        <el-form-item label="状态">
          <el-switch v-model="form.status" :active-value="1" :inactive-value="0" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="submitting" @click="handleSubmit">
          确定
        </el-button>
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

.pagination {
  margin-top: 16px;
  justify-content: flex-end;
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
