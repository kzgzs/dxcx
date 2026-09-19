<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { getShortvideoSysset, saveShortvideoSysset } from '../../api/shortvideo'

const loading = ref(false)
const saving = ref(false)

const form = reactive({
  list_type: 0,
  can_upload: 0,
  upload_maxsize: 0,
  upload_maxduration: 0,
  upload_check: 1,
  show_business_video: 0,
})

async function fetchSet() {
  loading.value = true
  try {
    const data = await getShortvideoSysset()
    Object.assign(form, {
      list_type: data.list_type ?? 0,
      can_upload: data.can_upload ?? 0,
      upload_maxsize: data.upload_maxsize ?? 0,
      upload_maxduration: data.upload_maxduration ?? 0,
      upload_check: data.upload_check ?? 1,
      show_business_video: data.show_business_video ?? 0,
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
    await saveShortvideoSysset({ ...form })
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
        <span>短视频系统设置</span>
      </template>
      <el-form label-width="150px" label-position="left" style="max-width: 620px">
        <el-form-item label="列表样式">
          <el-radio-group v-model="form.list_type">
            <el-radio :value="0">单列</el-radio>
            <el-radio :value="1">双列</el-radio>
          </el-radio-group>
          <span class="form-tip">C 端视频列表展示样式</span>
        </el-form-item>
        <el-form-item label="会员投稿">
          <el-switch v-model="form.can_upload" :active-value="1" :inactive-value="0" />
          <span class="form-tip">开启后会员可在 C 端上传短视频</span>
        </el-form-item>
        <el-form-item label="投稿大小上限">
          <el-input-number v-model="form.upload_maxsize" :min="0" :precision="0" :step="1" />
          <span class="form-tip">单位：M，0 为不限制</span>
        </el-form-item>
        <el-form-item label="投稿时长上限">
          <el-input-number v-model="form.upload_maxduration" :min="0" :precision="0" :step="1" />
          <span class="form-tip">单位：秒，0 为不限制</span>
        </el-form-item>
        <el-form-item label="投稿审核">
          <el-switch v-model="form.upload_check" :active-value="1" :inactive-value="0" />
          <span class="form-tip">开启后会员投稿需审核通过才展示</span>
        </el-form-item>
        <el-form-item label="显示商户视频">
          <el-switch v-model="form.show_business_video" :active-value="1" :inactive-value="0" />
          <span class="form-tip">关闭后 C 端仅显示平台（自营）视频</span>
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
