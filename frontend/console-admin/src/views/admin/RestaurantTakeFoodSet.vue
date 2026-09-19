<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { getTakeFoodSet, saveTakeFoodSet } from '../../api/takefood'
import ImgIconPicker from '../../components/ImgIconPicker.vue'

const loading = ref(false)
const submitting = ref(false)

const form = reactive({
  banner: [] as string[],
  screen_pic: '',
  banner_interval: 3,
})

async function fetchSet() {
  loading.value = true
  try {
    const data = await getTakeFoodSet()
    form.banner = Array.isArray(data.banner) ? data.banner : []
    form.screen_pic = data.screen_pic || ''
    form.banner_interval = Number(data.banner_interval) || 3
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    loading.value = false
  }
}

async function handleSave() {
  if (form.banner_interval < 1 || form.banner_interval > 60) {
    ElMessage.warning('轮播间隔需在 1-60 秒之间')
    return
  }
  submitting.value = true
  try {
    await saveTakeFoodSet({
      banner: form.banner,
      screen_pic: form.screen_pic,
      banner_interval: form.banner_interval,
    })
    ElMessage.success('保存成功')
    fetchSet()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    submitting.value = false
  }
}

// ============ 选图（ImgIconPicker 统一选图） ============
const imgPickerVisible = ref(false)
const imgPickerMulti = ref(false)
let imgPickerSetter: ((urls: string[]) => void) | null = null
function openImgPicker(setter: (urls: string[]) => void, multi = false) {
  imgPickerMulti.value = multi
  imgPickerSetter = setter
  imgPickerVisible.value = true
}
function onImgPick(urls: string[]) {
  imgPickerSetter?.(urls)
  imgPickerSetter = null
}

function removeBanner(index: number) {
  form.banner.splice(index, 1)
}

onMounted(fetchSet)
</script>

<template>
  <el-card v-loading="loading" shadow="never">
    <template #header>
      <div class="card-header">
        <span class="card-title">取餐设置</span>
        <el-button type="primary" :loading="submitting" @click="handleSave">保存</el-button>
      </div>
    </template>

    <el-form :model="form" label-width="130px" label-position="right" class="compact-form">
      <div class="section-title">取餐大屏</div>
      <el-form-item label="轮播图">
        <div class="pic-line">
          <div v-for="(url, idx) in form.banner" :key="idx" class="pic-item">
            <el-image :src="url" class="pic-thumb" fit="cover" />
            <el-button link type="danger" size="small" @click="removeBanner(idx)">删除</el-button>
          </div>
          <el-button
            v-if="form.banner.length < 10"
            size="small"
            @click="
              openImgPicker((urls) => {
                urls.forEach((u) => {
                  if (form.banner.length < 10 && !form.banner.includes(u)) form.banner.push(u)
                })
              }, true)
            "
          >
            选择图片
          </el-button>
        </div>
        <span class="form-tip">取餐大屏顶部轮播展示，最多 10 张</span>
      </el-form-item>
      <el-form-item label="大屏背景图">
        <div class="pic-line">
          <el-image v-if="form.screen_pic" :src="form.screen_pic" class="pic-thumb" fit="cover" />
          <el-button size="small" @click="openImgPicker((urls) => {
            if (urls[0]) form.screen_pic = urls[0]
          })">{{ form.screen_pic ? '更换' : '选择图片' }}</el-button>
        </div>
      </el-form-item>
      <el-form-item label="轮播间隔（秒）">
        <el-input-number v-model="form.banner_interval" :min="1" :max="60" />
        <span class="form-tip">轮播图切换间隔，1-60 秒</span>
      </el-form-item>

      <el-form-item>
        <el-button type="primary" :loading="submitting" @click="handleSave">保存</el-button>
      </el-form-item>
    </el-form>

    <ImgIconPicker
      v-model:visible="imgPickerVisible"
      :multi="imgPickerMulti"
      default-tab="library"
      @pick="onImgPick"
    />
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

.compact-form :deep(.el-form-item) {
  margin-bottom: 10px;
}

.compact-form :deep(.el-form-item__label) {
  line-height: 32px;
}

.section-title {
  margin: 6px 0 12px;
  padding-left: 8px;
  border-left: 3px solid var(--el-color-primary);
  font-weight: 600;
  font-size: 14px;
}

.form-tip {
  display: block;
  color: #909399;
  font-size: 12px;
}

.pic-line {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 10px;
}

.pic-item {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 2px;
}

.pic-thumb {
  width: 88px;
  height: 88px;
  border: 1px solid var(--el-border-color-lighter);
  border-radius: 4px;
}
</style>
