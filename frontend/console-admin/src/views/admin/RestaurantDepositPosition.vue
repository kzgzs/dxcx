<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  deleteDepositNumber,
  deleteDepositPosition,
  getDepositNumberList,
  getDepositPositionList,
  saveDepositNumber,
  saveDepositPosition,
  type DepositNumberItem,
  type DepositPositionItem,
} from '../../api/restaurantDeposit'

// ============ 位置 ============
const positionLoading = ref(false)
const positionList = ref<DepositPositionItem[]>([])
const positionKeyword = ref('')
const currentPositionId = ref(0)

async function loadPositions() {
  positionLoading.value = true
  try {
    const res = await getDepositPositionList(positionKeyword.value)
    positionList.value = res?.list ?? []
    if (currentPositionId.value > 0 && !positionList.value.some((p) => p.id === currentPositionId.value)) {
      currentPositionId.value = 0
    }
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    positionLoading.value = false
  }
}

const positionDialogVisible = ref(false)
const positionSubmitting = ref(false)
const positionForm = reactive({ id: 0, name: '', sort: 0, status: 1 })

function openPositionAdd() {
  Object.assign(positionForm, { id: 0, name: '', sort: 0, status: 1 })
  positionDialogVisible.value = true
}

function openPositionEdit(row: DepositPositionItem) {
  Object.assign(positionForm, { id: row.id, name: row.name, sort: row.sort, status: row.status })
  positionDialogVisible.value = true
}

async function submitPosition() {
  if (!positionForm.name.trim()) {
    ElMessage.warning('请填写位置名称')
    return
  }
  positionSubmitting.value = true
  try {
    await saveDepositPosition({
      id: positionForm.id > 0 ? positionForm.id : undefined,
      name: positionForm.name.trim(),
      sort: positionForm.sort,
      status: positionForm.status,
    })
    ElMessage.success(positionForm.id > 0 ? '保存成功' : '添加成功')
    positionDialogVisible.value = false
    loadPositions()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    positionSubmitting.value = false
  }
}

async function handlePositionDelete(row: DepositPositionItem) {
  try {
    await ElMessageBox.confirm(
      `确定删除位置「${row.name}」？其下${row.number_count}个编号将一并删除`,
      '提示',
      { type: 'warning' },
    )
  } catch {
    return
  }
  try {
    await deleteDepositPosition(row.id)
    ElMessage.success('删除成功')
    if (currentPositionId.value === row.id) currentPositionId.value = 0
    loadPositions()
    loadNumbers()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  }
}

// ============ 编号 ============
const numberLoading = ref(false)
const numberList = ref<DepositNumberItem[]>([])
const numberKeyword = ref('')

async function loadNumbers() {
  numberLoading.value = true
  try {
    const res = await getDepositNumberList({
      position_id: currentPositionId.value > 0 ? currentPositionId.value : undefined,
      keyword: numberKeyword.value,
    })
    numberList.value = res?.list ?? []
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    numberLoading.value = false
  }
}

function handleSelectPosition(row: DepositPositionItem) {
  currentPositionId.value = row.id
  loadNumbers()
}

const numberDialogVisible = ref(false)
const numberSubmitting = ref(false)
const numberForm = reactive({ id: 0, position_id: 0, name: '', sort: 0, status: 1 })

function openNumberAdd(row?: DepositNumberItem) {
  Object.assign(numberForm, {
    id: 0,
    position_id: row ? row.position_id : currentPositionId.value,
    name: '',
    sort: 0,
    status: 1,
  })
  numberDialogVisible.value = true
}

function openNumberEdit(row: DepositNumberItem) {
  Object.assign(numberForm, {
    id: row.id,
    position_id: row.position_id,
    name: row.name,
    sort: row.sort,
    status: row.status,
  })
  numberDialogVisible.value = true
}

async function submitNumber() {
  if (!numberForm.name.trim()) {
    ElMessage.warning('请填写编号名称')
    return
  }
  if (numberForm.position_id <= 0) {
    ElMessage.warning('请选择所属位置')
    return
  }
  numberSubmitting.value = true
  try {
    await saveDepositNumber({
      id: numberForm.id > 0 ? numberForm.id : undefined,
      position_id: numberForm.position_id,
      name: numberForm.name.trim(),
      sort: numberForm.sort,
      status: numberForm.status,
    })
    ElMessage.success(numberForm.id > 0 ? '保存成功' : '添加成功')
    numberDialogVisible.value = false
    loadNumbers()
    loadPositions()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    numberSubmitting.value = false
  }
}

async function handleNumberDelete(row: DepositNumberItem) {
  try {
    await ElMessageBox.confirm(`确定删除编号「${row.name}」？`, '提示', { type: 'warning' })
  } catch {
    return
  }
  try {
    await deleteDepositNumber(row.id)
    ElMessage.success('删除成功')
    loadNumbers()
    loadPositions()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  }
}

onMounted(() => {
  loadPositions()
  loadNumbers()
})
</script>

