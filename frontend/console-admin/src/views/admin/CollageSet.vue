<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { getCollageSet, saveCollageSet } from '../../api/collage'
import ImgIconPicker from '../../components/ImgIconPicker.vue'

const loading = ref(false)
const saving = ref(false)

const form = reactive({
  teamnum: 3,
  teamhour: 24,
  leadermoney: 0,
  autoshdays: 7,
  comment_check: 1,
  timeset: '',
  team_refund: 0,
  pics: '',
})

async function fetchSet() {
  loading.value = true
  try {
    const data = await getCollageSet()
    Object.assign(form, {
      teamnum: data.teamnum ?? 3,
      teamhour: data.teamhour ?? 24,
      leadermoney: Number(data.leadermoney ?? 0),
      autoshdays: data.autoshdays ?? 7,
      comment_check: data.comment_check ?? 1,
      timeset: data.timeset ?? '',
      team_refund: data.team_refund ?? 0,
      pics: data.pics ?? '',
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
    await saveCollageSet({ ...form })
    ElMessage.success('保存成功')
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    saving.value = false
  }
}

// ============ 选图（活动说明图，ImgIconPicker 统一选图，任务293） ============
const imgPickerVisible = ref(false)
let imgPickerSetter: ((url: string) => void) | null = null
function openImgPicker(setter: (url: string) => void) {
  imgPickerSetter = setter
  imgPickerVisible.value = true
}
function onImgPick(urls: string[]) {
  if (urls[0]) imgPickerSetter?.(urls[0])
  imgPickerSetter = null
}

onMounted(fetchSet)
</script>

<template>
  <div v-loading="loading">
    <el-card shadow="never">
      <template #header>
        <span>拼团设置</span>
      </template>
      <el-form label-width="130px" label-position="left" style="max-width: 560px">
        <el-form-item label="默认成团人数">
          <el-input-number v-model="form.teamnum" :min="2" :max="100" :precision="0" :step="1" />
          <span class="form-tip">2-100 人，商品可单独覆盖</span>
        </el-form-item>
        <el-form-item label="默认成团时限">
          <el-input-number v-model="form.teamhour" :min="1" :max="720" :precision="0" :step="1" />
          <span class="form-tip">小时，超时未成团自动解散并退款</span>
        </el-form-item>
        <el-form-item label="默认团长优惠">
          <el-input-number
            v-model="form.leadermoney"
            :min="0"
            :precision="2"
            :step="1"
          />
          <span class="form-tip">元，发团团长额外优惠金额</span>
        </el-form-item>
        <el-form-item label="自动收货天数">
          <el-input-number v-model="form.autoshdays" :min="1" :max="90" :precision="0" :step="1" />
          <span class="form-tip">发货后 N 天自动确认收货</span>
        </el-form-item>
        <el-form-item label="拼团场次">
          <el-input v-model="form.timeset" placeholder="如：10:00,20:00，留空不限场次" />
        </el-form-item>
        <el-form-item label="评价审核">
          <el-switch
            v-model="form.comment_check"
            :active-value="1"
            :inactive-value="0"
          />
          <span class="form-tip">开启后拼团评价需审核通过才展示</span>
        </el-form-item>
        <el-form-item label="团长退款">
          <el-radio-group v-model="form.team_refund">
            <el-radio :value="0">保留团（仅退团长）</el-radio>
            <el-radio :value="1">解散整个团</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item label="活动说明图">
          <div style="display: flex; align-items: center; gap: 12px">
            <el-image v-if="form.pics" :src="form.pics" style="width: 100px; height: 100px" fit="cover" />
            <el-button size="small" @click="openImgPicker((u) => (form.pics = u))">{{ form.pics ? '更换' : '选择图片' }}</el-button>
            <el-button v-if="form.pics" size="small" link type="danger" @click="form.pics = ''">移除</el-button>
          </div>
        </el-form-item>
        <el-form-item>
          <el-button type="primary" :loading="saving" @click="handleSave">保存设置</el-button>
        </el-form-item>
      </el-form>
    </el-card>

    <ImgIconPicker v-model:visible="imgPickerVisible" default-tab="library" @pick="onImgPick" />
  </div>
</template>

<style scoped>
.form-tip {
  margin-left: 8px;
  color: #909399;
  font-size: 12px;
}
</style>
