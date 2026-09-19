<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { Search, Setting } from '@element-plus/icons-vue'
import {
  clearCacheByItems,
  getCacheGroups,
  getCacheKeys,
  queryCacheValue,
} from '../api/system'
import type { CacheGroupOption, CacheQueryResult, ClearItemKey } from '../api/system'

// 清理缓存页（对照参考系统清理缓存页截图）：
// 区块一 复选框勾选清理内容提交；区块二 测试获取缓存数据（分类/参数联动查询，当页展示）
const checked = ref<ClearItemKey[]>([])
const submitting = ref(false)

// 区块二：设置分类 / 设置参数 联动
const groups = ref<CacheGroupOption[]>([])
const keys = ref<string[]>([])
const queryGroup = ref('')
const queryKey = ref('')
const keysLoading = ref(false)
const querying = ref(false)
const queryResult = ref<CacheQueryResult | null>(null)

// 提交清理：当页 ElMessage 提示每项清理明细，不跳转
async function handleSubmit() {
  if (checked.value.length === 0) {
    ElMessage.warning('请选择要清理的内容')
    return
  }
  submitting.value = true
  try {
    const data = await clearCacheByItems(checked.value)
    const lines = (data.details || [])
      .map((d) => `<div>【${d.name}】${d.message}</div>`)
      .join('')
    ElMessage({
      type: 'success',
      dangerouslyUseHTMLString: true,
      message: `<div>清理完成：</div>${lines}`,
      duration: 5000,
    })
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    submitting.value = false
  }
}

// 加载设置分类下拉
async function fetchGroups() {
  try {
    const data = await getCacheGroups()
    groups.value = data.groups || []
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  }
}

// 切换分类：联动加载该分类下参数，并重置参数与查询结果
async function handleGroupChange(group: string) {
  queryKey.value = ''
  queryResult.value = null
  keys.value = []
  if (!group) return

  keysLoading.value = true
  try {
    const data = await getCacheKeys(group)
    keys.value = data.keys || []
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    keysLoading.value = false
  }
}

function handleKeyChange() {
  queryResult.value = null
}

// 查询：当页展示缓存值
async function handleQuery() {
  if (!queryGroup.value) {
    ElMessage.warning('请选择设置分类')
    return
  }
  if (!queryKey.value) {
    ElMessage.warning('请选择设置参数')
    return
  }
  querying.value = true
  try {
    queryResult.value = await queryCacheValue(queryGroup.value, queryKey.value)
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    querying.value = false
  }
}

function groupLabel(group: string): string {
  const g = groups.value.find((it) => it.group === group)
  return g ? `${g.group}（${g.name}）` : group
}

function displayValue(v: string | null): string {
  if (v === null) return '（无）'
  return v === '' ? '（空字符串）' : v
}

onMounted(fetchGroups)
</script>

