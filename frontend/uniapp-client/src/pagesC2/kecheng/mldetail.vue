<script setup lang="ts">
import { computed, onBeforeUnmount, ref } from "vue";
import { onLoad } from "@dcloudio/uni-app";
import { fetchKcMlDetail, fetchKcNextSection, kcStudyAdd } from "../api/kecheng";
import type { KcMlDetail, KcMlDetailResult, KcMlRow, KcStudyLog } from "../api/kecheng";
import { imgUrl } from "../../utils/format";
import { goPage } from "../../utils/navigate";

const kcid = ref(0);
const data = ref<KcMlDetailResult | null>(null);
const chapterVisible = ref(false);
const jindu = ref(0);
const learned = ref(false);
const rate = ref(1);
const rates = [1, 1.25, 1.5, 2];

let logid = 0;
let lastReport = 0;
let reportTimer: ReturnType<typeof setInterval> | null = null;
let audioCtx: UniApp.InnerAudioContext | null = null;

const detail = computed<KcMlDetail | null>(() => data.value?.detail || null);
const chapters = computed<KcMlRow[]>(() => data.value?.chapters || []);
const kctype = computed(() => Number(detail.value?.kctype ?? 0));
/** 未购买且收费 → 引导购买 */
const needBuy = computed(
  () => Number(detail.value?.ispay ?? 0) !== 1 && Number(detail.value?.price ?? 0) > 0,
);

onLoad((options) => {
  kcid.value = Number(options && options.kcid) || 0;
  const mlid = Number(options && options.id) || 0;
  if (kcid.value > 0) void loadSection(mlid);
});

async function loadSection(mlid: number) {
  stopMedia();
  try {
    const res = await fetchKcMlDetail(kcid.value, mlid);
    data.value = res;
    jindu.value = Number(res.studylog?.jindu ?? 0);
    learned.value = Number(res.studylog?.status ?? 0) === 1;
    logid = Number(res.studylog?.id ?? 0);
    chapterVisible.value = false;

    const kc = Number(res.detail.kctype ?? 0);
    if (kc === 1) {
      // 图文：上报即完成
      void reportArticle();
    } else if (kc === 2) {
      startAudio(res.detail);
    }
    // 视频走模板内 <video>，startTime 由属性传入
  } catch {
    /* request 已弹 toast */
  }
}

async function reportArticle() {
  if (!logid || learned.value) return;
  try {
    const res = await kcStudyAdd(logid, 0, "100");
    jindu.value = Number(res.jindu ?? 100);
    learned.value = Number(res.status ?? 0) === 1;
    if (learned.value) uni.showToast({ title: "本节已学完", icon: "none" });
  } catch {
    /* 忽略 */
  }
}

/** 进度上报（节流 5s） */
function reportProgress(currentTime: number, force = false) {
  if (!logid || learned.value) return;
  const now = Date.now();
  if (!force && now - lastReport < 5000) return;
  lastReport = now;
  void kcStudyAdd(logid, currentTime)
    .then((res) => {
      jindu.value = Number(res.jindu ?? jindu.value);
      if (Number(res.status ?? 0) === 1) {
        learned.value = true;
        uni.showToast({ title: "本节已学完", icon: "none" });
      }
    })
    .catch(() => undefined);
}

function onVideoTimeUpdate(e: Event) {
  const detail = (e as unknown as { detail?: { currentTime?: number } }).detail;
  reportProgress(Number(detail?.currentTime ?? 0));
}

function onVideoEnded() {
  reportProgress(999999, true);
}

function startAudio(d: KcMlDetail) {
  const src = d.voice_url || "";
  if (!src) return;
  audioCtx = uni.createInnerAudioContext();
  audioCtx.src = imgUrl(src);
  // #ifndef H5
  audioCtx.startTime = Number(d.startTime ?? 0);
  // #endif
  // #ifdef H5
  // H5 端 InnerAudioContext 无 startTime 属性，seek 代替
  audioCtx.onCanplay(() => {
    const st = Number(d.startTime ?? 0);
    if (st > 0 && audioCtx) audioCtx.seek(st);
  });
  // #endif
  audioCtx.onTimeUpdate(() => {
    if (audioCtx) reportProgress(audioCtx.currentTime);
  });
  audioCtx.onEnded(() => {
    reportProgress(999999, true);
  });
  audioCtx.play();
}

