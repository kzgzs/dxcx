<script setup lang="ts">
/**
 * dp-orderbroadcast 订单播报（任务320：组件化改造，页面流内按放置位置渲染，非 fixed）
 * - 数据源 /api/index/recentOrders，swiper 竖向轮播
 * - 门控：读 theme store 的 sysset.order_broadcast CSV（与 follow-bar 同口径），全部去掉勾选 → 不渲染
 * - 无数据不渲染；脏记录（昵称/商品名全空）不参与播报
 * - params: height 组件高度 / borderradius 圆角 / bgcolor 背景色 / color 文字颜色 /
 *           showavatar 头像显隐 / interval 自动轮播间隔秒；与上方距离/左右边距由外层 margin_y/margin_x 承载
 */
import { computed, onMounted, ref } from "vue";
import { fetchRecentOrders, type RecentOrder } from "../../api/designer";
import { useThemeStore } from "../../stores/theme";
import { flag, num, str, type DpParams } from "./shared";

const props = withDefaults(
  defineProps<{
    params?: DpParams;
    data?: unknown;
  }>(),
  { params: () => ({}), data: () => [] }
);

const theme = useThemeStore();
const list = ref<RecentOrder[]>([]);
const show = ref(false);

/** 组件高度（设计值 *2.2 → rpx，兜底 36） */
const barHeight = computed(() => `${num(props.params.height, 36) * 2.2}rpx`);
/** 圆角（设计值 *2 → rpx，兜底 18 胶囊感） */
const radius = computed(() => `${num(props.params.borderradius, 18) * 2}rpx`);
/** 背景色/文字颜色 */
const bgColor = computed(() => str(props.params.bgcolor, "#F5F5F5"));
const textColor = computed(() => str(props.params.color, "#666666"));
/** 头像显隐 */
const showAvatar = computed(() => flag(props.params.showavatar, 1) === 1);
/** 自动轮播间隔（秒 → ms，最小 1s） */
const interval = computed(() => Math.max(1, num(props.params.interval, 3)) * 1000);

/** 昵称脱敏：首尾各留一字，中间 ** */
function mask(n: string): string {
  const name = (n || "").trim() || "用户";
  if (name.length <= 2) return name + "**";
  return name.charAt(0) + "**" + name.charAt(name.length - 1);
}

onMounted(async () => {
  // 门控：系统设置 order_broadcast 关（CSV 去空）→ 不渲染，与 follow-bar 同口径
  try {
    await theme.load();
  } catch {
    return;
  }
  const csv = String(theme.sysset?.order_broadcast ?? "1,2")
    .split(",")
    .map((t) => t.trim())
    .filter(Boolean);
  if (!csv.length) return;
  try {
    const r = await fetchRecentOrders();
    // 过滤无内容记录：昵称与商品名全空的脏数据不参与播报
    list.value = (r.list || []).filter((o) => (o.nickname || "").trim() || (o.title || "").trim());
    show.value = list.value.length > 0;
  } catch {
    // 播报数据拉取失败不阻塞页面
  }
});
</script>

<template>
  <view
    v-if="show"
    class="dp-ob"
    :style="{ height: barHeight, borderRadius: radius, background: bgColor, color: textColor }"
  >
    <swiper class="dp-ob-swiper" vertical autoplay circular :interval="interval" :duration="400">
      <swiper-item v-for="(o, i) in list" :key="'ob' + i">
        <view class="dp-ob-line" :style="{ height: barHeight }">
          <template v-if="showAvatar">
            <image v-if="o.headimg" class="dp-ob-avatar" :src="o.headimg" mode="aspectFill" />
            <view v-else class="dp-ob-avatar dp-ob-avatar-ph"></view>
          </template>
          <text class="dp-ob-text">{{ mask(o.nickname) }} 刚刚购买了 {{ o.title }}</text>
        </view>
      </swiper-item>
    </swiper>
  </view>
</template>

<style scoped>
.dp-ob {
  width: 100%;
  overflow: hidden;
  box-sizing: border-box;
  padding: 0 20rpx;
}
.dp-ob-swiper {
  width: 100%;
  height: 100%;
}
.dp-ob-line {
  display: flex;
  align-items: center;
  width: 100%;
}
.dp-ob-avatar {
  width: 36rpx;
  height: 36rpx;
  border-radius: 50%;
  margin-right: 10rpx;
  flex: none;
}
.dp-ob-avatar-ph {
  background: var(--tp-primary, #fd4a46);
}
.dp-ob-text {
  flex: 1;
  min-width: 0;
  font-size: 22rpx;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
</style>
