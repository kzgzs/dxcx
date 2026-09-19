<script setup lang="ts">
/**
 * 手机端后台自绘底部 Tab（对照参考项目 admin/index/index.vue 底部 tabbar）
 * 四项：会员 / 咨询 / 财务 / 我的，reLaunch 切换（参照参考页 data-opentype="reLaunch"）
 */
interface TabItem {
  /** 图标（emoji 自绘，不依赖远端图片资源） */
  icon: string;
  /** 文案 */
  text: string;
  /** 页面路径（admin 子包内） */
  url: string;
}

const props = defineProps<{
  /** 当前选中项 key：member / kefu / finance / my */
  current: "member" | "kefu" | "finance" | "my";
}>();

/** Tab 项配置（顺序与参考页一致：会员、咨询、财务、我的） */
const tabs: Array<TabItem & { key: string }> = [
  { key: "member", icon: "👥", text: "会员", url: "/admin/member/index" },
  { key: "kefu", icon: "💬", text: "咨询", url: "/admin/kefu/index" },
  { key: "finance", icon: "💰", text: "财务", url: "/admin/finance/index" },
  { key: "my", icon: "👤", text: "我的", url: "/admin/my/index" },
];

/** reLaunch 切换（同页不重复跳） */
function switchTab(item: TabItem & { key: string }) {
  if (item.key === props.current) return;
  uni.reLaunch({ url: item.url });
}
</script>

<template>
  <view class="tabbar">
    <view class="tabbar-bar">
      <view
        v-for="item in tabs"
        :key="item.key"
        class="tabbar-item"
        @click="switchTab(item)"
      >
        <view class="tabbar-icon-box">
          <text class="tabbar-icon">{{ item.icon }}</text>
        </view>
        <text class="tabbar-text" :class="{ active: item.key === current }">{{ item.text }}</text>
      </view>
    </view>
    <!-- 底部安全区占位 -->
    <view class="tabbar-safe"></view>
  </view>
</template>

<style scoped>
.tabbar {
  position: fixed;
  left: 0;
  right: 0;
  bottom: 0;
  z-index: 99;
  background: #ffffff;
  box-shadow: 0 -2rpx 12rpx rgba(0, 0, 0, 0.05);
}
.tabbar-bar {
  display: flex;
  align-items: center;
  height: 100rpx;
}
.tabbar-item {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
}
.tabbar-icon-box {
  width: 44rpx;
  height: 44rpx;
  display: flex;
  align-items: center;
  justify-content: center;
}
.tabbar-icon {
  font-size: 38rpx;
}
.tabbar-text {
  margin-top: 2rpx;
  font-size: 22rpx;
  color: #999999;
}
.tabbar-text.active {
  color: #7b7de5;
  font-weight: 600;
}
.tabbar-safe {
  padding-bottom: constant(safe-area-inset-bottom);
  padding-bottom: env(safe-area-inset-bottom);
}
</style>
