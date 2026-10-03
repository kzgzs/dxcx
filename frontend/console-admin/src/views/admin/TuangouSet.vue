<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { getTuangouSet, saveTuangouSet } from '../../api/tuangou'
import ImgIconPicker from '../../components/ImgIconPicker.vue'

const loading = ref(false)
const saving = ref(false)

const form = reactive({
  pics: [] as string[],
  autoshdays: 7,
  comment: 1,
  comment_check: 1,
  showjd: 1,
  vipdiscount: 1,
})

async function fetchSet() {
  loading.value = true
  try {
    const d = await getTuangouSet()
    form.pics = d.pics ? String(d.pics).split(',').filter((s) => s !== '') : []
    form.autoshdays = d.autoshdays ?? 7
    form.comment = d.comment ?? 1
    form.comment_check = d.comment_check ?? 1
    form.showjd = d.showjd ?? 1
    form.vipdiscount = d.vipdiscount ?? 1
  } catch { /* 拦截器 */ } finally { loading.value = false }
}

async function submit() {
  saving.value = true
  try {
    await saveTuangouSet({
      pics: form.pics.join(','),
      autoshdays: Number(form.autoshdays) || 1,
      comment: form.comment,
      comment_check: form.comment_check,
      showjd: form.showjd,
      vipdiscount: form.vipdiscount,
    })
    ElMessage.success('保存成功')
  } catch { /* 拦截器 */ } finally { saving.value = false }
}

const imgPickerVisible = ref(false)
function openImgPicker() { imgPickerVisible.value = true }
function onPick(urls: string[]) {
  form.pics = urls
}

onMounted(fetchSet)
</script>

<template>
  <el-card v-loading="loading" shadow="never">
    <el-form label-width="120px" class="set-form">
      <el-form-item label="轮播图">
        <el-button @click="openImgPicker">批量上传</el-button>
        <span class="hint">建议尺寸：740×320像素</span>
        <div class="thumb-row">
          <el-image v-for="(u, i) in form.pics" :key="i" :src="u" fit="cover" class="thumb" />
        </div>
      </el-form-item>
      <el-form-item label="自动收货天数">
        <el-input-number v-model="form.autoshdays" :min="1" :precision="0" />
        <span class="unit">天</span>
        <span class="hint">发货后多少天用户未确认收货自动完成收货（最小值为1）</span>
      </el-form-item>
      <el-form-item label="商品评价">
        <el-radio-group v-model="form.comment"><el-radio :value="1">开启</el-radio><el-radio :value="0">关闭</el-radio></el-radio-group>
      </el-form-item>
      <el-form-item label="评价审核">
        <el-radio-group v-model="form.comment_check"><el-radio :value="1">开启</el-radio><el-radio :value="0">关闭</el-radio></el-radio-group>
        <span class="hint">关闭后用户的评价将直接显示,不需要后台审核</span>
      </el-form-item>
      <el-form-item label="进店及咨询">
        <el-radio-group v-model="form.showjd"><el-radio :value="1">开启</el-radio><el-radio :value="0">关闭</el-radio></el-radio-group>
        <span class="hint">商品详情页是否显示进店及咨询</span>
      </el-form-item>
      <el-form-item label="会员等级折扣">
        <el-radio-group v-model="form.vipdiscount"><el-radio :value="1">开启</el-radio><el-radio :value="0">关闭</el-radio></el-radio-group>
        <span class="hint">是否开启会员等级折扣</span>
      </el-form-item>
      <el-form-item>
        <el-button type="success" :loading="saving" @click="submit">提交</el-button>
      </el-form-item>
    </el-form>
    <ImgIconPicker v-model:visible="imgPickerVisible" :multi="true" default-tab="library" @pick="onPick" />
  </el-card>
</template>

<style scoped>
.set-form { max-width: 640px; }
.hint { color: #909399; font-size: 12px; margin-left: 10px; }
.unit { margin-left: 6px; }
.thumb-row { display: flex; gap: 8px; margin-top: 8px; flex-wrap: wrap; }
.thumb { width: 120px; height: 52px; border-radius: 4px; border: 1px solid #e4e7ed; }
</style>