<template>
  <div class="clear-cache-page">
    <!-- 区块一：清理缓存 -->
    <section class="cc-card">
      <header class="cc-card-header">
        <el-icon class="cc-header-icon"><Setting /></el-icon>
        <span>清理缓存</span>
      </header>
      <div class="cc-card-body">
        <div class="cc-row">
          <label class="cc-label">清理内容：</label>
          <div class="cc-checks">
            <el-checkbox-group v-model="checked">
              <el-checkbox value="global">全局缓存</el-checkbox>
              <el-checkbox value="console_system">控制台-系统设置</el-checkbox>
              <el-checkbox value="tenant_system">所有用户-系统-系统设置</el-checkbox>
              <el-checkbox value="tenant_shop">所有用户-商城-系统设置</el-checkbox>
              <el-checkbox value="tenant_merchant">所有用户-商户-系统设置</el-checkbox>
            </el-checkbox-group>
          </div>
        </div>
        <div class="cc-row">
          <label class="cc-label"></label>
          <div class="cc-warn">
            注意：勾选【全局缓存】将清除系统的全部缓存数据，runtime/cache 目录也会被一并清空
          </div>
        </div>
        <div class="cc-row">
          <label class="cc-label"></label>
          <button
            type="button"
            class="cc-btn cc-btn-submit"
            :disabled="submitting"
            @click="handleSubmit"
          >
            {{ submitting ? '提交中…' : '提 交' }}
          </button>
        </div>
      </div>
    </section>

    <!-- 区块二：测试获取缓存数据 -->
    <section class="cc-card">
      <header class="cc-card-header">
        <el-icon class="cc-header-icon"><Search /></el-icon>
        <span>测试获取缓存数据</span>
      </header>
      <div class="cc-card-body">
        <div class="cc-row">
          <label class="cc-label">设置分类：</label>
          <el-select
            v-model="queryGroup"
            placeholder="请选择设置分类"
            class="cc-select"
            :loading="keysLoading"
            @change="handleGroupChange"
          >
            <el-option
              v-for="g in groups"
              :key="g.group"
              :value="g.group"
              :label="`${g.name}（${g.group}）`"
            />
          </el-select>
        </div>
        <div class="cc-row">
          <label class="cc-label">设置参数：</label>
          <el-select
            v-model="queryKey"
            :placeholder="queryGroup ? '请选择设置参数' : '请先选择分类'"
            :disabled="!queryGroup"
            :loading="keysLoading"
            class="cc-select"
            filterable
            @change="handleKeyChange"
          >
            <el-option v-for="k in keys" :key="k" :value="k" :label="k" />
          </el-select>
        </div>
        <div class="cc-row">
          <label class="cc-label"></label>
          <button
            type="button"
            class="cc-btn cc-btn-query"
            :disabled="querying"
            @click="handleQuery"
          >
            {{ querying ? '查询中…' : '查 询' }}
          </button>
        </div>
        <div v-if="queryResult" class="cc-row">
          <label class="cc-label"></label>
          <div class="cc-result">
            <div>查询结果：</div>
            <div>设置分类：{{ groupLabel(queryResult.group) }}</div>
            <div>设置参数：{{ queryResult.key }}</div>
            <div>
              缓存命中：{{ queryResult.cache_hit ? '是（读取缓存值）' : '否（缓存已清空，回源库中值）' }}
            </div>
            <div>缓存值：{{ displayValue(queryResult.cache_value) }}</div>
            <div>库中值：{{ displayValue(queryResult.db_value) }}</div>
            <div>当前值：{{ displayValue(queryResult.value) }}</div>
          </div>
        </div>
      </div>
    </section>
  </div>
</template>

<style scoped>
.clear-cache-page {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.cc-card {
  background: #fff;
  border-radius: 2px;
  box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
}

.cc-card-header {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 12px 16px;
  border-bottom: 1px solid #eee;
  font-size: 14px;
  font-weight: 700;
  color: #333;
}

.cc-header-icon {
  font-size: 16px;
  color: #333;
}

.cc-card-body {
  padding: 24px 16px 28px;
}

.cc-row {
  display: flex;
  align-items: flex-start;
  margin-bottom: 20px;
}

.cc-row:last-child {
  margin-bottom: 0;
}

.cc-label {
  flex: 0 0 90px;
  padding-right: 10px;
  text-align: right;
  font-size: 14px;
  color: #333;
  line-height: 32px;
}

.cc-checks {
  max-width: 620px;
  padding-top: 5px;
}

.cc-checks :deep(.el-checkbox) {
  margin-right: 24px;
  height: 26px;
}

.cc-warn {
  line-height: 32px;
  font-size: 14px;
  color: #ff0000;
}

.cc-btn {
  border: none;
  border-radius: 2px;
  padding: 0 20px;
  height: 36px;
  font-size: 14px;
  color: #fff;
  cursor: pointer;
}

.cc-btn:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.cc-btn-submit {
  background: #009688;
}

.cc-btn-submit:hover:not(:disabled) {
  background: #00a293;
}

.cc-btn-query {
  background: #1e9fff;
}

.cc-btn-query:hover:not(:disabled) {
  background: #3db0ff;
}

.cc-select {
  width: 300px;
}

.cc-result {
  padding: 10px 14px;
  background: #f8f8f8;
  border: 1px solid #eee;
  border-radius: 2px;
  font-size: 13px;
  line-height: 1.9;
  color: #333;
  font-family: 'Courier New', monospace;
  white-space: normal;
  word-break: break-all;
}
</style>
