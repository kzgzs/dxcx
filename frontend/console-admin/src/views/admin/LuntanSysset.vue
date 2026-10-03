<script setup lang="ts">
// 用户论坛 - 系统设置（对齐点大 Luntan/sysset）
import { onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { getLuntanSysset, saveLuntanSysset } from '../../api/luntan'
import ImgIconPicker from '../../components/ImgIconPicker.vue'
import LinkPicker from '../../components/LinkPicker.vue'

const loading = ref(false)
const saving = ref(false)

const form = reactive({
  status: 1 as number,
  title: '',
  pic: '',
  picurl: '',
  sendtjAll: true,
  sendLevels: [] as number[],
  sendcheck: 1 as number,
  pingluncheck: 0 as number,
  cansave: 1 as number,
})
const memberLevels = ref<{ id: number; name: string }[]>([])

const bannerPickerVisible = ref(false)

async function fetchSysset() {
  loading.value = true
  try {
    const res = await getLuntanSysset()
    form.status = res.status || 0
    form.title = res.title || ''
    form.pic = res.pic || ''
    form.picurl = res.picurl || ''
    form.sendcheck = res.sendcheck || 0
    form.pingluncheck = res.pingluncheck || 0
    form.cansave = res.cansave || 0
    memberLevels.value = res.member_levels || []
    const tj = res.sendtj || []
    form.sendtjAll = tj.includes('-1')
    form.sendLevels = memberLevels.value
      .filter((l) => tj.includes(String(l.id)))
      .map((l) => l.id)
  } finally {
    loading.value = false
  }
}

function toggleLevel(id: number) {
  const i = form.sendLevels.indexOf(id)
  if (i >= 0) form.sendLevels.splice(i, 1)
  else form.sendLevels.push(id)
}

async function handleSave() {
  saving.value = true
  try {
    const sendtj: string[] = []
    if (form.sendtjAll) sendtj.push('-1')
    form.sendLevels.forEach((id) => sendtj.push(String(id)))
    if (sendtj.length === 0) sendtj.push('-1')
    await saveLuntanSysset({
      title: form.title,
      pic: form.pic,
      picurl: form.picurl,
      sendtj,
      sendcheck: form.sendcheck,
      pingluncheck: form.pingluncheck,
      cansave: form.cansave,
      status: form.status,
    })
    ElMessage.success('设置成功')
    fetchSysset()
  } finally {
    saving.value = false
  }
}

onMounted(fetchSysset)
</script>

<template>
  <div v-loading="loading">
    <div class="page-title">⚙ 系统设置</div>

    <el-card shadow="never">
      <el-form label-width="110px" class="set-form">
        <el-form-item label="论坛状态">
          <el-radio-group v-model="form.status">
            <el-radio :value="1">开启</el-radio>
            <el-radio :value="0">关闭</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item label="论坛标题">
          <el-input v-model="form.title" placeholder="请输入论坛标题" style="width: 240px" />
        </el-form-item>
        <el-form-item label="banner图">
          <el-button @click="bannerPickerVisible = true">上传图片</el-button>
          <span class="field-tip">建议尺寸：690×260像素</span>
          <div class="banner-preview">
            <el-image v-if="form.pic" :src="form.pic" fit="cover" class="banner-box" />
            <div v-else class="banner-box banner-empty">暂无图片</div>
          </div>
        </el-form-item>
        <el-form-item label="banner图链接">
          <LinkPicker v-model="form.picurl" pick-text="选择链接" placeholder="banner图跳转链接（可空）" style="width: 360px" />
        </el-form-item>
        <el-form-item label="*发帖条件">
          <div class="chip-row">
            <span class="chip" :class="{ on: form.sendtjAll }" @click="form.sendtjAll = !form.sendtjAll">所有人</span>
            <span
              v-for="l in memberLevels"
              :key="l.id"
              class="chip"
              :class="{ on: form.sendLevels.includes(l.id) }"
              @click="toggleLevel(l.id)"
            >{{ l.name }}</span>
          </div>
        </el-form-item>
        <el-form-item label="发帖审核">
          <el-radio-group v-model="form.sendcheck">
            <el-radio :value="1">开启</el-radio>
            <el-radio :value="0">关闭</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item label="评论审核">
          <el-radio-group v-model="form.pingluncheck">
            <el-radio :value="1">开启</el-radio>
            <el-radio :value="0">关闭</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item label="保存文案">
          <el-radio-group v-model="form.cansave">
            <el-radio :value="1">开启</el-radio>
            <el-radio :value="0">关闭</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item>
          <el-button type="primary" :loading="saving" @click="handleSave">提 交</el-button>
        </el-form-item>
      </el-form>
    </el-card>

    <ImgIconPicker v-model:visible="bannerPickerVisible" @pick="(urls) => { if (urls[0]) form.pic = urls[0] }" />
  </div>
</template>

<style scoped>
.page-title {
  font-size: 15px;
  font-weight: 600;
  color: #303133;
  padding: 2px 4px 8px;
}
.set-form {
  max-width: 720px;
}
.field-tip {
  color: #909399;
  font-size: 12px;
  margin-left: 8px;
}
.banner-preview {
  margin-top: 8px;
}
.banner-box {
  width: 240px;
  height: 90px;
  border-radius: 4px;
}
.banner-empty {
  background: #f5f7fa;
  border: 1px solid #ebeef5;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #c0c4cc;
  font-size: 12px;
}
.chip-row {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
}
.chip {
  padding: 5px 14px;
  border: 1px solid #dcdfe6;
  border-radius: 4px;
  color: #606266;
  cursor: pointer;
  font-size: 13px;
  background: #f5f7fa;
  user-select: none;
}
.chip.on {
  background: #67c23a;
  border-color: #67c23a;
  color: #fff;
}
</style>
