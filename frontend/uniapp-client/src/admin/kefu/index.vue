<script setup lang="ts">
/**
 * 手机端后台-咨询（底部 Tab 页，对照参考项目 admin/kefu/index）
 * 本期为骨架页：列表占位 + 空态，契约接口就绪后直接替换数据源
 */
import { onLoad } from "@dcloudio/uni-app";
import { ref } from "vue";
import AdminTabbar from "../components/admin-tabbar.vue";
import { guardAdmin } from "../api";

/** 咨询列表（本期无契约接口，恒为空 → 展示空态） */
const list = ref<unknown[]>([]);
const loading = ref(false);

onLoad(() => {
  // 未登录先跳登录页
  guardAdmin();
});
</script>

<template>
  <view class="page">
    <!-- 骨架占位（加载中视觉） -->
    <view v-if="loading" class="skeleton">
      <view v-for="i in 3" :key="i" class="sk-row"></view>
    </view>

    <!-- 空态：本期无数据/接口未就绪均展示，不白屏 -->
    <view v-if="list.length === 0 && !loading" class="empty">
      <text class="empty-icon">💬</text>
      <text class="empty-text">暂无咨询消息</text>
      <text class="empty-sub">会员发起咨询后将在此展示</text>
    </view>

    <!-- 底部占位 + 自绘 Tab -->
    <view class="tabbar-holder"></view>
    <admin-tabbar current="kefu" />
  </view>
</template>

<style scoped>
.page {
  min-height: 100vh;
  background: #f8f8f8;
}
.skeleton {
  padding: 20rpx 24rpx;
}
.sk-row {
  height: 120rpx;
  border-radius: 16rpx;
  background: #eeeeee;
  margin-bottom: 20rpx;
}
.empty {
  margin-top: 240rpx;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 12rpx;
}
.empty-icon {
  font-size: 72rpx;
}
.empty-text {
  font-size: 28rpx;
  color: #999999;
}
.empty-sub {
  font-size: 24rpx;
  color: #bbbbbb;
}
.tabbar-holder {
  height: 140rpx;
}
</style>
