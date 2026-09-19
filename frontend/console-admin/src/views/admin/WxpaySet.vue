<script setup lang="ts">
import WxpaySetForm from './WxpaySetForm.vue'
</script>

<template>
  <WxpaySetForm
    platform="wx"
    page-title="小程序支付设置"
    page-desc="配置小程序微信支付：支持普通模式（直连商户）、服务商模式、二级商户模式与随行付，含 APIv3 密钥与签名验签配置。"
  />
</template>
