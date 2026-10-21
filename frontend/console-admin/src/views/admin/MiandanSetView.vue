<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { getMiandanSet, saveMiandanSet } from '../../api/ewaybill'

defineEmits<{ (e: 'close'): void }>()

const loading = ref(false)
const saving = ref(false)
const form = reactive({
  status: 1,
  query_status: 1,
  print_type: 0,
})

async function load() {
  loading.value = true
  try {
    const d = await getMiandanSet()
    form.status = d.status ?? 1
    form.query_status = d.query_status ?? 1
    form.print_type = d.print_type ?? 0
  } catch {
    /* 拦截器已提示 */
  } finally {
    loading.value = false
  }
}

async function save() {
  saving.value = true
  try {
    await saveMiandanSet({ ...form })
    ElMessage.success('操作成功')
  } catch {
    /* 拦截器已提示 */
  } finally {
    saving.value = false
  }
}

onMounted(load)
</script>

<template>
  <div class="subpage">
    <div class="subpage-head">
      <span class="subpage-title">物流助手设置</span>
      <span class="subpage-close" @click="$emit('close')">✕</span>
    </div>
    <div v-loading="loading" class="subpage-body">
      <el-form label-width="210px" style="max-width: 720px" @submit.prevent>
        <el-form-item label="状态：">
          <el-radio-group v-model="form.status">
            <el-radio :value="1">开启</el-radio>
            <el-radio :value="0">关闭</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item label="优先使用物流助手查询物流：">
          <el-radio-group v-model="form.query_status">
            <el-radio :value="1">开启</el-radio>
            <el-radio :value="0">关闭</el-radio>
          </el-radio-group>
          <div class="tip">开启后优先使用微信物流助手查询物流轨迹（免费），查询不到时再使用系统物流查询</div>
        </el-form-item>
        <el-form-item label="一联单/二联单：">
          <el-radio-group v-model="form.print_type">
            <el-radio :value="1">一联单</el-radio>
            <el-radio :value="0">二联单</el-radio>
          </el-radio-group>
          <div class="tip">打印面单类型，顺丰不支持一联单（系统会自动读取二联单数据）</div>
        </el-form-item>
        <el-form-item>
          <el-button type="primary" :loading="saving" @click="save">提 交</el-button>
        </el-form-item>
      </el-form>
    </div>
  </div>
</template>

<style scoped>
.subpage-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 3px 0;
}
.subpage-title {
  font-size: 16px;
  font-weight: 600;
}
.subpage-close {
  cursor: pointer;
  font-size: 20px;
  font-weight: bold;
  color: #606266;
  padding: 0 6px;
}
.subpage-close:hover {
  color: #f56c6c;
}
.subpage-body {
  margin-top: 3px;
}
.tip {
  color: #909399;
  font-size: 12px;
  line-height: 1.5;
}
</style>
