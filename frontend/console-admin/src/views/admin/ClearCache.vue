<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { adminClearCache } from '../../api/admin'

const clearing = ref(false)
const selected = ref<string[]>([])
const cacheCategories = [
  { label: '系统-系统设置', value: 'sysset' },
  { label: '商城-系统设置', value: 'shop_sysset' },
  { label: '商户-系统设置', value: 'business_sysset' },
]

const testCategory = ref('')
const testParam = ref('')
const testResult = ref('')
const categoryOptions = [
  { label: '系统设置', value: 'sysset' },
  { label: '商城设置', value: 'shop' },
  { label: '商户设置', value: 'business' },
]

async function handleClear() {
  if (selected.value.length === 0) {
    ElMessage.warning('请勾选要清理的内容')
    return
  }
  clearing.value = true
  try {
    await adminClearCache({ keys: selected.value })
    ElMessage.success('缓存清理完成')
  } catch {
    // 拦截器统一提示
  } finally {
    clearing.value = false
  }
}

async function handleTestQuery() {
  if (!testCategory.value) {
    ElMessage.warning('请先选择设置分类')
    return
  }
  testResult.value = '缓存数据查询结果'
}

onMounted(() => {})
</script>

<template>
  <div class="clear-page">
    <div class="page-card">
      <div class="card-title">清理缓存</div>
      <el-form label-width="90px">
        <el-form-item label="清理内容：">
          <el-checkbox-group v-model="selected">
            <el-checkbox v-for="c in cacheCategories" :key="c.value" :value="c.value">{{ c.label }}</el-checkbox>
          </el-checkbox-group>
        </el-form-item>
        <el-form-item>
          <el-button type="primary" :loading="clearing" @click="handleClear">提 交</el-button>
        </el-form-item>
      </el-form>
    </div>

    <div class="page-card">
      <div class="card-title">测试获取缓存数据</div>
      <el-form label-width="90px">
        <el-form-item label="设置分类：">
          <el-select v-model="testCategory" placeholder="请选择设置分类" clearable>
            <el-option v-for="o in categoryOptions" :key="o.value" :label="o.label" :value="o.value" />
          </el-select>
        </el-form-item>
        <el-form-item label="设置参数：">
          <el-select v-model="testParam" placeholder="请先选择分类" clearable :disabled="!testCategory">
          </el-select>
        </el-form-item>
        <el-form-item>
          <el-button type="primary" @click="handleTestQuery">查 询</el-button>
        </el-form-item>
      </el-form>
    </div>
  </div>
</template>

<style scoped>
.clear-page {
  padding: 3px;
}
.page-card {
  background: #fff;
  border-radius: 4px;
  padding: 12px;
  margin-bottom: 3px;
}
.card-title {
  font-size: 14px;
  font-weight: 600;
  color: #303133;
  padding-bottom: 10px;
  margin-bottom: 10px;
  border-bottom: 1px solid #ebeef5;
}
</style>
