<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { ElMessage } from 'element-plus'
import {
  collectProduct,
  getTaobaoConfig,
  picLocal,
  saveTaobaoKey,
} from '../../api/shopconfig'

// ============ apikey 配置 ============
const apikey = ref('')
const savingKey = ref(false)

async function fetchConfig() {
  try {
    const data = await getTaobaoConfig()
    apikey.value = data.apikey || ''
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  }
}

async function handleSaveKey() {
  savingKey.value = true
  try {
    await saveTaobaoKey(apikey.value.trim())
    ElMessage.success('apikey 保存成功')
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    savingKey.value = false
  }
}

// ============ 采集 ============
const collectType = ref(0)
const collectUrl = ref('')
const collecting = ref(false)
const collectResult = ref('')

const SOURCE_TIPS: Record<number, string> = {
  0: '淘宝商品链接，如 https://item.taobao.com/item.htm?id=xxx',
  1: '天猫商品链接，如 https://detail.tmall.com/item.htm?id=xxx',
  2: '京东商品链接，如 https://item.jd.com/xxx.html',
  3: '1688 商品链接，如 https://detail.1688.com/offer/xxx.html',
}

async function handleCollect() {
  if (!collectUrl.value.trim()) {
    ElMessage.warning('请填写商品链接')
    return
  }
  collecting.value = true
  collectResult.value = ''
  try {
    const data = await collectProduct({
      type: collectType.value,
      url: collectUrl.value.trim(),
      apikey: apikey.value.trim() || undefined,
    })
    collectResult.value = `采集完成：商品ID ${data.proid}，待本地化图片 ${data.pic_count} 张（商品已入库未上架）`
    ElMessage.success('采集成功')
  } catch {
    // 无 key / 外部接口失败等由拦截器弹出友好提示
  } finally {
    collecting.value = false
  }
}

// ============ 图片本地化（循环分批直至完成） ============
const picRunning = ref(false)
const picProgress = ref('')

async function handlePicLocal() {
  picRunning.value = true
  picProgress.value = '开始本地化…'
  let totalProcessed = 0
  let totalFailed = 0
  try {
    for (let i = 0; i < 200; i++) {
      const data = await picLocal(10)
      totalProcessed += data.processed
      totalFailed += data.failed
      picProgress.value = `已处理 ${totalProcessed} 张，失败 ${totalFailed} 张…`
      if (data.done) {
        break
      }
    }
    picProgress.value = `图片本地化完成：成功 ${totalProcessed} 张，失败 ${totalFailed} 张`
    ElMessage.success('图片本地化完成')
  } catch {
    picProgress.value = `本地化中断：已处理 ${totalProcessed} 张`
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    picRunning.value = false
  }
}

onMounted(fetchConfig)
</script>

<template>
  <div>
    <!-- apikey 配置 -->
    <el-card shadow="never" class="block-card">
      <template #header><span class="card-title">99API 采集密钥</span></template>
      <el-form label-width="110px" style="max-width: 620px">
        <el-form-item label="apikey">
          <el-input v-model="apikey" placeholder="请输入 99API 的 apikey" clearable />
        </el-form-item>
        <el-form-item>
          <el-button type="primary" :loading="savingKey" @click="handleSaveKey">保存 apikey</el-button>
        </el-form-item>
        <el-alert type="info" :closable="false" show-icon
          title="采集依赖 99API（api03.6bqb.com），需自行注册并申请 apikey 后方可使用；未配置时采集会提示先填写密钥。" />
      </el-form>
    </el-card>

    <!-- 商品链接采集 -->
    <el-card shadow="never" class="block-card">
      <template #header><span class="card-title">商品链接采集</span></template>
      <el-form label-width="110px" style="max-width: 760px">
        <el-form-item label="采集来源">
          <el-radio-group v-model="collectType">
            <el-radio :value="0">淘宝</el-radio>
            <el-radio :value="1">天猫</el-radio>
            <el-radio :value="2">京东</el-radio>
            <el-radio :value="3">1688</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item label="商品链接">
          <el-input v-model="collectUrl" :placeholder="SOURCE_TIPS[collectType]" clearable />
        </el-form-item>
        <el-form-item>
          <el-button type="primary" :loading="collecting" @click="handleCollect">开始采集</el-button>
        </el-form-item>
        <el-form-item v-if="collectResult">
          <el-alert type="success" :closable="false" show-icon :title="collectResult" />
        </el-form-item>
      </el-form>
    </el-card>

    <!-- 图片本地化 -->
    <el-card shadow="never" class="block-card">
      <template #header><span class="card-title">远程图片本地化</span></template>
      <el-form label-width="110px" style="max-width: 760px">
        <el-form-item label="说明">
          <span class="muted">采集入库的商品图片为远程地址，点击下方按钮分批下载至本地并回写商品引用。</span>
        </el-form-item>
        <el-form-item>
          <el-button type="primary" :loading="picRunning" @click="handlePicLocal">开始本地化</el-button>
        </el-form-item>
        <el-form-item v-if="picProgress">
          <el-alert type="info" :closable="false" :title="picProgress" />
        </el-form-item>
      </el-form>
    </el-card>
  </div>
</template>

<style scoped>
.block-card {
  margin-bottom: 16px;
}

.card-title {
  font-weight: 600;
}

.muted {
  color: #909399;
  font-size: 13px;
}
</style>