function stopMedia() {
  if (audioCtx) {
    audioCtx.stop();
    audioCtx.destroy();
    audioCtx = null;
  }
  if (reportTimer) {
    clearInterval(reportTimer);
    reportTimer = null;
  }
}

function setRate(r: number) {
  rate.value = r;
  // #ifndef H5
  // 小程序端经 VideoContext 同步倍速（H5 端 playback-rate 属性直接生效）
  const ctx = uni.createVideoContext("kc-player");
  if (ctx) ctx.playbackRate(r);
  // #endif
  if (audioCtx) audioCtx.playbackRate = r;
}

function openChapters() {
  chapterVisible.value = true;
}

function pickChild(cid: number) {
  void loadSection(cid);
}

async function nextSection() {
  const d = detail.value;
  if (!d) return;
  try {
    const res = await fetchKcNextSection(d.id, kcid.value);
    if (res.id > 0) {
      void loadSection(res.id);
    } else {
      uni.showToast({ title: "已是最后一节", icon: "none" });
    }
  } catch {
    /* request 已弹 toast */
  }
}

function goBuy() {
  goPage("/pagesC2/kecheng/buy?id=" + kcid.value);
}

function goTiku() {
  const d = detail.value;
  if (!d) return;
  goPage("/pagesC2/kecheng/tiku?kcid=" + kcid.value + "&mlid=" + d.id);
}

function goRecord() {
  const d = detail.value;
  if (!d) return;
  goPage("/pagesC2/kecheng/recordlog?kcid=" + kcid.value + "&mlid=" + d.id);
}

onBeforeUnmount(() => {
  stopMedia();
});
</script>

<template>
  <view v-if="detail" class="page">
    <view class="head-bar">
      <text class="head-title">{{ detail.name }}</text>
      <text class="head-jindu">进度 {{ jindu }}%</text>
    </view>

    <!-- 未购引导 -->
    <view v-if="needBuy" class="buy-tip">
      <text class="buy-text">该章节需购买课程后学习</text>
      <view class="buy-btn" @click="goBuy"><text>去购买 ￥{{ detail.price }}</text></view>
    </view>

    <template v-else>
      <!-- 图文 -->
      <view v-if="kctype === 1" class="content-card">
        <rich-text :nodes="detail.content || '暂无内容'"></rich-text>
      </view>

      <!-- 音频 -->
      <view v-else-if="kctype === 2" class="audio-card">
        <view class="audio-icon"><text>🎧</text></view>
        <text class="audio-tip">音频播放中，离开页面前进度自动上报</text>
      </view>

      <!-- 视频 -->
      <video
        v-else-if="kctype === 3"
        id="kc-player"
        class="kc-video"
        :src="imgUrl(detail.video_url || '')"
        :initial-time="Number(detail.startTime || 0)"
        :playback-rate="rate"
        controls
        :show-fullscreen-btn="true"
        @timeupdate="onVideoTimeUpdate"
        @ended="onVideoEnded"
      ></video>

      <!-- 倍速 -->
      <view v-if="kctype !== 1" class="rate-row">
        <text class="rate-label">倍速</text>
        <view v-for="r in rates" :key="r" class="rate-item" :class="{ on: rate === r }" @click="setRate(r)">
          <text>{{ r }}x</text>
        </view>
      </view>

      <!-- 操作区 -->
      <view class="ops">
        <view class="op-btn" @click="openChapters"><text>📖 目录（{{ data && data.chapters ? data.chapters.length : 0 }} 章）</text></view>
        <view class="op-btn" @click="nextSection"><text>下一节 ▶</text></view>
        <view v-if="data && Number(data.iskaoshi) === 1" class="op-btn primary" @click="goTiku"><text>📝 章节考试</text></view>
        <view class="op-btn" @click="goRecord"><text>学习记录</text></view>
      </view>
    </template>

    <!-- 目录抽屉 -->
    <view v-if="chapterVisible" class="mask" @click="chapterVisible = false">
      <view class="drawer" @click.stop>
        <view class="drawer-head">
          <text class="drawer-title">课程目录</text>
          <text class="drawer-close" @click="chapterVisible = false">✕</text>
        </view>
        <scroll-view class="drawer-list" scroll-y>
          <view v-for="ch in chapters" :key="ch.id" class="ch-group">
            <text class="ch-name">{{ ch.name }}</text>
            <view
              v-for="child in ch.children || []"
              :key="child.id"
              class="child-row"
              :class="{ cur: detail.id === child.id }"
              @click="pickChild(child.id)"
            >
              <text class="child-icon">{{ Number(child.kctype) === 3 ? "🎬" : Number(child.kctype) === 2 ? "🎧" : "📄" }}</text>
              <text class="child-name">{{ child.name }}</text>
              <text class="child-jindu" :class="{ done: Number(child.study_status) === 1 }">
                {{ Number(child.study_status) === 1 ? "已学完" : "进度 " + (child.jindu || 0) + "%" }}
              </text>
            </view>
          </view>
        </scroll-view>
      </view>
    </view>
  </view>
