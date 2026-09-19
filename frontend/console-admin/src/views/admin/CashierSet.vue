<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  deleteCustomPay,
  getCashdeskConfig,
  getCustomPayList,
  saveCashdeskConfig,
  saveCustomPay,
} from '../../api/cashier'
import type { CustomPayItem } from '../../api/cashier'

const loading = ref(false)
const submitting = ref(false)

const form = reactive({
  name: '收银台',
  wxpay: 0,
  cashpay: 1,
  moneypay: 1,
  remove_zero_length: 0,
  paypwd_use_status: 0,
  jiaoban_print_ids: '',
})

async function fetchConfig() {
  loading.value = true
  try {
    const data = await getCashdeskConfig()
    form.name = data?.name || '收银台'
    form.wxpay = Number(data?.wxpay ?? 0)
    form.cashpay = Number(data?.cashpay ?? 0)
    form.moneypay = Number(data?.moneypay ?? 0)
    form.remove_zero_length = Number(data?.remove_zero_length ?? 0)
    form.paypwd_use_status = Number(data?.paypwd_use_status ?? 0)
    form.jiaoban_print_ids = String(data?.jiaoban_print_ids ?? '')
    customList.value = data?.custom_pays || []
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    loading.value = false
  }
}

async function handleSave() {
  submitting.value = true
  try {
    await saveCashdeskConfig({ ...form })
    ElMessage.success('保存成功')
    fetchConfig()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    submitting.value = false
  }
}

/* ============ 自定义支付方式 ============ */
const customList = ref<CustomPayItem[]>([])
const customDialog = ref(false)
const customSaving = ref(false)
const customForm = reactive({
  id: 0,
  title: '',
  sort: 0,
  status: 1,
})

async function fetchCustomList() {
  try {
    const data = await getCustomPayList()
    customList.value = data?.list || []
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  }
}

function handleCustomAdd() {
  customForm.id = 0
  customForm.title = ''
  customForm.sort = 0
  customForm.status = 1
  customDialog.value = true
}

function handleCustomEdit(row: CustomPayItem) {
  customForm.id = row.id
  customForm.title = row.title
  customForm.sort = row.sort
  customForm.status = row.status
  customDialog.value = true
}

async function handleCustomSave() {
  if (!customForm.title.trim()) {
    ElMessage.warning('请填写支付方式名称')
    return
  }
  customSaving.value = true
  try {
    await saveCustomPay({ ...customForm })
    ElMessage.success('保存成功')
    customDialog.value = false
    fetchCustomList()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    customSaving.value = false
  }
}

function handleCustomDelete(row: CustomPayItem) {
  ElMessageBox.confirm(`确认删除支付方式「${row.title}」吗？`, '操作确认', {
    confirmButtonText: '确定',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await deleteCustomPay(row.id)
        ElMessage.success('删除成功')
        fetchCustomList()
      } catch {
        // 错误提示已由 axios 拦截器统一弹出
      }
    })
    .catch(() => {})
}

onMounted(() => {
  fetchConfig()
  fetchCustomList()
})
</script>

<template>
  <div v-loading="loading">
    <el-card shadow="never">
      <div class="list-title">收银设置</div>
      <el-form label-width="140px" label-position="left" style="max-width: 700px">
        <el-form-item label="收银台名称">
          <el-input v-model="form.name" maxlength="30" style="width: 260px" />
        </el-form-item>
        <el-form-item label="微信支付">
          <el-switch v-model="form.wxpay" :active-value="1" :inactive-value="0" />
          <span class="form-tip">付款码收款（一期为模拟通道）</span>
        </el-form-item>
        <el-form-item label="现金收款">
          <el-switch v-model="form.cashpay" :active-value="1" :inactive-value="0" />
        </el-form-item>
        <el-form-item label="余额收款">
          <el-switch v-model="form.moneypay" :active-value="1" :inactive-value="0" />
          <span class="form-tip">需绑定会员后扣会员余额</span>
        </el-form-item>
        <el-form-item label="抹零位数">
          <el-select v-model="form.remove_zero_length" style="width: 200px">
            <el-option :value="0" label="不抹零" />
            <el-option :value="1" label="抹分（10.56 → 10.5）" />
            <el-option :value="2" label="抹角（10.56 → 10）" />
            <el-option :value="3" label="抹元（110.56 → 100，金额≥100可用）" />
          </el-select>
          <span class="form-tip">收银台结算时默认抹零位数，可在结算时手动开关</span>
        </el-form-item>
        <el-form-item>
          <el-button type="primary" :loading="submitting" @click="handleSave">保存设置</el-button>
        </el-form-item>
      </el-form>
    </el-card>

    <el-card shadow="never" style="margin-top: 12px">
      <div class="list-title">
        自定义支付方式
        <el-button type="primary" size="small" style="margin-left: 12px" @click="handleCustomAdd">
          新增
        </el-button>
      </div>
      <el-table :data="customList" stripe>
        <el-table-column prop="id" label="ID" width="80" />
        <el-table-column prop="title" label="名称" min-width="180" />
        <el-table-column prop="sort" label="排序" width="90" />
        <el-table-column label="状态" width="90">
          <template #default="{ row }">
            <el-tag :type="row.status === 1 ? 'success' : 'info'" size="small">
              {{ row.status === 1 ? '启用' : '停用' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="140" fixed="right">
          <template #default="{ row }">
            <el-button link type="primary" @click="handleCustomEdit(row)">编辑</el-button>
            <el-button link type="danger" @click="handleCustomDelete(row)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>
    </el-card>

    <el-dialog v-model="customDialog" :title="customForm.id ? '编辑支付方式' : '新增支付方式'" width="420px">
      <el-form label-width="80px">
        <el-form-item label="名称">
          <el-input v-model="customForm.title" maxlength="30" placeholder="如：抖音券 / 挂账" />
        </el-form-item>
        <el-form-item label="排序">
          <el-input-number v-model="customForm.sort" :min="0" :max="9999" />
        </el-form-item>
        <el-form-item label="状态">
          <el-switch v-model="customForm.status" :active-value="1" :inactive-value="0" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="customDialog = false">取消</el-button>
        <el-button type="primary" :loading="customSaving" @click="handleCustomSave">保存</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped>
.list-title {
  font-size: 16px;
  font-weight: 600;
  margin-bottom: 20px;
}

.form-tip {
  margin-left: 12px;
  color: #909399;
  font-size: 12px;
}
</style>
