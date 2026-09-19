<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { getKanjiaSysset, saveKanjiaSysset } from '../../api/kanjia'
import ImgIconPicker from '../../components/ImgIconPicker.vue'

const loading = ref(false)
const saving = ref(false)

const form = reactive({
  pic: '',
  autoshdays: 7,
  showjd: 1,
  mastpay: 0,
})

async function fetchSet() {
  loading.value = true
  try {
    const data = await getKanjiaSysset()
    Object.assign(form, {
      pic: data.pic ?? '',
      autoshdays: data.autoshdays ?? 7,
      showjd: data.showjd ?? 1,
      mastpay: data.mastpay ?? 0,
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
    await saveKanjiaSysset({ ...form })
    ElMessage.success('保存成功')
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    saving.value = false
  }
}

// ============ 图片选择（任务294：ImgIconPicker，默认素材库） ============
const imgPickerVisible = ref(false)

function openImgPicker() {
  imgPickerVisible.value = true
}

function onImgPick(urls: string[]) {
  if (urls.length) form.pic = urls[0]
}

onMounted(fetchSet)
</script>

<template>
  <div v-loading="loading">
    <el-card shadow="never">
      <template #header>
        <span>砍价设置</span>
      </template>
      <el-form label-width="130px" label-position="left" style="max-width: 560px">
        <el-form-item label="活动横幅">
          <div class="img-pick-row">
            <el-image v-if="form.pic" :src="form.pic" class="img-pick-thumb" fit="cover" />
            <el-button size="small" @click="openImgPicker">选择图片</el-button>
            <el-button v-if="form.pic" size="small" link type="danger" @click="form.pic = ''">删除</el-button>
          </div>
          <span class="form-tip">砍价活动页顶部横幅图</span>
        </el-form-item>
        <el-form-item label="自动收货天数">
          <el-input-number v-model="form.autoshdays" :min="1" :max="90" :precision="0" :step="1" />
          <span class="form-tip">发货后 N 天自动确认收货</span>
        </el-form-item>
        <el-form-item label="砍价进度显示">
          <el-switch v-model="form.showjd" :active-value="1" :inactive-value="0" />
          <span class="form-tip">开启后活动页展示砍价进度条</span>
        </el-form-item>
        <el-form-item label="必须支付">
          <el-switch v-model="form.mastpay" :active-value="1" :inactive-value="0" />
          <span class="form-tip">开启后砍到底价必须完成支付才算成交</span>
        </el-form-item>
        <el-form-item>
          <el-button type="primary" :loading="saving" @click="handleSave">保存设置</el-button>
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
