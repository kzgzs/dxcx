<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { getCycleSysset, saveCycleSysset } from '../../api/cycle'

const loading = ref(false)
const saving = ref(false)

const form = reactive({
  autoshdays: 7,
  comment: 1,
})

async function fetchSet() {
  loading.value = true
  try {
    const data = await getCycleSysset()
    Object.assign(form, {
      autoshdays: data.autoshdays ?? 7,
      comment: data.comment ?? 1,
    })
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    loading.value = false
  }
}

async function handleSave() {
  saving.value = true
  try {
    await saveCycleSysset({ ...form })
    ElMessage.success('保存成功')
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    saving.value = false
  }
}

onMounted(fetchSet)
</script>

<template>
  <div v-loading="loading">
    <el-card shadow="never">
      <template #header>
        <span>周期购设置</span>
      </template>
      <el-form label-width="130px" label-position="left" style="max-width: 560px">
        <el-form-item label="自动收货天数">
          <el-input-number v-model="form.autoshdays" :min="1" :max="90" :precision="0" :step="1" />
          <span class="form-tip">每期发货后 N 天自动确认收货</span>
        </el-form-item>
        <el-form-item label="评价开关">
          <el-switch v-model="form.comment" :active-value="1" :inactive-value="0" />
          <span class="form-tip">开启后用户可对已完成的周期购商品进行评价</span>
        </el-form-item>
        <el-form-item>
          <el-button type="primary" :loading="saving" @click="handleSave">保存设置</el-button>
        </el-form-item>
      </el-form>
    </el-card>
  </div>
</template>

<style scoped>
.form-tip {
  margin-left: 8px;
  color: #909399;
  font-size: 12px;
}
</style>
