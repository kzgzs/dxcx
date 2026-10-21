<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { getShdSet, saveShdSet } from '../../api/order'
import type { ShdSetData } from '../../api/types'

const loading = ref(false)
const saving = ref(false)

const form = reactive<ShdSetData>({
  shipping_pagetitle: '',
  shipping_pagenum: 0,
  shipping_linenum: 0,
})

async function loadData() {
  loading.value = true
  try {
    const result = await getShdSet()
    form.shipping_pagetitle = result.shipping_pagetitle || ''
    form.shipping_pagenum = Number(result.shipping_pagenum) || 0
    form.shipping_linenum = Number(result.shipping_linenum) || 0
  } catch {
    // 错误提示由 axios 拦截器统一处理
  } finally {
    loading.value = false
  }
}

async function handleSave() {
  saving.value = true
  try {
    await saveShdSet({
      shipping_pagetitle: form.shipping_pagetitle.trim(),
      shipping_pagenum: Math.max(0, Math.floor(Number(form.shipping_pagenum) || 0)),
      shipping_linenum: Math.max(0, Math.floor(Number(form.shipping_linenum) || 0)),
    })
    ElMessage.success('保存成功')
    await loadData()
  } catch {
    // 错误提示由 axios 拦截器统一处理
  } finally {
    saving.value = false
  }
}

onMounted(() => {
  loadData()
})
</script>

<template>
  <div class="shd-set-page" v-loading="loading">
    <div class="page-card">
      <div class="card-title">送货单设置</div>
      <el-form :model="form" label-width="120px" class="set-form">
        <el-form-item label="打印标题：">
          <el-input
            v-model="form.shipping_pagetitle"
            style="width: 240px"
          />
          <el-button link type="primary" style="margin-left:8px">示例</el-button>
        </el-form-item>
        <el-form-item label="每页行数：">
          <el-input v-model="form.shipping_pagenum" style="width: 240px" />
          <span class="form-tip">控制每页显示和打印行数（根据纸张大小修改），0为不限制</span>
        </el-form-item>
        <el-form-item label="品名及规格行数：">
          <el-input v-model="form.shipping_linenum" style="width: 240px" />
          <span class="form-tip">控制"品名及规格"文字显示几行，多出的用省略号表示，0为不限制</span>
        </el-form-item>
        <el-form-item>
          <el-button type="primary" :loading="saving" @click="handleSave">提 交</el-button>
        </el-form-item>
      </el-form>
    </div>
  </div>
</template>

<style scoped>
.shd-set-page {
  padding: 12px;
}
.page-card {
  background: #fff;
  border-radius: 4px;
  padding: 16px 20px;
}
.card-title {
  font-size: 15px;
  font-weight: 600;
  color: #303133;
  padding-bottom: 12px;
  margin-bottom: 16px;
  border-bottom: 1px solid #ebeef5;
}
.set-form {
  max-width: 860px;
}
.form-tip {
  margin-left: 10px;
  color: #909399;
  font-size: 12px;
}
.page-note {
  margin-top: 8px;
  padding: 12px 14px;
  background: #f7f8fa;
  border-radius: 4px;
  color: #909399;
  font-size: 12px;
  line-height: 1.9;
  max-width: 860px;
}
.page-note p {
  margin: 0;
}
</style>
