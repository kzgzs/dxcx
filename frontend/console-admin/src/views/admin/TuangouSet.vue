<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { getTuangouSet, saveTuangouSet } from '../../api/tuangou'
import ImgIconPicker from '../../components/ImgIconPicker.vue'

const loading = ref(false)
const saving = ref(false)

const form = reactive({
  pics: '',
  autoshdays: 7,
  comment: 1,
  comment_check: 1,
  showjd: 1,
})

async function fetchSet() {
  loading.value = true
  try {
    const data = await getTuangouSet()
    Object.assign(form, {
      pics: data.pics ?? '',
      autoshdays: data.autoshdays ?? 7,
      comment: data.comment ?? 1,
      comment_check: data.comment_check ?? 1,
      showjd: data.showjd ?? 1,
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
    await saveTuangouSet({ ...form })
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
  if (urls.length) form.pics = urls[0]
}

onMounted(fetchSet)
</script>

<template>
  <div v-loading="loading">
    <el-card shadow="never">
      <template #header>
        <span>团购设置</span>
      </template>
      <el-form label-width="130px" label-position="left" style="max-width: 560px">
        <el-form-item label="自动收货天数">
          <el-input-number v-model="form.autoshdays" :min="1" :max="90" :precision="0" :step="1" />
          <span class="form-tip">发货后 N 天自动确认收货</span>
        </el-form-item>
        <el-form-item label="评价开关">
          <el-switch v-model="form.comment" :active-value="1" :inactive-value="0" />
          <span class="form-tip">关闭后会员不可评价团购订单</span>
        </el-form-item>
        <el-form-item label="评价审核">
          <el-switch v-model="form.comment_check" :active-value="1" :inactive-value="0" />
          <span class="form-tip">开启后评价需审核通过才展示</span>
        </el-form-item>
        <el-form-item label="显示购买进度">
          <el-switch v-model="form.showjd" :active-value="1" :inactive-value="0" />
          <span class="form-tip">商品页展示已购人数与阶梯价达成进度</span>
        </el-form-item>
        <el-form-item label="活动说明图">
          <div class="img-pick-row">
            <el-image v-if="form.pics" :src="form.pics" class="img-pick-thumb" fit="cover" />
            <el-button size="small" @click="openImgPicker">{{ form.pics ? '更换' : '选择图片' }}</el-button>
            <el-button v-if="form.pics" size="small" link type="danger" @click="form.pics = ''">删除</el-button>
          </div>
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
