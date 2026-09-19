<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import type { CashdeskConfig, CustomPayItem } from '../../api/restaurantCashdesk'
import {
  deleteCustomPay,
  fetchConfig,
  saveConfig,
  saveCustomPay,
} from '../../api/restaurantCashdesk'

const loading = ref(false)
const saving = ref(false)
const config = ref<CashdeskConfig | null>(null)

const form = reactive({
  wxpay: 0,
  cashpay: 1,
  moneypay: 1,
  remove_zero_length: 0,
  reverse_audit: 0,
})

async function loadConfig() {
  loading.value = true
  try {
    const data = await fetchConfig()
    config.value = data
    form.wxpay = data.wxpay
    form.cashpay = data.cashpay
    form.moneypay = data.moneypay
    form.remove_zero_length = data.remove_zero_length
    form.reverse_audit = data.reverse_audit
  } catch {
    // 错误提示由拦截器弹出
  } finally {
    loading.value = false
  }
}

async function handleSave() {
  saving.value = true
  try {
    await saveConfig({
      wxpay: form.wxpay,
      cashpay: form.cashpay,
      moneypay: form.moneypay,
      remove_zero_length: form.remove_zero_length,
      reverse_audit: form.reverse_audit,
    })
    ElMessage.success('保存成功')
    await loadConfig()
  } catch {
    // 拦截器已提示
  } finally {
    saving.value = false
  }
}

// ============ 自定义支付字典（字段名 title） ============
const customDialog = reactive({ visible: false, id: 0, title: '', sort: 0, status: 1 })

function openCustomDialog(item?: CustomPayItem) {
  customDialog.id = item?.id || 0
  customDialog.title = item?.title || ''
  customDialog.sort = item?.sort ?? 0
  customDialog.status = item?.status ?? 1
  customDialog.visible = true
}

async function submitCustom() {
  if ('' === customDialog.title.trim()) {
    ElMessage.warning('请填写支付方式名称')
    return
  }
  try {
    await saveCustomPay({
      id: customDialog.id > 0 ? customDialog.id : undefined,
      title: customDialog.title.trim(),
      sort: customDialog.sort,
      status: customDialog.status,
    })
    customDialog.visible = false
    ElMessage.success('保存成功')
    await loadConfig()
  } catch {
    // 拦截器已提示
  }
}

async function handleDeleteCustom(item: CustomPayItem) {
  try {
    await ElMessageBox.confirm(`确定删除支付方式「${item.title}」？`, '删除确认', { type: 'warning' })
  } catch {
    return
  }
  try {
    await deleteCustomPay(item.id)
    ElMessage.success('删除成功')
    await loadConfig()
  } catch {
    // 拦截器已提示
  }
}

onMounted(loadConfig)
</script>

<template>
  <el-card v-loading="loading" shadow="never">
    <template #header>
      <div class="card-header">
        <span class="card-title">餐饮收银设置</span>
        <el-button type="primary" :loading="saving" @click="handleSave">保存</el-button>
      </div>
    </template>

    <el-form label-width="130px" class="set-form">
      <el-form-item label="微信支付">
        <el-switch v-model="form.wxpay" :active-value="1" :inactive-value="0" />
        <span class="form-tip">模拟通道：扫码后直接确认收款</span>
      </el-form-item>
      <el-form-item label="现金支付">
        <el-switch v-model="form.cashpay" :active-value="1" :inactive-value="0" />
      </el-form-item>
      <el-form-item label="余额支付">
        <el-switch v-model="form.moneypay" :active-value="1" :inactive-value="0" />
        <span class="form-tip">余额支付需订单绑定会员</span>
      </el-form-item>
      <el-form-item label="默认抹零位数">
        <el-select v-model="form.remove_zero_length" style="width: 140px">
          <el-option :value="0" label="不抹零" />
          <el-option :value="1" label="抹分" />
          <el-option :value="2" label="抹角" />
          <el-option :value="3" label="抹元（≥100元）" />
        </el-select>
        <span class="form-tip">开台订单默认继承该配置，可在结账前按单调整</span>
      </el-form-item>
      <el-form-item label="反结账审核">
        <el-switch v-model="form.reverse_audit" :active-value="1" :inactive-value="0" />
        <span class="form-tip">开启后反结账需审核通过才回滚，关闭则直接撤销回进行中</span>
      </el-form-item>
    </el-form>

    <el-divider content-position="left">自定义支付方式</el-divider>

    <div style="margin-bottom: 12px">
      <el-button type="primary" size="small" @click="openCustomDialog()">新增支付方式</el-button>
    </div>

    <el-table :data="config?.custom_pays || []" border size="small">
      <el-table-column prop="title" label="名称" min-width="160" />
      <el-table-column prop="paytypeid" label="支付方式ID" width="120" align="right" />
      <el-table-column prop="sort" label="排序" width="90" align="right" />
      <el-table-column label="状态" width="90">
        <template #default="{ row }">
          <el-tag size="small" :type="row.status === 1 ? 'success' : 'info'">
            {{ row.status === 1 ? '启用' : '停用' }}
          </el-tag>
        </template>
      </el-table-column>
      <el-table-column label="操作" width="140">
        <template #default="{ row }">
          <el-button link type="primary" size="small" @click="openCustomDialog(row)">编辑</el-button>
          <el-button link type="danger" size="small" @click="handleDeleteCustom(row)">删除</el-button>
        </template>
      </el-table-column>
    </el-table>

    <!-- 自定义支付编辑 -->
    <el-dialog v-model="customDialog.visible" :title="customDialog.id > 0 ? '编辑支付方式' : '新增支付方式'" width="420px">
      <el-form label-width="80px">
        <el-form-item label="名称">
          <el-input v-model="customDialog.title" maxlength="30" placeholder="如：挂账/美团/抖音" />
        </el-form-item>
        <el-form-item label="排序">
          <el-input-number v-model="customDialog.sort" :min="0" :max="9999" />
        </el-form-item>
        <el-form-item label="状态">
          <el-switch v-model="customDialog.status" :active-value="1" :inactive-value="0" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="customDialog.visible = false">取消</el-button>
        <el-button type="primary" @click="submitCustom">保存</el-button>
      </template>
    </el-dialog>
  </el-card>
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

.set-form {
  max-width: 860px;
}

.form-tip {
  margin-left: 8px;
  color: #909399;
  font-size: 12px;
}
</style>