</template>

<style scoped>
.page {
  min-height: 100vh;
  background: #f8f8f8;
  padding-bottom: 60rpx;
}
.head-bar {
  background: #ffffff;
  padding: 28rpx;
  display: flex;
  align-items: center;
}
.head-title {
  font-size: 32rpx;
  font-weight: 700;
  color: #222222;
  flex: 1;
}
.head-jindu {
  font-size: 24rpx;
  color: #7b7de5;
}
.buy-tip {
  margin: 24rpx;
  background: #ffffff;
  border-radius: 16rpx;
  padding: 60rpx 28rpx;
  display: flex;
  flex-direction: column;
  align-items: center;
}
.buy-text {
  font-size: 28rpx;
  color: #666666;
}
.buy-btn {
  margin-top: 32rpx;
  padding: 20rpx 64rpx;
  background: #7b7de5;
  color: #ffffff;
  border-radius: 44rpx;
  font-size: 30rpx;
}
.content-card {
  margin: 24rpx;
  background: #ffffff;
  border-radius: 16rpx;
  padding: 28rpx;
  font-size: 28rpx;
  color: #333333;
  line-height: 1.7;
  word-break: break-all;
}
.audio-card {
  margin: 24rpx;
  background: #ffffff;
  border-radius: 16rpx;
  padding: 80rpx 28rpx;
  display: flex;
  flex-direction: column;
  align-items: center;
}
.audio-icon {
  font-size: 96rpx;
}
.audio-tip {
  margin-top: 24rpx;
  font-size: 26rpx;
  color: #999999;
}
.kc-video {
  width: 100%;
  height: 420rpx;
  background: #000000;
}
.rate-row {
  margin: 24rpx;
  display: flex;
  align-items: center;
}
.rate-label {
  font-size: 26rpx;
  color: #666666;
  margin-right: 16rpx;
}
.rate-item {
  padding: 10rpx 28rpx;
  margin-right: 16rpx;
  background: #ffffff;
  border-radius: 28rpx;
  color: #666666;
  font-size: 24rpx;
}
.rate-item.on {
  background: #7b7de5;
  color: #ffffff;
}
.ops {
  margin: 24rpx;
  display: flex;
  flex-wrap: wrap;
}
.op-btn {
  padding: 18rpx 32rpx;
  background: #ffffff;
  color: #555555;
  border-radius: 36rpx;
  font-size: 26rpx;
  margin: 0 16rpx 16rpx 0;
}
.op-btn.primary {
  background: #ecebf7;
  color: #7b7de5;
}
.mask {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.5);
  display: flex;
  justify-content: flex-end;
}
.drawer {
  width: 76%;
  height: 100%;
  background: #ffffff;
  display: flex;
  flex-direction: column;
}
.drawer-head {
  display: flex;
  align-items: center;
  padding: 28rpx;
  border-bottom: 1rpx solid #f2f2f2;
}
.drawer-title {
  font-size: 30rpx;
  font-weight: 600;
  color: #333333;
}
.drawer-close {
  margin-left: auto;
  font-size: 32rpx;
  color: #999999;
}
.drawer-list {
  flex: 1;
}
.ch-group {
  padding: 20rpx 28rpx;
}
.ch-name {
  font-size: 28rpx;
  font-weight: 600;
  color: #333333;
}
.child-row {
  display: flex;
  align-items: center;
  padding: 22rpx 0 22rpx 16rpx;
  border-bottom: 1rpx solid #f7f7fa;
}
.child-row.cur {
  background: #f4f4fd;
  border-radius: 12rpx;
}
.child-icon {
  font-size: 28rpx;
  margin-right: 12rpx;
}
.child-name {
  flex: 1;
  font-size: 26rpx;
  color: #444444;
}
.child-jindu {
  font-size: 22rpx;
  color: #b5b5b5;
}
.child-jindu.done {
  color: #2e9e5b;
}
</style>
