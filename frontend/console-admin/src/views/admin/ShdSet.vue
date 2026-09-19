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
      <el-form :model="form" label-width="150px" class="set-form">
        <el-form-item label="送货单标题">
          <el-input
            v-model="form.shipping_pagetitle"
            placeholder="打印送货单时显示的标题，留空使用默认标题"
            maxlength="50"
            show-word-limit
            style="width: 420px"
          />
        </el-form-item>
        <el-form-item label="每页打印行数">
          <el-input-number v-model="form.shipping_pagenum" :min="0" :max="200" :step="1" />
          <span class="form-tip">每页打印的明细行数，超出自动分页；0 表示不分页</span>
        </el-form-item>
        <el-form-item label="每页表格固定行数">
          <el-input-number v-model="form.shipping_linenum" :min="0" :max="200" :step="1" />
          <span class="form-tip">不足时用空行补齐，保证每页表格行数一致；0 表示不固定</span>
        </el-form-item>
        <el-form-item>
          <el-button type="primary" :loading="saving" @click="handleSave">保存设置</el-button>
        </el-form-item>
      </el-form>
      <div class="page-note">
        <p>说明：</p>
        <p>1. 在「订单管理」列表勾选订单后点击「打印送货单」，按此处设置生成打印页。</p>
        <p>2. 每页打印行数大于 0 时，明细超出部分自动续页打印，不足按固定行数补空行。</p>
        <p>3. 送货单金额自动转人民币大写，末页含合计与签收栏。</p>
      </div>
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
