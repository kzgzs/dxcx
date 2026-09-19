<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { getWxembedded } from '../../api/wxext'
import type { WxEmbeddedItem } from '../../api/wxext'

const loading = ref(false)
const loaded = ref(false)
const list = ref<WxEmbeddedItem[]>([])
const isMock = ref(false)
// 非代授权等不可用场景的友好提示（后端 error msg）
const denyMsg = ref('')

async function fetchInit() {
  loading.value = true
  denyMsg.value = ''
  try {
    const data = await getWxembedded()
    list.value = data.list || []
    isMock.value = !!data.is_mock
    loaded.value = true
  } catch (e) {
    loaded.value = false
    list.value = []
    denyMsg.value = e instanceof Error && e.message ? e.message : '半屏小程序暂不可用'
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  fetchInit()
})
</script>

<template>
  <div>
    <!-- 配置说明 -->
    <el-card shadow="never" class="doc-card">
      <template #header>
        <span class="doc-title">半屏小程序 · 配置说明</span>
      </template>
      <el-descriptions :column="1" border size="small">
        <el-descriptions-item label="什么是半屏小程序">
          在小程序内以半屏浮层方式打开另一个小程序，不离开当前页面，适合支付、授权、第三方服务等场景。
        </el-descriptions-item>
        <el-descriptions-item label="使用方式">
          客户端通过 wx.openEmbeddedMiniProgram 打开已申请通过的目标小程序，目标 AppID 需先完成半屏授权。
        </el-descriptions-item>
        <el-descriptions-item label="开通前提">
          仅平台代授权（开放平台授权）的小程序支持本页接口管理；手动接入的小程序请前往
          <b>微信公众平台 - 设置 - 第三方设置 - 半屏小程序管理</b> 中手动申请。
        </el-descriptions-item>
        <el-descriptions-item label="数量限制">
          微信侧限制：最多可申请 10 个半屏小程序，同一时间可打开的半屏小程序数量以微信最新规则为准。
        </el-descriptions-item>
      </el-descriptions>
    </el-card>

    <!-- 可用列表 -->
    <el-card shadow="never">
      <template #header>
        <div class="list-head">
          <span>可用的半屏小程序</span>
          <el-button size="small" :loading="loading" @click="fetchInit">刷新 / 初始化</el-button>
        </div>
      </template>

      <!-- 不可用提示（如：手动接入非代授权） -->
      <el-alert
        v-if="denyMsg"
        :title="denyMsg"
        type="warning"
        :closable="false"
        show-icon
        class="deny-alert"
      />

      <template v-else-if="loaded">
        <el-table v-loading="loading" :data="list" stripe border size="small">
          <el-table-column prop="appid" label="AppID" min-width="200" show-overflow-tooltip />
          <el-table-column label="名称" min-width="160">
            <template #default="{ row }">{{ row.nickname || '-' }}</template>
          </el-table-column>
          <el-table-column label="状态" width="100">
            <template #default="{ row }">
              <el-tag v-if="row.status === 1 || row.status === undefined" type="success" size="small">可用</el-tag>
              <el-tag v-else type="info" size="small">待生效</el-tag>
            </template>
          </el-table-column>
        </el-table>
        <div class="list-tip">
          共 {{ list.length }} 个
          <el-tag v-if="isMock" type="info" size="small" class="mock-tag">MOCK 演示数据</el-tag>
          ；如需新增授权目标小程序，请在微信侧完成半屏申请后刷新本页。
        </div>
      </template>
    </el-card>
  </div>
</template>

<style scoped>
.doc-card {
  margin-bottom: 12px;
}

.doc-title {
  font-weight: 600;
}

.list-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.deny-alert {
  margin-bottom: 8px;
}

.list-tip {
  margin-top: 10px;
  color: #909399;
  font-size: 12px;
  display: flex;
  align-items: center;
  gap: 6px;
}
</style>
