<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { getQueueSet, saveQueueSet } from '../../api/queue'
import ImgIconPicker from '../../components/ImgIconPicker.vue'
import { useAuthStore } from '../../stores/auth'

const authStore = useAuthStore()

const loading = ref(false)
const saving = ref(false)

const form = reactive({
  screen_title: '',
  screen_pic: '',
  screen_desc: '',
  start_hours: '00:00',
  end_hours: '00:00',
  status: 1,
})

// 保存按钮交互：当页保存，不刷新不跳转
async function handleSave() {
  saving.value = true
  try {
    await saveQueueSet({
      screen_title: form.screen_title,
      screen_pic: form.screen_pic,
      screen_desc: form.screen_desc,
      start_hours: form.start_hours,
      end_hours: form.end_hours,
      status: form.status,
    })
    ElMessage.success('保存成功')
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    saving.value = false
  }
}

async function fetchSet() {
  loading.value = true
  try {
    const data = await getQueueSet()
    form.screen_title = data.screen_title || ''
    form.screen_pic = data.screen_pic || ''
    form.screen_desc = data.screen_desc || ''
    form.start_hours = data.start_hours || '00:00'
    form.end_hours = data.end_hours || '00:00'
    form.status = data.status
  } catch {
    // 错误提示已由拦截器弹出
  } finally {
    loading.value = false
  }
}

// ============ 图片选择（任务294：ImgIconPicker，默认素材库） ============
const imgPickerVisible = ref(false)

function openImgPicker() {
  imgPickerVisible.value = true
}

function onImgPick(urls: string[]) {
  if (urls.length) form.screen_pic = urls[0]
}

// ============ 大屏地址 ============
const tenantAid = computed(() => authStore.userinfo?.aid || 0)

const screenUrl = computed(() => {
  const base = `${location.origin}${location.pathname}`
  return `${base}#/queue/screen?aid=${tenantAid.value}`
})

async function copyScreenUrl() {
  try {
    await navigator.clipboard.writeText(screenUrl.value)
    ElMessage.success('大屏地址已复制')
  } catch {
    ElMessage.warning('复制失败，请手动复制地址')
  }
}

function openScreen() {
  window.open(screenUrl.value, '_blank')
}

onMounted(fetchSet)
</script>

<template>
  <el-card v-loading="loading" shadow="never">
    <template #header>
      <div class="card-header">
        <span class="card-title">排队设置</span>
        <el-button type="primary" :loading="saving" @click="handleSave">保存</el-button>
      </div>
    </template>

    <el-form :model="form" label-width="110px" class="set-form">
      <el-form-item label="排队开关">
        <el-radio-group v-model="form.status">
          <el-radio :value="1">开启</el-radio>
          <el-radio :value="0">关闭</el-radio>
        </el-radio-group>
        <span class="form-tip">关闭后 C 端不可取号</span>
      </el-form-item>
      <el-form-item label="可取号时段">
        <el-time-select v-model="form.start_hours" start="00:00" end="23:30" step="00:30" placeholder="开始" style="width: 120px" />
        <span class="form-tip">至</span>
        <el-time-select v-model="form.end_hours" start="00:00" end="23:30" step="00:30" placeholder="结束" style="width: 120px" />
        <span class="form-tip">开始=结束时为全天可取号；支持跨天时段</span>
      </el-form-item>
      <el-form-item label="大屏标题">
        <el-input v-model="form.screen_title" placeholder="如：欢迎光临，请留意叫号" maxlength="50" style="width: 360px" />
      </el-form-item>
      <el-form-item label="大屏背景图">
        <div class="img-pick-row">
          <el-image v-if="form.screen_pic" :src="form.screen_pic" class="img-pick-thumb" fit="cover" />
          <el-button size="small" @click="openImgPicker">选择图片</el-button>
          <el-button v-if="form.screen_pic" size="small" link type="danger" @click="form.screen_pic = ''">删除</el-button>
        </div>
      </el-form-item>
      <el-form-item label="大屏底部文字">
        <el-input v-model="form.screen_desc" placeholder="如：过号请重新取号" maxlength="100" style="width: 360px" />
      </el-form-item>
      <el-form-item label="叫号语音">
        <span class="form-tip">采用浏览器 TTS 语音播报 + 文字展示，无需额外语音服务配置</span>
      </el-form-item>
      <el-form-item label="排队大屏">
        <el-input :model-value="screenUrl" readonly style="width: 420px" />
        <el-button style="margin-left: 8px" @click="copyScreenUrl">复制</el-button>
        <el-button type="primary" plain @click="openScreen">打开大屏</el-button>
        <div class="form-tip block">独立页面无需登录，可投放到店内显示屏，自动轮询最新叫号与等待列表</div>
      </el-form-item>
    </el-form>
  </el-card>

  <!-- 选图弹窗（任务294） -->
  <ImgIconPicker
    v-model:visible="imgPickerVisible"
    default-tab="library"
    @pick="(urls: string[]) => onImgPick(urls)"
  />
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

.form-tip.block {
  margin-left: 0;
  margin-top: 4px;
  line-height: 1.6;
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
