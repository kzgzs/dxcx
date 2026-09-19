<script setup lang="ts">
import FenhongSettle from './FenhongSettle.vue'

/**
 * 股东分红（任务183）：等级 fenhong>0 的股东按等级比例均分分红池，
 * 受 fenhong_max_money 累计封顶与 fenhong_num 人数限制约束
 */
</script>

<template>
  <FenhongSettle
    type="fenhong"
    title="股东分红"
    desc="规则：股东等级（fenhong>0）会员按等级比例分享分红池并均分给当期有效股东，封顶（fenhong_max_money）与份数（fenhong_num）约束在发放时裁剪；结算后生成待发放记录，审核后按载体入账。"
  />
</template>