<template>
  <div>
    <el-row :gutter="16">
      <!-- 左：位置字典 -->
      <el-col :span="10">
        <el-card shadow="never">
          <div class="panel-header">
            <div class="list-title">寄存位置</div>
            <div>
              <el-input
                v-model="positionKeyword"
                placeholder="搜索位置"
                clearable
                size="small"
                style="width: 150px; margin-right: 8px"
                @keyup.enter="loadPositions"
                @clear="loadPositions"
              />
              <el-button type="primary" size="small" @click="openPositionAdd">新增位置</el-button>
            </div>
          </div>
          <el-table
            v-loading="positionLoading"
            :data="positionList"
            border
            stripe
            highlight-current-row
            @row-click="handleSelectPosition"
          >
            <el-table-column prop="id" label="ID" width="60" />
            <el-table-column label="位置名称" min-width="120">
              <template #default="{ row }">
                {{ row.name }}
                <el-tag v-if="row.id === currentPositionId" size="small" type="success" style="margin-left: 6px">
                  当前
                </el-tag>
              </template>
            </el-table-column>
            <el-table-column prop="number_count" label="编号数" width="80" align="center" />
            <el-table-column label="排序" width="70" align="center">
              <template #default="{ row }">{{ row.sort }}</template>
            </el-table-column>
            <el-table-column label="状态" width="80" align="center">
              <template #default="{ row }">
                <el-tag :type="row.status === 1 ? 'success' : 'info'" size="small">
                  {{ row.status === 1 ? '启用' : '停用' }}
                </el-tag>
              </template>
            </el-table-column>
            <el-table-column label="操作" width="110">
              <template #default="{ row }">
                <el-button link type="primary" @click.stop="openPositionEdit(row)">编辑</el-button>
                <el-button link type="danger" @click.stop="handlePositionDelete(row)">删除</el-button>
              </template>
            </el-table-column>
          </el-table>
          <div class="panel-tip">点击行可查看/维护该位置下的编号</div>
        </el-card>
      </el-col>

      <!-- 右：编号字典 -->
      <el-col :span="14">
        <el-card shadow="never">
          <div class="panel-header">
            <div class="list-title">
              存放编号
              <span v-if="currentPositionId > 0" class="text-muted">
                （{{ positionList.find((p) => p.id === currentPositionId)?.name ?? '' }}）
              </span>
            </div>
            <div>
              <el-input
                v-model="numberKeyword"
                placeholder="搜索编号"
                clearable
                size="small"
                style="width: 150px; margin-right: 8px"
                @keyup.enter="loadNumbers"
                @clear="loadNumbers"
              />
              <el-button type="primary" size="small" @click="openNumberAdd()">新增编号</el-button>
            </div>
          </div>
          <el-table v-loading="numberLoading" :data="numberList" border stripe>
            <el-table-column prop="id" label="ID" width="60" />
            <el-table-column prop="name" label="编号名称" min-width="120" />
            <el-table-column label="所属位置" min-width="110">
              <template #default="{ row }">
                {{ row.position_name || '-' }}
              </template>
            </el-table-column>
            <el-table-column label="排序" width="70" align="center">
              <template #default="{ row }">{{ row.sort }}</template>
            </el-table-column>
            <el-table-column label="状态" width="80" align="center">
              <template #default="{ row }">
                <el-tag :type="row.status === 1 ? 'success' : 'info'" size="small">
                  {{ row.status === 1 ? '启用' : '停用' }}
                </el-tag>
              </template>
            </el-table-column>
            <el-table-column label="操作" width="110">
              <template #default="{ row }">
                <el-button link type="primary" @click="openNumberEdit(row)">编辑</el-button>
                <el-button link type="danger" @click="handleNumberDelete(row)">删除</el-button>
              </template>
            </el-table-column>
          </el-table>
        </el-card>
      </el-col>
    </el-row>

    <!-- 位置编辑弹窗 -->
    <el-dialog v-model="positionDialogVisible" :title="positionForm.id > 0 ? '编辑位置' : '新增位置'" width="420px">
      <el-form label-width="90px">
        <el-form-item label="位置名称" required>
          <el-input v-model="positionForm.name" maxlength="30" placeholder="如：吧台酒柜 / 二楼储物间" />
        </el-form-item>
        <el-form-item label="排序">
          <el-input-number v-model="positionForm.sort" :min="0" :max="9999" />
          <span class="form-tip">数值越大越靠前</span>
        </el-form-item>
        <el-form-item label="状态">
          <el-switch v-model="positionForm.status" :active-value="1" :inactive-value="0" />
          <span class="form-tip">停用后添加寄存时不可选</span>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="positionDialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="positionSubmitting" @click="submitPosition">保存</el-button>
      </template>
    </el-dialog>

    <!-- 编号编辑弹窗 -->
    <el-dialog v-model="numberDialogVisible" :title="numberForm.id > 0 ? '编辑编号' : '新增编号'" width="420px">
      <el-form label-width="90px">
        <el-form-item label="所属位置" required>
          <el-select v-model="numberForm.position_id" placeholder="选择位置" style="width: 100%">
            <el-option v-for="p in positionList" :key="p.id" :label="p.name" :value="p.id" />
          </el-select>
        </el-form-item>
        <el-form-item label="编号名称" required>
          <el-input v-model="numberForm.name" maxlength="30" placeholder="如：A-01 / 1号柜" />
        </el-form-item>
        <el-form-item label="排序">
          <el-input-number v-model="numberForm.sort" :min="0" :max="9999" />
        </el-form-item>
        <el-form-item label="状态">
          <el-switch v-model="numberForm.status" :active-value="1" :inactive-value="0" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="numberDialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="numberSubmitting" @click="submitNumber">保存</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped>
.list-title {
  font-size: 16px;
  font-weight: 600;
}

.panel-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 16px;
  flex-wrap: wrap;
  gap: 8px;
}

.panel-tip {
  margin-top: 10px;
  color: #909399;
  font-size: 12px;
}

.text-muted {
  color: #909399;
  font-size: 13px;
  font-weight: 400;
}

.form-tip {
  margin-left: 12px;
  color: #909399;
  font-size: 12px;
}
</style>
