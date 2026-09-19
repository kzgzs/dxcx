<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { getDepositSysset, saveDepositSysset } from '../../api/restaurantDeposit'

const loading = ref(false)
const submitting = ref(false)

const form = reactive({
  time: '',
  status: 0,
})

async function fetchConfig() {
  loading.value = true
  try {
    const data = await getDepositSysset()
    form.time = String(data?.time ?? '')
    form.status = Number(data?.status ?? 0)
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    loading.value = false
  }
}

async function handleSave() {
  submitting.value = true
  try {
    await saveDepositSysset({ time: form.time, status: form.status })
    ElMessage.success('保存成功')
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    submitting.value = false
  }
}

onMounted(fetchConfig)
</script>

<template>
  <div v-loading="loading">
    <el-card shadow="never">
      <div class="list-title">寄存设置</div>
      <el-form label-width="140px" label-position="left" style="max-width: 640px">
        <el-form-item label="寄存功能">
          <el-switch v-model="form.status" :active-value="1" :inactive-value="0" />
          <span class="form-tip">关闭后 C端会员不可提交寄存申请（后台仍可代录入）</span>
        </el-form-item>
        <el-form-item label="寄存时限说明">
          <el-input
            v-model="form.time"
            placeholder="如：免费寄存30天，超期每日收取保管费10元"
            maxlength="100"
            show-word-limit
          />
          <span class="form-tip">展示给 C端会员的寄存时限说明文字</span>
        </el-form-item>
        <el-form-item>
          <el-button type="primary" :loading="submitting" @click="handleSave">保存设置</el-button>
        </el-form-item>
      </el-form>
    </el-card>
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
