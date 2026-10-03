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
})

async function fetchSet() {
  loading.value = true
  try {
    const data = await getKanjiaSysset()
    Object.assign(form, {
      pic: data.pic ?? '',
      autoshdays: data.autoshdays ?? 7,
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
    await saveKanjiaSysset({ ...form })
    ElMessage.success('保存成功')
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    saving.value = false
  }
}

// ============ 图片选择 ============
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
  <div v-loading="loading" class="set-wrap">
    <el-card shadow="never">
      <template #header>
        <span class="set-title">系统设置</span>
      </template>
      <el-form label-width="110px" label-position="left" class="set-form">
        <el-form-item label="banner图：">
          <el-image v-if="form.pic" :src="form.pic" class="banner-thumb" fit="cover" @click="openImgPicker" />
          <el-button v-else size="small" @click="openImgPicker">上传图片</el-button>
          <span class="form-tip">建议尺寸：750×430像素</span>
          <div v-if="form.pic" class="banner-actions">
            <el-button size="small" @click="openImgPicker">更换</el-button>
            <el-button size="small" link type="danger" @click="form.pic = ''">删除</el-button>
          </div>
        </el-form-item>
        <el-form-item label="自动收货天数：">
          <el-input-number v-model="form.autoshdays" :min="1" :max="90" :precision="0" :step="1" :controls="false" style="width: 160px" />
          <span class="form-tip">天&nbsp;&nbsp;发货后多少天用户未确认收货自动完成收货（最小值为1）</span>
        </el-form-item>
        <el-form-item label="进店及咨询：">
          <el-radio-group v-model="form.showjd">
            <el-radio :value="1">开启</el-radio>
            <el-radio :value="0">关闭</el-radio>
          </el-radio-group>
          <span class="form-tip">商品详情页是否显示进店及咨询</span>
        </el-form-item>
        <el-form-item label=" ">
          <el-button type="success" :loading="saving" @click="handleSave">提交</el-button>
        </el-form-item>
      </el-form>
    </el-card>

    <ImgIconPicker
      v-model:visible="imgPickerVisible"
      default-tab="library"
      @pick="(urls: string[]) => onImgPick(urls)"
    />
  </div>
</template>

<style scoped>
.set-wrap { margin: 3px; }
.set-title { font-size: 15px; font-weight: 600; }
.set-form { max-width: 720px; padding-left: 10px; }
.form-tip {
  margin-left: 10px;
  color: #909399;
  font-size: 12px;
}
.banner-thumb {
  width: 150px;
  height: 86px;
  border-radius: 4px;
  border: 1px solid #ebeef5;
  cursor: pointer;
}
.banner-actions { margin-top: 6px; }
</style>
