<script setup lang="ts">
import FenhongSettle from './FenhongSettle.vue'

/**
 * 区域分红（任务183）：等级 areafenhong（1省 2市 3区县）+ areafenhongbl 比例，
 * 按订单收货区域 area2 逐级匹配（省 / 省+市 / 省+市+区），areafenhongmaxnum 单数封顶
 */
</script>

<template>
  <FenhongSettle
    type="areafenhong"
    title="区域分红"
    desc="规则：区域代理等级（areafenhong 1省/2市/3区县）按订单收货区域逐级匹配（省→市→区县），跟随等级会员按等级比例均分，独立代理按各自比例；结算后生成待发放记录，审核后按载体入账。"
  />
</template>
