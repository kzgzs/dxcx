<script setup lang="ts">
import { ref } from 'vue'
import PosterEditor from '../../components/PosterEditor.vue'

// 商品海报：按终端分别配置独立模板（对齐参考系统 ShopPoster，八个终端）
type Platform = 'mp' | 'wx' | 'alipay' | 'baidu' | 'toutiao' | 'qq' | 'h5' | 'app'

const PLATFORMS: { value: Platform; label: string }[] = [
  { value: 'mp', label: '微信公众号' },
  { value: 'wx', label: '微信小程序' },
  { value: 'alipay', label: '支付宝小程序' },
  { value: 'baidu', label: '百度小程序' },
  { value: 'toutiao', label: '抖音小程序' },
  { value: 'qq', label: 'QQ小程序' },
  { value: 'h5', label: '手机H5' },
  { value: 'app', label: '手机APP' },
]

// 默认首个终端：微信公众号
const platform = ref<Platform>('mp')
</script>

<template>
  <div class="page-card">
    <el-alert
      type="info"
      :closable="false"
      show-icon
      title="商品海报用于会员/客户端分享商品时生成推广图。各终端模板相互独立，请逐个终端设计并保存；公众号、手机H5、手机APP 仅含网页二维码，五个小程序另含对应小程序码。"
      style="margin-bottom: 12px"
    />

    <el-radio-group v-model="platform" class="platform-tabs">
      <el-radio-button v-for="p in PLATFORMS" :key="p.value" :value="p.value">{{ p.label }}</el-radio-button>
    </el-radio-group>

    <!-- 切换终端时重建编辑器，各自加载独立模板 -->
    <PosterEditor :type="'product'" :platform="platform" :key="platform" show-product-image />
  </div>
</template>

<style scoped>
.platform-tabs {
  display: flex;
  flex-wrap: wrap;
  margin-bottom: 16px;
}
</style>
