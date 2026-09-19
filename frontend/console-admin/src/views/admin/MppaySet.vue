<script setup lang="ts">
import WxpaySetForm from './WxpaySetForm.vue'
</script>

<template>
  <WxpaySetForm
    platform="mp"
    page-title="公众号支付设置"
    page-desc="配置公众号 JSAPI 微信支付：支持普通模式（直连商户）、服务商模式与随行付，含 APIv3 密钥与签名验签配置。"
  />
</template>
