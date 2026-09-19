<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import type { FormInstance, FormRules } from 'element-plus'
import { getSystemConfig, saveSystemConfig } from '../api/system'

// 系统设置（站点基础信息，对接 console 侧通用配置接口，group=base）
const loading = ref(false)
const saving = ref(false)
const formRef = ref<FormInstance>()

const form = reactive({
  site_name: '',
  service_phone: '',
  service_email: '',
  icp: '',
})

const rules: FormRules = {
  site_name: [{ required: true, message: '请输入站点名称', trigger: 'blur' }],
}

async function fetchConfig() {
  loading.value = true
  try {
    const data = await getSystemConfig('base')
    Object.assign(form, {
      site_name: data.site_name || '',
      service_phone: data.service_phone || '',
      service_email: data.service_email || '',
      icp: data.icp || '',
    })
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    loading.value = false
  }
}

// 当页保存：成功后原地提示，不刷新不跳转
async function handleSave() {
  if (!formRef.value) return
  await formRef.value.validate(async (valid) => {
    if (!valid) return
    saving.value = true
    try {
      await saveSystemConfig('base', { ...form })
      ElMessage.success('保存成功')
    } catch {
      // 错误提示已由 axios 拦截器统一弹出
    } finally {
      saving.value = false
    }
  })
}

onMounted(fetchConfig)
</script>

<template>
  <el-card v-loading="loading" shadow="never">
    <template #header>
      <span>站点基础设置</span>
    </template>

    <el-form
      ref="formRef"
      :model="form"
      :rules="rules"
      label-width="140px"
      label-position="left"
      style="max-width: 620px"
    >
      <el-form-item label="站点名称" prop="site_name">
        <el-input v-model="form.site_name" placeholder="如：鼎极多端" clearable />
      </el-form-item>
      <el-form-item label="客服电话">
        <el-input v-model="form.service_phone" placeholder="如：400-000-0000" clearable />
      </el-form-item>
      <el-form-item label="客服邮箱">
        <el-input v-model="form.service_email" placeholder="如：service@example.com" clearable />
      </el-form-item>
      <el-form-item label="ICP 备案号">
        <el-input v-model="form.icp" placeholder="如：粤ICP备00000000号" clearable />
      </el-form-item>
      <el-form-item>
        <el-button type="primary" :loading="saving" @click="handleSave">保存</el-button>
      </el-form-item>
    </el-form>
  </el-card>
</template>
