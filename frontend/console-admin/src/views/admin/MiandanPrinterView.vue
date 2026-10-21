<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { addPrinter, delPrinter, getPrinterList } from '../../api/ewaybill'
import type { MiandanPrinter } from '../../api/ewaybill'
import { getMemberList } from '../../api/member'
import type { MemberRow } from '../../api/member'

defineEmits<{ (e: 'close'): void }>()

// ===== 打印员列表 =====
const loading = ref(false)
const list = ref<MiandanPrinter[]>([])

async function load() {
  loading.value = true
  try {
    const d = await getPrinterList()
    list.value = d.list || []
  } catch {
    /* 拦截器已提示 */
  } finally {
    loading.value = false
  }
}

async function handleUnbind(row: MiandanPrinter) {
  try {
    await ElMessageBox.confirm('确定要解绑吗？', '操作确认', { type: 'warning' })
  } catch {
    return
  }
  try {
    await delPrinter({ openid: row.openid })
    ElMessage.success('解绑成功')
    load()
  } catch {
    /* 拦截器已提示 */
  }
}

// ===== 添加打印员弹窗 =====
const addVisible = ref(false)
const openidInput = ref('')
const submitting = ref(false)

function openAdd() {
  openidInput.value = ''
  addVisible.value = true
}

async function submitAdd() {
  if (!openidInput.value.trim()) {
    ElMessage.warning('请填写会员ID或openid')
    return
  }
  submitting.value = true
  try {
    await addPrinter({ openid: openidInput.value.trim() })
    ElMessage.success('添加成功')
    addVisible.value = false
    load()
  } catch {
    /* 拦截器已提示 */
  } finally {
    submitting.value = false
  }
}

// ===== 查找会员ID（二级弹窗） =====
const pickerVisible = ref(false)
const members = ref<MemberRow[]>([])
const pickerLoading = ref(false)

async function openPicker() {
  pickerVisible.value = true
  pickerLoading.value = true
  try {
    const d = await getMemberList({ page: 1, pagesize: 20 } as any)
    members.value = d.list || []
  } catch {
    members.value = []
  } finally {
    pickerLoading.value = false
  }
}

function pickMember(m: MemberRow) {
  // 数字会员ID回填，后端按会员ID反查 openid
  openidInput.value = String(m.id)
  pickerVisible.value = false
}

onMounted(load)
</script>

<template>
  <div class="subpage">
    <div class="subpage-head">
      <span class="subpage-title">打印员列表</span>
      <span class="subpage-close" @click="$emit('close')">✕</span>
    </div>
    <div class="toolbar">
      <el-button type="primary" @click="openAdd">添加打印员</el-button>
    </div>
    <el-table v-loading="loading" :data="list" style="width: 100%" :header-cell-style="{ background: '#e6e6e6' }">
      <el-table-column type="selection" width="40" />
      <el-table-column prop="openid" label="openid" min-width="220" align="left" />
      <el-table-column label="头像昵称" min-width="200" align="left">
        <template #default="{ row }">
          <div class="nick">
            <img v-if="row.headimg" :src="row.headimg" class="avatar" />
            <span>{{ row.nickname }}</span>
          </div>
        </template>
      </el-table-column>
      <el-table-column label="操作" min-width="80" align="left">
        <template #default="{ row }">
          <el-button link type="primary" @click="handleUnbind(row)">解绑</el-button>
        </template>
      </el-table-column>
    </el-table>

    <!-- 添加打印员弹窗 -->
    <el-dialog v-model="addVisible" title="" width="500px" :show-close="false" append-to-body>
      <div class="add-row">
        <span class="add-label">会员ID或openid：</span>
        <el-input v-model="openidInput" class="field-md" />
        <el-link type="primary" :underline="false" @click="openPicker">查找会员ID</el-link>
      </div>
      <template #footer>
        <el-button type="primary" :loading="submitting" @click="submitAdd">确定</el-button>
        <el-button @click="addVisible = false">取消</el-button>
      </template>
    </el-dialog>

    <!-- 查找会员ID 二级弹窗 -->
    <el-dialog v-model="pickerVisible" title="选择会员" width="560px" append-to-body>
      <el-table v-loading="pickerLoading" :data="members" max-height="360" :header-cell-style="{ background: '#e6e6e6' }">
        <el-table-column prop="id" label="ID" width="70" align="left" />
        <el-table-column prop="nickname" label="昵称" min-width="160" align="left" />
        <el-table-column prop="tel" label="手机号" min-width="130" align="left" />
        <el-table-column label="操作" width="80" align="left">
          <template #default="{ row }">
            <el-button link type="primary" @click="pickMember(row)">选择</el-button>
          </template>
        </el-table-column>
      </el-table>
    </el-dialog>
  </div>
</template>

<style scoped>
.subpage-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 3px 0;
}
.subpage-title {
  font-size: 16px;
  font-weight: 600;
}
.subpage-close {
  cursor: pointer;
  font-size: 20px;
  font-weight: bold;
  color: #606266;
  padding: 0 6px;
}
.subpage-close:hover {
  color: #f56c6c;
}
.toolbar {
  margin: 3px 0;
}
.nick {
  display: flex;
  align-items: center;
  gap: 8px;
}
.avatar {
  width: 40px;
  height: 40px;
  border-radius: 4px;
  object-fit: cover;
  background: #f0f0f0;
}
.add-row {
  display: flex;
  align-items: center;
  gap: 10px;
  margin: 6px 0;
}
.add-label {
  white-space: nowrap;
}
</style>
