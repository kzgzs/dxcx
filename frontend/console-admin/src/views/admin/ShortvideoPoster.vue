<script setup lang="ts">
import { ref } from 'vue'
import PosterEditor from '../../components/PosterEditor.vue'

// 短视频分享海报：八个终端 Tab（点大截图顺序），每个终端独立模板
// PosterEditor 内部按 type=shortvideo + platform 读写 admin_set_poster
type PosterPlatform = 'mp' | 'wx' | 'alipay' | 'baidu' | 'toutiao' | 'qq' | 'h5' | 'app'

const platforms: Array<{ value: PosterPlatform; label: string }> = [
  { value: 'mp', label: '微信公众号' },
  { value: 'wx', label: '微信小程序' },
  { value: 'alipay', label: '支付宝小程序' },
  { value: 'baidu', label: '百度小程序' },
  { value: 'toutiao', label: '抖音小程序' },
  { value: 'qq', label: 'QQ小程序' },
  { value: 'h5', label: '手机H5' },
  { value: 'app', label: '手机APP' },
]

const platform = ref<PosterPlatform>('mp')
</script>

<template>
  <div>
    <el-card shadow="never">
      <div class="page-head">
        <span class="list-title">⚙ 分享海报设置</span>
      </div>
      <el-tabs v-model="platform" class="sv-poster-tabs">
        <el-tab-pane v-for="p in platforms" :key="p.value" :label="p.label" :name="p.value" />
      </el-tabs>
      <PosterEditor :type="'shortvideo'" :platform="platform" :key="platform" show-all-qr />
    </el-card>
  </div>
</template>

<style scoped>
.page-head {
  margin-bottom: 8px;
}
.list-title {
  font-size: 15px;
  font-weight: 600;
  color: #303133;
}
.sv-poster-tabs :deep(.el-tabs__nav-wrap::after) {
  height: 1px;
}
</style>
