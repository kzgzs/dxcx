<script setup lang="ts">
// 文章系统设置（对齐点大「扩展→文章管理→系统设置」截图）
// 仅截图所列字段：列表样式 / 列表标题字体大小 / 转发送积分；打赏相关字段后端保留、不在此编辑
import { onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { getArticleSet, saveArticleSet } from '../../api/article'

const loading = ref(false)
const saving = ref(false)
const form = reactive({
  listtype: 1,
  title_size: 0,
  share_score: 0,
  share_score_max_perday: 0,
})

async function load() {
  loading.value = true
  try {
    const res = await getArticleSet()
    form.listtype = Number(res.listtype || 1)
    form.title_size = Number(res.title_size || 0)
    form.share_score = Number(res.share_score || 0)
    form.share_score_max_perday = Number(res.share_score_max_perday || 0)
  } finally {
    loading.value = false
  }
}

async function handleSave() {
  saving.value = true
  try {
    await saveArticleSet({
      listtype: form.listtype,
      title_size: form.title_size,
      share_score: form.share_score,
      share_score_max_perday: form.share_score_max_perday,
    })
    ElMessage.success('保存成功')
    await load()
  } finally {
    saving.value = false
  }
}

onMounted(load)
</script>

<template>
  <div class="article-set" v-loading="loading">
    <div class="set-card">
      <div class="set-title">
        <span class="set-title-icon">⚙</span>文章系统设置
      </div>
      <el-form label-width="120px" class="set-form">
        <el-form-item label="列表样式:">
          <el-radio-group v-model="form.listtype">
            <el-radio :value="1">横排显示</el-radio>
            <el-radio :value="2">双排显示</el-radio>
            <el-radio :value="3">瀑布流</el-radio>
            <el-radio :value="4">大图显示</el-radio>
            <el-radio :value="5">三排显示</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item label="列表标题字体大小:">
          <el-input-number v-model="form.title_size" :min="0" :controls="false" style="width: 90px" />
          <span class="unit">px</span>
          <span class="tip">大于0时生效</span>
        </el-form-item>
        <el-form-item label="转发送积分:">
          <span class="inline-label">转发一次</span>
          <el-input-number v-model="form.share_score" :min="0" :controls="false" style="width: 90px" />
          <span class="unit">分</span>
          <span class="inline-label inline-gap">每日上限</span>
          <el-input-number v-model="form.share_score_max_perday" :min="0" :controls="false" style="width: 90px" />
          <span class="unit">分</span>
        </el-form-item>
        <el-form-item>
          <el-button type="success" :loading="saving" @click="handleSave">提 交</el-button>
        </el-form-item>
      </el-form>
    </div>
  </div>
</template>

<style scoped>
.article-set {
  padding: 3px;
}
.set-card {
  background: #fff;
  border: 1px solid #e6e6e6;
  padding: 16px 20px;
}
.set-title {
  font-size: 15px;
  font-weight: 600;
  color: #303133;
  margin-bottom: 18px;
}
.set-title-icon {
  margin-right: 4px;
}
.set-form :deep(.el-form-item) {
  margin-bottom: 16px;
}
.inline-label {
  color: #606266;
  font-size: 13px;
  margin-right: 6px;
}
.inline-gap {
  margin-left: 10px;
}
.unit {
  margin: 0 6px 0 4px;
  color: #606266;
  font-size: 13px;
}
.tip {
  margin-left: 8px;
  color: #909399;
  font-size: 12px;
}
</style>
