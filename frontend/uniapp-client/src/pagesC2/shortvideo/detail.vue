<script setup lang="ts">
import { computed, ref } from "vue";
import { onLoad } from "@dcloudio/uni-app";
import { fetchSvComments, fetchSvDetail, svAddSharenum, svPlayEnd, svPzan, svSubPinglun, svUpdateViewnum, svZan } from "../api/shortvideo";
import type { SvComment, SvVideo } from "../api/shortvideo";
import { imgUrl } from "../../utils/format";

const id = ref(0);
const cid = ref(0);
const videoList = ref<SvVideo[]>([]);
const current = ref(0);
const comments = ref<SvComment[]>([]);
const panelVisible = ref(false);
const plText = ref("");
const replyTarget = ref(0);
const submitting = ref(false);
const rate = ref(1);
const rates = [0.75, 1, 1.25, 1.5, 2];
const reportedEnd = ref<Record<number, boolean>>({});

const cur = computed<SvVideo | null>(() => videoList.value[current.value] || null);

onLoad((options) => {
  id.value = Number(options && options.id) || 0;
  cid.value = Number(options && options.cid) || 0;
  if (id.value > 0) void load();
});

async function load() {
  try {
    const res = await fetchSvDetail(id.value, cid.value);
    videoList.value = res.videoList || [];
    current.value = 0;
    void reportView();
  } catch {
    /* request 已弹 toast */
  }
}

async function reportView() {
  const v = cur.value;
  if (!v) return;
  try {
    await svUpdateViewnum(v.id);
  } catch {
    /* 忽略 */
  }
}

function onSwiperChange(e: { detail: { current: number } }) {
  current.value = e.detail.current;
  comments.value = [];
  void reportView();
}

async function onZan() {
  const v = cur.value;
  if (!v) return;
  try {
    const res = await svZan(v.id);
    v.iszan = res.iszan;
    v.zan_num = res.zan_num;
  } catch {
    /* 忽略 */
  }
}

function setRate(r: number) {
  rate.value = r;
  // #ifndef H5
  // 小程序端部分机型需经 VideoContext 同步倍速
  const ctx = uni.createVideoContext("sv-player");
  if (ctx) ctx.playbackRate(r);
  // #endif
}

function onPlayEnd() {
  const v = cur.value;
  if (!v || reportedEnd.value[v.id]) return;
  reportedEnd.value[v.id] = true;
  void svPlayEnd(v.id).catch(() => undefined);
}

async function openPanel() {
  panelVisible.value = true;
  if (!comments.value.length) {
    const v = cur.value;
    if (!v) return;
    try {
      const res = await fetchSvComments(v.id, 1);
      comments.value = res.data || [];
    } catch {
      /* 忽略 */
    }
  }
}

function setReply(target: number) {
  replyTarget.value = target;
}

async function submitPl() {
  const v = cur.value;
  const content = plText.value.trim();
  if (!v || !content) {
    uni.showToast({ title: "请输入内容", icon: "none" });
    return;
  }
  if (submitting.value) return;
  submitting.value = true;
  try {
    const res = await svSubPinglun(v.id, content, replyTarget.value);
    if (typeof res.commentnum === "number") v.commentnum = res.commentnum;
    plText.value = "";
    replyTarget.value = 0;
    const reload = await fetchSvComments(v.id, 1);
    comments.value = reload.data || [];
  } catch {
    /* request 已弹 toast */
  } finally {
    submitting.value = false;
  }
}

async function onPzan(item: SvComment) {
  try {
    await svPzan(item.id);
    item.iszan = item.iszan ? 0 : 1;
    item.zan = (item.zan || 0) + (item.iszan ? 1 : -1);
  } catch {
    /* 忽略 */
  }
}

function onShare() {
  const v = cur.value;
  if (!v) return;
  void svAddSharenum(v.id).catch(() => undefined);
  uni.showToast({ title: "感谢分享", icon: "none" });
}

function videoSrc(item: SvVideo): string {
  return imgUrl(item.url || item.src || "");
}
</script>

<template>
  <view class="sv-page">
    <swiper class="sv-swiper" vertical :current="current" @change="onSwiperChange">
      <swiper-item v-for="(item, i) in videoList" :key="item.id">
        <view class="sv-item">
          <video
            v-if="i === current"
            id="sv-player"
            class="sv-video"
            :src="videoSrc(item)"
            autoplay
            :controls="true"
            :playback-rate="rate"
            object-fit="contain"
            :show-fullscreen-btn="true"
            @ended="onPlayEnd"
          ></video>
          <view class="sv-info">
            <text class="sv-name">{{ item.name || item.title || "未命名视频" }}</text>
            <text v-if="item.content" class="sv-desc">{{ item.content }}</text>
            <view class="rate-row">
              <text class="rate-label">倍速</text>
              <view v-for="r in rates" :key="r" class="rate-item" :class="{ on: rate === r }" @click="setRate(r)">
                <text>{{ r }}x</text>
              </view>
            </view>
          </view>
          <view class="sv-side">
            <view class="side-btn" :class="{ on: item.iszan === 1 }" @click="onZan">
              <text class="side-icon">♥</text>
              <text class="side-num">{{ item.zan_num || 0 }}</text>
            </view>
            <view class="side-btn" @click="openPanel">
              <text class="side-icon">💬</text>
              <text class="side-num">{{ item.commentnum || 0 }}</text>
            </view>
            <view class="side-btn" @click="onShare">
              <text class="side-icon">↗</text>
              <text class="side-num">{{ item.share_num || 0 }}</text>
            </view>
          </view>
        </view>
      </swiper-item>
    </swiper>

    <!-- 评论面板 -->
    <view v-if="panelVisible" class="mask" @click="panelVisible = false">
      <view class="panel" @click.stop>
        <view class="panel-head">
          <text class="panel-title">评论 {{ (cur && cur.commentnum) || 0 }}</text>
          <text class="panel-close" @click="panelVisible = false">✕</text>
        </view>
        <scroll-view class="panel-list" scroll-y>
          <view v-for="pl in comments" :key="pl.id" class="c-item">
            <image v-if="pl.headimg" class="c-avatar" :src="imgUrl(pl.headimg)" mode="aspectFill"></image>
            <view v-else class="c-avatar ph"><text>友</text></view>
            <view class="c-body">
              <text class="c-nick">{{ pl.nickname || "游客" }}</text>
              <text class="c-content">{{ pl.content }}</text>
              <view class="c-acts">
                <text class="c-time">{{ pl.createtime_fmt }}</text>
                <text class="c-act" @click="setReply(pl.id)">回复</text>
                <text class="c-act" :class="{ on: pl.iszan === 1 }" @click="onPzan(pl)">赞 {{ pl.zan || 0 }}</text>
              </view>
            </view>
          </view>
          <view v-if="!comments.length" class="c-empty"><text>暂无评论</text></view>
        </scroll-view>
        <view class="panel-input">
          <input
            v-model="plText"
            class="p-input"
            :placeholder="replyTarget > 0 ? '回复中...' : '写下你的评论'"
            confirm-type="send"
            @confirm="submitPl"
          />
          <view class="p-send" @click="submitPl"><text>{{ submitting ? "..." : "发送" }}</text></view>
        </view>
      </view>
    </view>
  </view>
