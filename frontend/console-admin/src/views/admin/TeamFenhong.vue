<script setup lang="ts">
import FenhongSettle from './FenhongSettle.vue'

/**
 * 团队分红（任务183）：沿会员 path 向上按 teamfenhonglv 代数分配，
 * 每单固定额 teamfenhong_money + 比例 teamfenhongbl，支持仅最近同级/含本人开关
 */
</script>

<template>
  <FenhongSettle
    type="teamfenhong"
    title="团队分红"
    desc="规则：下单会员的上级链（按等级 teamfenhonglv 代数）获得团队分红，每单固定额 + 销售比例；结算后生成待发放记录，审核后按载体入账。"
  />
</template>
