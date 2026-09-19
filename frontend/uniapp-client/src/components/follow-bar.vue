<script setup lang="ts">
/**
 * 公众号关注引导条（任务308）：仅公众号 H5 环境展示
 * 展示口径：公众号关注组件开启 或 关注提示勾选当前页面（CSV 1首页 2商品详情页）
 */
import { onMounted, ref } from "vue";
import { useThemeStore } from "../stores/theme";

const props = defineProps<{
  /** 1首页 2商品详情页 */
  pageKey: number;
}>();

const theme = useThemeStore();
const show = ref(false);
const closed = ref(false);

function isGzhH5(): boolean {
  // #ifdef H5
  try {
    return /MicroMessenger/i.test(navigator.userAgent);
  } catch {
    return false;
  }
  // #endif
  // #ifndef H5
  return false;
  // #endif
}

onMounted(async () => {
  if (!isGzhH5()) return;
  try {
    await theme.load();
  } catch {
    return;
  }
  const s = theme.sysset;
  if (!s) return;
  const csv = String(s.guanzhu_tip ?? "")
    .split(",")
    .map((t) => t.trim());
  show.value = Number(s.official_account_status || 0) === 1 || csv.includes(String(props.pageKey));
});

function tipFollow() {
  uni.showToast({ title: "请点击右上角 ··· 选择「关注公众号」", icon: "none" });
}
function closeBar() {
  closed.value = true;
}
</script>

<template>
  <view v-if="show && !closed" class="fb-bar">
    <text class="fb-text">关注我们的公众号，获取最新资讯与优惠</text>
    <view class="fb-btn" @click="tipFollow">关注</view>
    <view class="fb-close" @click="closeBar">×</view>
  </view>
</template>

<style scoped>
.fb-bar {
  display: flex;
  align-items: center;
  padding: 14rpx 24rpx;
  background: var(--tp-primary, #fd4a46);
}
.fb-text {
  flex: 1;
  font-size: 24rpx;
  color: #ffffff;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.fb-btn {
  flex: none;
  margin-left: 16rpx;
  padding: 4rpx 24rpx;
  border-radius: 999rpx;
  background: #ffffff;
  color: var(--tp-primary, #fd4a46);
  font-size: 22rpx;
}
.fb-close {
  flex: none;
  margin-left: 16rpx;
  color: #ffffff;
  font-size: 30rpx;
  line-height: 1;
}
</style>
