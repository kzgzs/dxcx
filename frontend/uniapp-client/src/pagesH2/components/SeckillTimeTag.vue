<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from "vue";

/**
 * 秒杀场次时间态标签
 * - 未开始：显示距开始倒计时
 * - 抢购中：显示距结束倒计时
 * - 已结束：静态文案
 * - 组件卸载自动清理定时器；跨态时 emit change
 */
const props = defineProps<{
  starttime: number;
  endtime: number;
}>();

const emit = defineEmits<{ (e: "change", phase: "before" | "ing" | "end"): void }>();

const nowTs = ref(Math.floor(Date.now() / 1000));
let timer: ReturnType<typeof setInterval> | null = null;

const phase = computed<"before" | "ing" | "end">(() => {
  if (nowTs.value < props.starttime) return "before";
  if (nowTs.value <= props.endtime) return "ing";
  return "end";
});

const remain = computed(() => {
  if (phase.value === "before") return Math.max(0, props.starttime - nowTs.value);
  if (phase.value === "ing") return Math.max(0, props.endtime - nowTs.value);
  return 0;
});

function pad(n: number): string {
  return n < 10 ? "0" + n : "" + n;
}

const countdownText = computed(() => {
  const s = remain.value;
  const hour = Math.floor(s / 3600);
  const minute = Math.floor((s % 3600) / 60);
  const sec = s % 60;
  return pad(hour) + ":" + pad(minute) + ":" + pad(sec);
});

let lastPhase: "before" | "ing" | "end" | "" = "";

function tick() {
  nowTs.value = Math.floor(Date.now() / 1000);
  if (lastPhase !== "" && lastPhase !== phase.value) {
    emit("change", phase.value);
  }
  lastPhase = phase.value;
  if (phase.value === "end") {
    stop();
  }
}

function stop() {
  if (timer !== null) {
    clearInterval(timer);
    timer = null;
  }
}

onMounted(() => {
  lastPhase = phase.value;
  if (phase.value !== "end") {
    timer = setInterval(tick, 1000);
  }
});

onUnmounted(() => {
  stop();
});
</script>

<template>
  <view class="tag" :class="phase">
    <template v-if="phase === 'before'">
      <text class="tag-label">距开始</text>
      <text class="tag-time">{{ countdownText }}</text>
    </template>
    <template v-else-if="phase === 'ing'">
      <text class="tag-label">抢购中</text>
      <text class="tag-time">{{ countdownText }}</text>
    </template>
    <template v-else>
      <text class="tag-label">已结束</text>
    </template>
  </view>
</template>

<style scoped>
.tag {
  display: inline-flex;
  align-items: center;
  border-radius: 8rpx;
  padding: 4rpx 14rpx;
  font-size: 22rpx;
}
.tag.before {
  background: #eef0ff;
  color: #7b7de5;
}
.tag.ing {
  background: #ff4d4f;
  color: #ffffff;
}
.tag.end {
  background: #f0f0f0;
  color: #999999;
}
.tag-label {
  font-size: 22rpx;
}
.tag-time {
  margin-left: 8rpx;
  font-size: 22rpx;
  font-variant-numeric: tabular-nums;
}
</style>
