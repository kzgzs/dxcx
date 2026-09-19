<script setup lang="ts">
import { ref } from 'vue'
import { ElMessage } from 'element-plus'
import { adminClearCache } from '../../api/admin'

// 租户后台「系统-清理缓存」：对接后端 /admin/system/clearCache
// 点击当页执行 + 原地 ElMessage 提示，不刷新不跳转
const clearing = ref(false)
const lastResult = ref<string[]>([])

async function handleClear() {
  if (clearing.value) return
  clearing.value = true
  try {
    const data = await adminClearCache()
    lastResult.value = data.results || []
    ElMessage.success('缓存清理完成')
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    clearing.value = false
  }
}
</script>

<template>
  <el-card shadow="never">
    <template #header>
      <span>清理缓存</span>
    </template>

    <div class="clear-box">
      <div class="clear-icon">🧹</div>
      <div class="clear-title">一键清理系统缓存</div>
      <div class="clear-desc">
        清理范围：Redis 缓存数据 + runtime 缓存文件。不删除业务数据，不清理日志。
        遇到页面数据不更新、配置改了没生效时，可以先来这里清一下。
      </div>
      <el-button type="primary" size="large" :loading="clearing" @click="handleClear">
        {{ clearing ? '正在清理…' : '立即清理缓存' }}
      </el-button>

      <div v-if="lastResult.length" class="clear-result">
        <div class="result-title">清理明细：</div>
        <div v-for="(line, idx) in lastResult" :key="idx" class="result-line">
          {{ line }}
        </div>
      </div>
    </div>
  </el-card>
</template>

<style scoped>
.clear-box {
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: 40px 20px;
  gap: 14px;
}

.clear-icon {
  font-size: 44px;
}

.clear-title {
  font-size: 17px;
  font-weight: 600;
  color: #303133;
}

.clear-desc {
  max-width: 520px;
  text-align: center;
  color: #909399;
  font-size: 13px;
  line-height: 1.8;
}

.clear-result {
  margin-top: 10px;
  padding: 12px 16px;
  background-color: #f0f9eb;
  border-radius: 6px;
  min-width: 320px;
}

.result-title {
  color: #67c23a;
  font-size: 13px;
  font-weight: 600;
  margin-bottom: 6px;
}

.result-line {
  color: #606266;
  font-size: 13px;
  line-height: 1.8;
}
</style>
