<script setup lang="ts">
import { onBeforeUnmount, ref, watch } from "vue";

/**
 * 团剩余时间倒计时（deadline 为秒级时间戳）
 * - 每秒刷新；归零后触发 finish 事件
 */
const props = defineProps<{
  /** 截止时间（秒级时间戳） */
  deadline: number;
  /** 前缀文案 */
  prefix?: string;
}>();

const emit = defineEmits<{ (e: "finish"): void }>();

const text = ref("");
let timer = 0;
let fired = false;

function pad(n: number): string {
  return n < 10 ? "0" + n : "" + n;
}

function tick() {
  const remain = props.deadline - Math.floor(Date.now() / 1000);
  if (remain <= 0) {
    text.value = "已结束";
    if (!fired) {
      fired = true;
      emit("finish");
    }
    if (timer) {
      clearInterval(timer);
      timer = 0;
    }
    return;
  }
  const d = Math.floor(remain / 86400);
  const h = Math.floor((remain % 86400) / 3600);
  const m = Math.floor((remain % 3600) / 60);
  const s = remain % 60;
  text.value = d > 0 ? d + "天" + pad(h) + ":" + pad(m) + ":" + pad(s) : pad(h) + ":" + pad(m) + ":" + pad(s);
}

function restart() {
  fired = false;
  if (timer) clearInterval(timer);
  tick();
  timer = setInterval(tick, 1000) as unknown as number;
}

watch(() => props.deadline, restart);
restart();

onBeforeUnmount(() => {
  if (timer) clearInterval(timer);
});
</script>

<template>
  <text class="biz-countdown">{{ prefix || "剩余" }} {{ text }}</text>
</template>

<style scoped>
.biz-countdown {
  font-size: 22rpx;
  color: #ff6034;
}
</style>
