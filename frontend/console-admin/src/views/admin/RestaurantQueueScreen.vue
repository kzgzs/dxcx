<script setup lang="ts">
/**
 * 排队叫号大屏（独立公开路由 /queue/screen?aid=X，无需登录）
 * - 每 5 秒轮询 /api/restaurantQueue/getqueuelist
 * - 最近 300 秒内叫号高亮展示，等待列表 16 位栅格
 * - 语音降级方案：浏览器 TTS（Web Speech API）播报 call_text
 */
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import axios from 'axios'
import { speakCallText } from '../../api/queue'

interface ScreenSet {
  screen_title: string
  screen_pic: string
  screen_desc: string
  status: number
}

interface LastQueue {
  id: number
  queue_no: string
  call_text: string
  call_time?: number
}

interface WaitItem {
  id: number
  queue_no: string
  cid: number
  renshu: string
  create_time: number
}

const route = useRoute()
const aid = Number(route.query.aid || 0)

const screenSet = ref<ScreenSet>({ screen_title: '', screen_pic: '', screen_desc: '', status: 1 })
const lastqueue = ref<LastQueue>({ id: 0, queue_no: '--', call_text: '当前叫号' })
const waitlist = ref<WaitItem[]>([])
const loadError = ref(false)

// 时钟（大屏右上角）
const clock = ref('')
let clockTimer: number | undefined
let pollTimer: number | undefined
// 记录上一次播报的叫号 ID，避免重复 TTS
let spokenQueueId = 0

const bgStyle = computed(() => {
  const pic = screenSet.value.screen_pic
  return pic
    ? { backgroundImage: `linear-gradient(rgba(10, 12, 20, 0.78), rgba(10, 12, 20, 0.86)), url(${pic})`, backgroundSize: 'cover', backgroundPosition: 'center' }
    : {}
})

const displayWaitlist = computed(() => {
  const list = [...waitlist.value]
  for (let i = list.length; i < 16; i++) {
    list.push({ id: 0, queue_no: '', cid: 0, renshu: '', create_time: 0 })
  }
  return list.slice(0, 16)
})

function pad(n: number): string {
  return String(n).padStart(2, '0')
}

function tickClock() {
  const d = new Date()
  clock.value = `${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`
}

async function poll() {
  if (!aid) return
  try {
    const resp = await axios.get('/api/restaurantQueue/getqueuelist', { params: { aid }, timeout: 10000 })
    const body = resp.data
    if (!body || body.code !== 0) return
    const data = body.data || {}
    if (data.set) screenSet.value = data.set
    if (data.lastqueue) {
      const prev = lastqueue.value
      lastqueue.value = data.lastqueue
      // 新叫号（或重复叫同号）触发 TTS 播报
      const newId = Number(data.lastqueue.id || 0)
      if (newId > 0 && (newId !== spokenQueueId || prev.queue_no !== data.lastqueue.queue_no)) {
        spokenQueueId = newId
        speakCallText(String(data.lastqueue.call_text || ''))
      }
    }
    if (Array.isArray(data.waitlist)) waitlist.value = data.waitlist
    loadError.value = false
  } catch {
    loadError.value = true
  }
}

onMounted(() => {
  tickClock()
  clockTimer = window.setInterval(tickClock, 1000)
  poll()
  pollTimer = window.setInterval(poll, 5000)
})

onUnmounted(() => {
  if (clockTimer) window.clearInterval(clockTimer)
  if (pollTimer) window.clearInterval(pollTimer)
  if ('speechSynthesis' in window) window.speechSynthesis.cancel()
})
</script>

<template>
  <div class="queue-screen" :style="bgStyle">
    <!-- 顶部：标题 + 时钟 -->
    <header class="screen-header">
      <div class="brand">
        <span class="brand-dot"></span>
        <span class="brand-text">{{ screenSet.screen_title || '排队叫号' }}</span>
      </div>
      <div class="clock">{{ clock }}</div>
    </header>

    <!-- 排队关闭提示 -->
    <div v-if="screenSet.status === 0" class="closed-mask">
      <div class="closed-text">当前不在排队时段</div>
      <div class="closed-sub">请稍候或咨询店员</div>
    </div>

    <main v-else class="screen-main">
      <!-- 左：当前叫号 -->
      <section class="call-board">
        <div class="call-label">当前叫号</div>
        <div class="call-no" :class="{ living: lastqueue.id > 0 }">{{ lastqueue.queue_no }}</div>
        <div class="call-text">{{ lastqueue.call_text || '等待叫号' }}</div>
        <div class="call-wave" :class="{ living: lastqueue.id > 0 }">
          <i></i><i></i><i></i><i></i><i></i>
        </div>
      </section>

      <!-- 右：等待列表 16 位 -->
      <section class="wait-board">
        <div class="wait-title">
          <span>等待队列</span>
          <span class="wait-hint">请留意叫号，过号需重新取号</span>
        </div>
        <div class="wait-grid">
          <div
            v-for="(w, idx) in displayWaitlist"
            :key="idx"
            class="wait-cell"
            :class="{ empty: !w.queue_no, first: idx === 0 && !!w.queue_no }"
          >
            <template v-if="w.queue_no">
              <span class="wait-no">{{ w.queue_no }}</span>
              <span v-if="w.renshu" class="wait-ren">{{ w.renshu }}人</span>
            </template>
            <span v-else class="wait-empty">·</span>
          </div>
        </div>
      </section>
    </main>

    <!-- 底部：滚动说明 -->
    <footer v-if="screenSet.screen_desc" class="screen-footer">
      <div class="marquee">
        <span>{{ screenSet.screen_desc }}</span>
        <span class="marquee-copy">{{ screenSet.screen_desc }}</span>
      </div>
    </footer>

    <div v-if="loadError" class="error-tip">网络异常，正在重试…</div>
  </div>
