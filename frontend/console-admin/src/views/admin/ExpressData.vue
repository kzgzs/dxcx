<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { getExpressData, saveExpressData } from '../../api/tenantSet'
import type { ExpressDataMap } from '../../api/types'

const loading = ref(false)
const saving = ref(false)

// 内置候选字典（公司名 => 标准编码）
const dict = ref<ExpressDataMap>({})
// 右侧已启用的公司名集合（穿梭框 v-model）
const selected = ref<string[]>([])

// 穿梭框候选项：公司名 + 编码展示
interface TransferItem {
  key: string
  label: string
  disabled?: boolean
}

const transferData = computed<TransferItem[]>(() =>
  Object.entries(dict.value).map(([name, code]) => ({
    key: name,
    label: code ? `${name}（${code}）` : name,
  })),
)

async function loadData() {
  loading.value = true
  try {
    const result = await getExpressData()
    dict.value = result.dict || {}
    selected.value = Object.keys(result.data || {})
  } catch {
    // 错误提示由 axios 拦截器统一处理
  } finally {
    loading.value = false
  }
}

async function handleSave() {
  saving.value = true
  try {
    // 编码统一按内置字典回填标准值
    const data: ExpressDataMap = {}
    for (const name of selected.value) {
      data[name] = dict.value[name] ?? ''
    }
    await saveExpressData(data)
    ElMessage.success('保存成功')
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
  <div v-loading="loading">
    <el-card shadow="never">
      <template #header>
        <span class="card-title">快递设置</span>
      </template>

      <p class="page-tip">
        左侧为内置快递公司候选列表，穿梭到右侧即启用；保存后客户端下单发货仅展示已启用的快递公司。
      </p>

      <el-transfer
        v-model="selected"
        :data="transferData"
        :titles="['系统快递', '自定义快递']"
        filterable
        filter-placeholder="关键词搜索"
      />

      <div class="save-row">
        <el-button type="primary" :loading="saving" @click="handleSave">保存</el-button>
        <span class="save-tip">已启用 {{ selected.length }} 家快递公司</span>
      </div>
    </el-card>
  </div>
</template>

<style scoped>
.card-title {
  font-weight: 600;
}

.page-tip {
  margin: 0 0 16px;
  color: #909399;
  font-size: 13px;
}

.save-row {
  display: flex;
  align-items: center;
  margin-top: 18px;
}

.save-tip {
  margin-left: 12px;
  color: #909399;
  font-size: 12px;
}

/* 穿梭框整体靠左，宽度贴合内容 */
:deep(.el-transfer) {
  display: flex;
  align-items: center;
}
</style>
