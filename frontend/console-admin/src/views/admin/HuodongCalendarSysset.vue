<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { getHuodongCalendarSet, saveHuodongCalendarSet } from '../../api/huodongCalendar'
import ImgIconPicker from '../../components/ImgIconPicker.vue'

const loading = ref(false)
const submitting = ref(false)

const form = reactive({
  status: 0,
  title: '活动日历',
  bgimg: '',
  notice: '',
  desc: '',
})

async function fetchSet() {
  loading.value = true
  try {
    const data = await getHuodongCalendarSet()
    Object.assign(form, {
      status: Number(data.status) || 0,
      title: data.title || '活动日历',
      bgimg: data.bgimg || '',
      notice: data.notice || '',
      desc: data.desc || '',
    })
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    loading.value = false
  }
}

async function handleSave() {
  submitting.value = true
  try {
    await saveHuodongCalendarSet({ ...form })
    ElMessage.success('保存成功')
    fetchSet()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    submitting.value = false
  }
}

// ============ 图片选择（任务294：ImgIconPicker，默认素材库） ============
const imgPickerVisible = ref(false)

function openImgPicker() {
  imgPickerVisible.value = true
}

function onImgPick(urls: string[]) {
  if (urls.length) form.bgimg = urls[0]
}

onMounted(fetchSet)
</script>

<template>
  <div v-loading="loading">
    <el-card shadow="never">
      <template #header>
        <div class="card-header">
          <span>活动日历设置</span>
          <el-tag type="info" size="small">客户端活动日历页读取本配置</el-tag>
        </div>
      </template>

      <el-form label-width="120px" label-position="left" style="max-width: 640px">
        <el-form-item label="启用开关">
          <el-switch v-model="form.status" :active-value="1" :inactive-value="0" />
          <span class="form-tip">关闭后客户端不展示活动日历入口</span>
        </el-form-item>
        <el-form-item label="页面标题">
          <el-input v-model="form.title" placeholder="活动日历" />
        </el-form-item>
        <el-form-item label="顶部背景图">
          <div class="img-pick-row">
            <el-image v-if="form.bgimg" :src="form.bgimg" class="img-pick-thumb" fit="cover" />
            <el-button size="small" @click="openImgPicker">选择图片</el-button>
            <el-button v-if="form.bgimg" size="small" link type="danger" @click="form.bgimg = ''">删除</el-button>
          </div>
        </el-form-item>
        <el-form-item label="顶部公告">
          <el-input
            v-model="form.notice"
            type="textarea"
            :rows="2"
            maxlength="255"
            show-word-limit
            placeholder="展示在日历顶部的活动公告"
          />
        </el-form-item>
        <el-form-item label="底部说明">
          <el-input
            v-model="form.desc"
            type="textarea"
            :rows="3"
            maxlength="255"
            show-word-limit
            placeholder="展示在日历底部的活动说明"
          />
        </el-form-item>
        <el-form-item>
          <el-button type="primary" :loading="submitting" @click="handleSave">保存</el-button>
        </el-form-item>
      </el-form>
    </el-card>

    <!-- 选图弹窗（任务294） -->
    <ImgIconPicker
      v-model:visible="imgPickerVisible"
      default-tab="library"
      @pick="(urls: string[]) => onImgPick(urls)"
    />
  </div>
</template>

<style scoped>
.card-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
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