</template>

<style scoped>
.queue-screen {
  position: fixed;
  inset: 0;
  display: flex;
  flex-direction: column;
  background-color: #0a0c14;
  color: #f5f1e8;
  font-family: 'Microsoft YaHei', 'PingFang SC', sans-serif;
  overflow: hidden;
}

/* ===== 顶部 ===== */
.screen-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 28px 48px 10px;
}

.brand {
  display: flex;
  align-items: center;
  gap: 14px;
}

.brand-dot {
  width: 14px;
  height: 14px;
  border-radius: 50%;
  background: #ffb347;
  box-shadow: 0 0 18px rgba(255, 179, 71, 0.9);
}

.brand-text {
  font-size: 34px;
  font-weight: 700;
  letter-spacing: 6px;
}

.clock {
  font-size: 30px;
  letter-spacing: 3px;
  color: #c9c2b2;
  font-family: 'Bahnschrift', 'DIN Alternate', 'Segoe UI', monospace;
}

/* ===== 主体 ===== */
.screen-main {
  flex: 1;
  display: grid;
  grid-template-columns: 5fr 7fr;
  gap: 40px;
  padding: 20px 48px;
  min-height: 0;
}

/* 当前叫号板 */
.call-board {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 22px;
  border: 2px solid rgba(255, 179, 71, 0.35);
  border-radius: 18px;
  background: rgba(255, 179, 71, 0.06);
  padding: 30px;
}

.call-label {
  font-size: 28px;
  letter-spacing: 12px;
  color: #ffb347;
}

.call-no {
  font-size: 160px;
  line-height: 1;
  font-weight: 700;
  letter-spacing: 6px;
  color: #6f6a5e;
  font-family: 'Bahnschrift', 'DIN Alternate', 'Segoe UI', monospace;
  transition: color 0.4s;
}

.call-no.living {
  color: #ffd98a;
  text-shadow: 0 0 40px rgba(255, 179, 71, 0.55);
  animation: call-pop 0.6s ease;
}

@keyframes call-pop {
  0% { transform: scale(0.86); opacity: 0.4; }
  60% { transform: scale(1.06); }
  100% { transform: scale(1); opacity: 1; }
}

.call-text {
  font-size: 26px;
  color: #e8e0cf;
  letter-spacing: 2px;
  text-align: center;
}

/* 声波装饰 */
.call-wave {
  display: flex;
  align-items: flex-end;
  gap: 8px;
  height: 42px;
}

.call-wave i {
  width: 10px;
  height: 10px;
  border-radius: 3px;
  background: rgba(255, 179, 71, 0.35);
}

.call-wave.living i {
  background: #ffb347;
  animation: wave 1s ease-in-out infinite;
}

.call-wave.living i:nth-child(2) { animation-delay: 0.15s; }
.call-wave.living i:nth-child(3) { animation-delay: 0.3s; }
.call-wave.living i:nth-child(4) { animation-delay: 0.45s; }
.call-wave.living i:nth-child(5) { animation-delay: 0.6s; }

@keyframes wave {
  0%, 100% { height: 10px; }
  50% { height: 40px; }
}

/* 等待列表 */
.wait-board {
  display: flex;
  flex-direction: column;
  min-height: 0;
}

.wait-title {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  padding-bottom: 14px;
  border-bottom: 2px solid rgba(245, 241, 232, 0.15);
}

.wait-title span:first-child {
  font-size: 28px;
  font-weight: 700;
  letter-spacing: 8px;
}

.wait-hint {
  font-size: 16px;
  color: #9a927f;
}

.wait-grid {
  flex: 1;
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  grid-template-rows: repeat(4, 1fr);
  gap: 14px;
  padding-top: 18px;
  min-height: 0;
}

.wait-cell {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 4px;
  border-radius: 12px;
  background: rgba(245, 241, 232, 0.07);
  border: 1px solid rgba(245, 241, 232, 0.1);
}

.wait-cell.first {
  background: rgba(255, 179, 71, 0.16);
  border-color: rgba(255, 179, 71, 0.6);
}

.wait-cell.first .wait-no {
  color: #ffd98a;
}

.wait-no {
  font-size: 44px;
  font-weight: 700;
  letter-spacing: 2px;
  color: #f5f1e8;
  font-family: 'Bahnschrift', 'DIN Alternate', 'Segoe UI', monospace;
}

.wait-ren {
  font-size: 16px;
  color: #9a927f;
}

.wait-empty {
  font-size: 40px;
  color: rgba(245, 241, 232, 0.18);
}

/* ===== 底部 ===== */
.screen-footer {
  padding: 16px 0 24px;
  overflow: hidden;
  border-top: 1px solid rgba(245, 241, 232, 0.12);
}

.marquee {
  display: flex;
  white-space: nowrap;
  animation: marquee 24s linear infinite;
}

.marquee span {
  font-size: 24px;
  letter-spacing: 4px;
  color: #c9c2b2;
  padding-right: 120px;
}

@keyframes marquee {
  0% { transform: translateX(0); }
  100% { transform: translateX(-50%); }
}

/* ===== 关闭 / 异常 ===== */
.closed-mask {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 18px;
}

.closed-text {
  font-size: 64px;
  font-weight: 700;
  letter-spacing: 10px;
  color: #ffb347;
}

.closed-sub {
  font-size: 26px;
  color: #9a927f;
}

.error-tip {
  position: absolute;
  right: 24px;
  bottom: 24px;
  font-size: 14px;
  color: #e57373;
}
</style>
