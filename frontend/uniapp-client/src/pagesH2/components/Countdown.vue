<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from "vue";

/**
 * 倒计时组件（秒级时间戳截止）
 * - 每秒刷新；组件卸载自动清理定时器
 * - 归零时 emit finish（仅触发一次）
 */
const props = defineProps<{
  /** 截止时间（秒级时间戳） */
  deadline: number;
  /** 是否显示天数段 */
  showDay?: boolean;
}>();

const emit = defineEmits<{ (e: "finish"): void }>();

const nowTs = ref(Math.floor(Date.now() / 1000));
let timer: ReturnType<typeof setInterval> | null = null;
let finished = false;

const remain = computed(() => Math.max(0, (props.deadline || 0) - nowTs.value));

function pad(n: number): string {
  return n < 10 ? "0" + n : "" + n;
}

const text = computed(() => {
  const s = remain.value;
  const day = Math.floor(s / 86400);
  const hour = Math.floor((s % 86400) / 3600);
  const minute = Math.floor((s % 3600) / 60);
  const sec = s % 60;
  if (props.showDay && day > 0) {
    return day + "天" + pad(hour) + ":" + pad(minute) + ":" + pad(sec);
  }
  const totalHour = Math.floor(s / 3600);
  return pad(totalHour) + ":" + pad(minute) + ":" + pad(sec);
});

function tick() {
  nowTs.value = Math.floor(Date.now() / 1000);
  if (remain.value <= 0 && !finished) {
    finished = true;
    stop();
    emit("finish");
  }
}

function stop() {
  if (timer !== null) {
    clearInterval(timer);
    timer = null;
  }
}

onMounted(() => {
  timer = setInterval(tick, 1000);
});

onUnmounted(() => {
  stop();
});
</script>

<template>
  <text class="countdown">{{ remain > 0 ? text : "00:00:00" }}</text>
</template>

<style scoped>
.countdown {
  font-variant-numeric: tabular-nums;
}
</style>