</template>

<style scoped>
.sv-page {
  height: 100vh;
  background: #000000;
}
.sv-swiper {
  width: 100%;
  height: 100%;
}
.sv-item {
  width: 100%;
  height: 100%;
  position: relative;
}
.sv-video {
  width: 100%;
  height: 100%;
}
.sv-info {
  position: absolute;
  left: 24rpx;
  right: 160rpx;
  bottom: calc(60rpx + env(safe-area-inset-bottom));
}
.sv-name {
  color: #ffffff;
  font-size: 32rpx;
  font-weight: 600;
}
.sv-desc {
  margin-top: 10rpx;
  color: rgba(255, 255, 255, 0.8);
  font-size: 26rpx;
}
.rate-row {
  margin-top: 16rpx;
  display: flex;
  align-items: center;
}
.rate-label {
  color: rgba(255, 255, 255, 0.7);
  font-size: 22rpx;
  margin-right: 12rpx;
}
.rate-item {
  padding: 6rpx 20rpx;
  margin-right: 12rpx;
  border-radius: 24rpx;
  background: rgba(255, 255, 255, 0.15);
  color: #ffffff;
  font-size: 22rpx;
}
.rate-item.on {
  background: #7b7de5;
}
.sv-side {
  position: absolute;
  right: 24rpx;
  bottom: calc(160rpx + env(safe-area-inset-bottom));
  display: flex;
  flex-direction: column;
}
.side-btn {
  margin-top: 32rpx;
  display: flex;
  flex-direction: column;
  align-items: center;
  color: #ffffff;
}
.side-icon {
  font-size: 56rpx;
}
.side-btn.on .side-icon {
  color: #ff4d6d;
}
.side-num {
  font-size: 22rpx;
  margin-top: 4rpx;
}
.mask {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.5);
  display: flex;
  align-items: flex-end;
}
.panel {
  width: 100%;
  height: 65vh;
  background: #ffffff;
  border-radius: 24rpx 24rpx 0 0;
  display: flex;
  flex-direction: column;
}
.panel-head {
  display: flex;
  align-items: center;
  padding: 28rpx;
  border-bottom: 1rpx solid #f2f2f2;
}
.panel-title {
  font-size: 30rpx;
  font-weight: 600;
  color: #333333;
}
.panel-close {
  margin-left: auto;
  font-size: 32rpx;
  color: #999999;
}
.panel-list {
  flex: 1;
  padding: 0 28rpx;
}
.c-item {
  display: flex;
  padding: 24rpx 0;
  border-bottom: 1rpx solid #f7f7fa;
}
.c-avatar {
  width: 64rpx;
  height: 64rpx;
  border-radius: 50%;
  flex-shrink: 0;
}
.c-avatar.ph {
  background: #ecebf7;
  color: #7b7de5;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 26rpx;
}
.c-body {
  flex: 1;
  margin-left: 16rpx;
}
.c-nick {
  font-size: 24rpx;
  color: #999999;
}
.c-content {
  margin-top: 8rpx;
  font-size: 28rpx;
  color: #333333;
  line-height: 1.5;
}
.c-acts {
  margin-top: 10rpx;
  display: flex;
}
.c-time {
  font-size: 22rpx;
  color: #b5b5b5;
  margin-right: 28rpx;
}
.c-act {
  font-size: 22rpx;
  color: #999999;
  margin-right: 28rpx;
}
.c-act.on {
  color: #7b7de5;
}
.c-empty {
  padding: 80rpx 0;
  text-align: center;
  color: #b5b5b5;
  font-size: 26rpx;
}
.panel-input {
  display: flex;
  align-items: center;
  padding: 16rpx 24rpx;
  padding-bottom: calc(16rpx + env(safe-area-inset-bottom));
  border-top: 1rpx solid #f2f2f2;
}
.p-input {
  flex: 1;
  height: 68rpx;
  background: #f2f2f6;
  border-radius: 34rpx;
  padding: 0 28rpx;
  font-size: 26rpx;
}
.p-send {
  margin-left: 20rpx;
  padding: 16rpx 36rpx;
  background: #7b7de5;
  color: #ffffff;
  border-radius: 34rpx;
  font-size: 26rpx;
}
</style>
