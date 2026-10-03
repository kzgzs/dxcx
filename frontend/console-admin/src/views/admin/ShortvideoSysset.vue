<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { getShortvideoSysset, saveShortvideoSysset } from '../../api/shortvideo'

const loading = ref(false)
const saving = ref(false)

const form = reactive({
  list_type: 0,
  can_upload: 0,
  upload_maxsize: 6000,
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
      upload_maxsize: data.upload_maxsize ?? 6000,
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
      <div class="page-title">⚙ 短视频设置</div>
      <el-form label-width="130px" label-position="left" style="max-width: 640px">
        <el-form-item label="视频列表样式">
          <el-radio-group v-model="form.list_type">
            <el-radio :value="0">样式一</el-radio>
            <el-radio :value="1">样式二</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item label="多商户视频">
          <el-radio-group v-model="form.show_business_video">
            <el-radio :value="1">显示</el-radio>
            <el-radio :value="0">不显示</el-radio>
          </el-radio-group>
          <span class="form-tip">平台视频链接，是否显示多商户视频</span>
        </el-form-item>
        <el-form-item label="用户上传">
          <el-radio-group v-model="form.can_upload">
            <el-radio :value="1">开启</el-radio>
            <el-radio :value="0">关闭</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item label="上传审核">
          <el-radio-group v-model="form.upload_check">
            <el-radio :value="1">开启</el-radio>
            <el-radio :value="0">关闭</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item label="上传最大限制">
          <el-input-number v-model="form.upload_maxsize" :min="0" :controls="false" style="width: 160px" />
          <span class="form-tip">kb&nbsp;&nbsp;0表示不限制</span>
        </el-form-item>
        <el-form-item>
          <el-button type="success" :loading="saving" @click="handleSave">提 交</el-button>
        </el-form-item>
      </el-form>
    </el-card>
  </div>
</template>

<style scoped>
.page-title {
  font-size: 15px;
  font-weight: 600;
  color: #303133;
  margin-bottom: 16px;
}
.form-tip {
  margin-left: 10px;
  color: #909090;
  font-size: 12px;
}
</style>
