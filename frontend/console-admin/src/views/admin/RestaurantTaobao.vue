<script setup lang="ts">
/**
 * 餐饮 - 菜品 - 菜品采集（对照参考系统 restaurant_taobao/index）
 * 平台：淘宝/天猫/京东/拼多多/1688，第三方抓取接口以 mock 数据替代，
 * 采集入库后 status=0 未上架，到菜品列表继续编辑上架
 */
import { onMounted, ref } from 'vue'
import { ElMessage } from 'element-plus'
import {
  collectRestaurantTaobao,
  getRestaurantTaobaoConfig,
  saveRestaurantTaobaoKey,
} from '../../api/restaurantExt'

// ============ apikey 配置 ============
const apikey = ref('')
const savingKey = ref(false)

async function fetchConfig() {
  try {
    const data = await getRestaurantTaobaoConfig()
    apikey.value = data.apikey || ''
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  }
}

async function handleSaveKey() {
  if (!apikey.value.trim()) {
    ElMessage.warning('请填写 apikey')
    return
  }
  savingKey.value = true
  try {
    await saveRestaurantTaobaoKey(apikey.value.trim())
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

const PLATFORM_OPTIONS = [
  { value: 0, label: '淘宝' },
  { value: 1, label: '天猫' },
  { value: 2, label: '京东' },
  { value: 3, label: '拼多多' },
  { value: 4, label: '1688' },
]

const SOURCE_TIPS: Record<number, string> = {
  0: '淘宝商品链接或商品ID，如 https://item.taobao.com/item.htm?id=xxx',
  1: '天猫商品链接或商品ID，如 https://detail.tmall.com/item.htm?id=xxx',
  2: '京东商品链接或商品ID，如 https://item.jd.com/xxx.html',
  3: '拼多多商品链接或商品ID',
  4: '1688 商品链接或商品ID，如 https://detail.1688.com/offer/xxx.html',
}

async function handleCollect() {
  if (!collectUrl.value.trim()) {
    ElMessage.warning('请填写商品链接或商品ID')
    return
  }
  collecting.value = true
  collectResult.value = ''
  try {
    const data = await collectRestaurantTaobao({
      type: collectType.value,
      taobaourl: collectUrl.value.trim(),
      apikey: apikey.value.trim() || undefined,
    })
    collectResult.value = `采集完成：菜品ID ${data.proid}（已入库未上架，请到「菜品列表」编辑上架）`
    ElMessage.success('采集成功')
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    collecting.value = false
  }
}

onMounted(fetchConfig)
</script>

<template>
  <el-card shadow="never">
    <template #header>
      <div class="rt-header">
        <span class="rt-title">菜品采集</span>
        <span class="rt-tip">支持淘宝/天猫/京东/拼多多/1688 商品链接一键采集为菜品</span>
      </div>
    </template>

    <el-form label-width="110px" style="max-width: 760px">
      <el-divider content-position="left">采集接口配置</el-divider>
      <el-form-item label="apikey：">
        <el-input v-model="apikey" placeholder="第三方采集接口 apikey" clearable />
        <div class="rt-field-tip">用于调用第三方商品抓取接口，未填写时按演示模式采集</div>
      </el-form-item>
      <el-form-item>
        <el-button type="primary" :loading="savingKey" @click="handleSaveKey">保存 apikey</el-button>
      </el-form-item>

      <el-divider content-position="left">采集菜品</el-divider>
      <el-form-item label="采集平台：">
        <el-radio-group v-model="collectType">
          <el-radio v-for="opt in PLATFORM_OPTIONS" :key="opt.value" :value="opt.value">{{ opt.label }}</el-radio>
        </el-radio-group>
      </el-form-item>
      <el-form-item label="商品链接：">
        <el-input
          v-model="collectUrl"
          type="textarea"
          :rows="2"
          :placeholder="SOURCE_TIPS[collectType]"
        />
      </el-form-item>
      <el-form-item>
        <el-button type="primary" :loading="collecting" @click="handleCollect">开始采集</el-button>
      </el-form-item>
      <el-form-item v-if="collectResult">
        <el-alert :title="collectResult" type="success" :closable="false" show-icon />
      </el-form-item>
    </el-form>
  </el-card>
</template>

<style scoped>
.rt-header {
  display: flex;
  align-items: baseline;
  gap: 10px;
}

.rt-title {
  font-size: 15px;
  font-weight: 600;
  color: #303133;
}

.rt-tip {
  font-size: 12px;
  color: #909399;
}

.rt-field-tip {
  font-size: 12px;
  color: #909399;
  line-height: 1.6;
}
</style>
