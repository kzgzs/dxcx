<script setup lang="ts">
import { computed, ref } from "vue";
import { onLoad } from "@dcloudio/uni-app";
import {
  articlePzan,
  articleReward,
  articleSubPinglun,
  articleZan,
  fetchArticleDetail,
  fetchArticlePlList,
  type ArticleDetailResult,
  type ArticlePl,
} from "../api/article";
import { imgUrl } from "../../utils/format";

const id = ref(0);
const data = ref<ArticleDetailResult | null>(null);
const plList = ref<ArticlePl[]>([]);
const loading = ref(false);
const plText = ref("");
/** 回帖目标：0=一级评论，>0=回评目标评论 id */
const replyTarget = ref(0);
const submitting = ref(false);
const rewardVisible = ref(false);
const rewardNum = ref(0);
const rewardType = ref<1 | 2>(1);

const detail = computed(() => data.value?.detail || null);
const canComment = computed(() => Number(detail.value?.canpl ?? 1) === 1);

onLoad((options) => {
  id.value = Number(options && options.id) || 0;
  if (id.value > 0) {
    void loadDetail();
    void loadPl();
  }
});

async function loadDetail() {
  try {
    data.value = await fetchArticleDetail(id.value);
  } catch {
    /* request 已弹 toast */
  }
}

async function loadPl() {
  loading.value = true;
  try {
    const res = await fetchArticlePlList(id.value, 1);
    plList.value = res.list || [];
  } catch {
    /* 忽略 */
  } finally {
    loading.value = false;
  }
}

async function onZan() {
  try {
    const res = await articleZan(id.value);
    if (data.value) {
      data.value.iszan = res.type;
      if (data.value.detail) data.value.detail.zan = res.zancount;
    }
  } catch {
    /* 忽略 */
  }
}

async function onPzan(item: ArticlePl) {
  try {
    await articlePzan(item.id);
    item.iszan = item.iszan ? 0 : 1;
    item.zan = (item.zan || 0) + (item.iszan ? 1 : -1);
  } catch {
    /* 忽略 */
  }
}

function setReply(target: number) {
  if (!canComment.value) return;
  replyTarget.value = target;
}

async function submitPl() {
  const content = plText.value.trim();
  if (!content) {
    uni.showToast({ title: "请输入内容", icon: "none" });
    return;
  }
  if (submitting.value) return;
  submitting.value = true;
  try {
    if (replyTarget.value > 0) {
      await articleSubPinglun(id.value, 1, content, replyTarget.value);
    } else {
      await articleSubPinglun(id.value, 0, content);
    }
    plText.value = "";
    replyTarget.value = 0;
    uni.showToast({ title: "发布成功", icon: "none" });
    void loadPl();
    void loadDetail();
  } catch {
    /* request 已弹 toast */
  } finally {
    submitting.value = false;
  }
}

function openReward() {
  if (!data.value || Number(data.value.reward) !== 1) return;
  rewardNum.value = 0;
  rewardVisible.value = true;
}

function pickNum(n: number) {
  rewardNum.value = n;
}

async function doReward() {
  if (rewardNum.value <= 0) {
    uni.showToast({ title: "请选择打赏金额", icon: "none" });
    return;
  }
  try {
    await articleReward(id.value, rewardType.value, rewardNum.value);
    rewardVisible.value = false;
    uni.showToast({ title: "打赏成功", icon: "none" });
    void loadDetail();
  } catch {
    /* request 已弹 toast */
  }
}

const rewardNums = computed<number[]>(() => {
  const groups = rewardType.value === 1 ? data.value?.reward_data?.money_data : data.value?.reward_data?.score_data;
  const nums: number[] = [];
  (groups || []).forEach((g) => {
    (g.nums || []).forEach((n) => {
      const v = Number(n);
      if (v > 0 && !nums.includes(v)) nums.push(v);
    });
  });
  return nums.slice(0, 6);
});
</script>

<template>
  <view v-if="detail" class="page">
    <view class="art-card">
      <text class="art-title">{{ detail.name }}</text>
      <view class="art-meta">
        <text class="meta-item">{{ detail.sendtime_fmt }}</text>
        <text class="meta-item">{{ detail.readcount || 0 }} 阅读</text>
      </view>
      <image v-if="detail.pic" class="art-cover" :src="imgUrl(detail.pic)" mode="widthFix"></image>
      <!-- 富文本：小程序必须用 rich-text 组件 -->
      <view class="art-content">
        <rich-text :nodes="detail.content || ''"></rich-text>
      </view>
    </view>

    <!-- 操作栏 -->
    <view class="action-bar">
      <view class="act-btn" :class="{ on: data && data.iszan === 1 }" @click="onZan">
        <text>{{ data && data.iszan === 1 ? "已赞" : "点赞" }} {{ detail.zan || 0 }}</text>
      </view>
      <view v-if="data && Number(data.reward) === 1" class="act-btn reward" @click="openReward">
        <text>打赏 {{ data.reward_num || 0 }}</text>
      </view>
    </view>

    <!-- 评论区 -->
    <view class="pl-section">
      <text class="pl-title">评论 {{ (data && data.plcount) || 0 }}</text>
      <view v-for="pl in plList" :key="pl.id" class="pl-item">
        <view class="pl-head">
          <image v-if="pl.headimg" class="pl-avatar" :src="imgUrl(pl.headimg)" mode="aspectFill"></image>
          <view v-else class="pl-avatar ph"><text>友</text></view>
          <text class="pl-nick">{{ pl.nickname || "游客" }}</text>
          <text class="pl-time">{{ pl.createtime_fmt }}</text>
        </view>
        <text class="pl-content">{{ pl.content }}</text>
        <view class="pl-acts">
          <text class="pl-act" :class="{ on: pl.iszan === 1 }" @click="onPzan(pl)">赞 {{ pl.zan || 0 }}</text>
          <text v-if="Number(detail.canplrp ?? 1) === 1" class="pl-act" @click="setReply(pl.id)">回复</text>
        </view>
        <view v-if="pl.replylist && pl.replylist.length" class="reply-box">
          <view v-for="(r, ri) in pl.replylist" :key="ri" class="reply-item">
            <text class="reply-nick">{{ r.nickname || "游客" }}：</text>
            <text class="reply-text">{{ r.content }}</text>
          </view>
        </view>
      </view>
      <view v-if="!plList.length && !loading" class="pl-empty"><text>暂无评论，快来抢沙发</text></view>
    </view>

    <!-- 评论输入 -->
    <view v-if="canComment" class="pl-input-bar">
      <input
        v-model="plText"
        class="pl-input"
        :placeholder="replyTarget > 0 ? '回复评论中...' : '写下你的评论'"
        confirm-type="send"
        @confirm="submitPl"
      />
      <view class="pl-send" @click="submitPl"><text>{{ submitting ? "..." : "发送" }}</text></view>
    </view>

    <!-- 打赏弹层 -->
    <view v-if="rewardVisible" class="mask" @click="rewardVisible = false">
      <view class="reward-panel" @click.stop>
        <text class="reward-title">打赏支持作者</text>
        <view class="reward-types">
          <view class="rt" :class="{ on: rewardType === 1 }" @click="rewardType = 1"><text>余额</text></view>
          <view class="rt" :class="{ on: rewardType === 2 }" @click="rewardType = 2"><text>积分</text></view>
        </view>
        <view class="reward-nums">
          <view v-for="n in rewardNums" :key="n" class="rn" :class="{ on: rewardNum === n }" @click="pickNum(n)">
            <text>{{ n }}</text>
          </view>
        </view>
        <view class="reward-btn" @click="doReward"><text>确认打赏</text></view>
      </view>
    </view>
  </view>
</template>

<style scoped>
.page {
  min-height: 100vh;
  background: #f8f8f8;
  padding-bottom: 140rpx;
}
.art-card {
  background: #ffffff;
  padding: 32rpx 28rpx;
}
.art-title {
  font-size: 38rpx;
  font-weight: 700;
  color: #222222;
  line-height: 1.4;
}
.art-meta {
  margin-top: 16rpx;
  display: flex;
}
.meta-item {
  font-size: 24rpx;
  color: #b5b5b5;
  margin-right: 28rpx;
}
.art-cover {
  margin-top: 24rpx;
  width: 100%;
  border-radius: 12rpx;
}
.art-content {
  margin-top: 24rpx;
  font-size: 30rpx;
  color: #333333;
  line-height: 1.7;
  word-break: break-all;
}
.action-bar {
  display: flex;
  justify-content: center;
  padding: 32rpx 0;
}
.act-btn {
  padding: 16rpx 56rpx;
  border-radius: 40rpx;
  background: #ffffff;
  color: #666666;
  font-size: 26rpx;
  border: 1rpx solid #e5e5ea;
  margin: 0 16rpx;
}
.act-btn.on {
  background: #7b7de5;
  border-color: #7b7de5;
  color: #ffffff;
}
.act-btn.reward {
  color: #e08a3c;
  border-color: #f0cfae;
}
.pl-section {
  background: #ffffff;
  margin-top: 20rpx;
  padding: 28rpx;
}
.pl-title {
  font-size: 30rpx;
  font-weight: 600;
  color: #333333;
}
.pl-item {
  margin-top: 28rpx;
  padding-top: 28rpx;
  border-top: 1rpx solid #f2f2f2;
}
.pl-head {
  display: flex;
  align-items: center;
}
.pl-avatar {
  width: 64rpx;
  height: 64rpx;
  border-radius: 50%;
}
.pl-avatar.ph {
  background: #ecebf7;
  color: #7b7de5;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 26rpx;
}
.pl-nick {
  margin-left: 16rpx;
  font-size: 26rpx;
  color: #333333;
}
.pl-time {
  margin-left: auto;
  font-size: 22rpx;
  color: #b5b5b5;
}
.pl-content {
  margin-top: 16rpx;
  font-size: 28rpx;
  color: #333333;
  line-height: 1.6;
}
.pl-acts {
  margin-top: 12rpx;
  display: flex;
}
.pl-act {
  font-size: 24rpx;
  color: #999999;
  margin-right: 40rpx;
}
.pl-act.on {
  color: #7b7de5;
}
.reply-box {
  margin-top: 16rpx;
  background: #f7f7fa;
  border-radius: 12rpx;
  padding: 20rpx;
}
.reply-item {
  font-size: 26rpx;
  color: #555555;
  line-height: 1.7;
}
.reply-nick {
  color: #7b7de5;
}
.pl-empty {
  padding: 60rpx 0;
  text-align: center;
  color: #b5b5b5;
  font-size: 26rpx;
}
.pl-input-bar {
  position: fixed;
  left: 0;
  right: 0;
  bottom: 0;
  background: #ffffff;
  display: flex;
  align-items: center;
  padding: 16rpx 24rpx;
  padding-bottom: calc(16rpx + env(safe-area-inset-bottom));
  box-shadow: 0 -2rpx 12rpx rgba(0, 0, 0, 0.04);
}
.pl-input {
  flex: 1;
  height: 68rpx;
  background: #f2f2f6;
  border-radius: 34rpx;
  padding: 0 28rpx;
  font-size: 26rpx;
}
.pl-send {
  margin-left: 20rpx;
  padding: 16rpx 36rpx;
  background: #7b7de5;
  color: #ffffff;
  border-radius: 34rpx;
  font-size: 26rpx;
}
.mask {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.5);
  display: flex;
  align-items: flex-end;
}
.reward-panel {
  width: 100%;
  background: #ffffff;
  border-radius: 24rpx 24rpx 0 0;
  padding: 36rpx 28rpx calc(36rpx + env(safe-area-inset-bottom));
}
.reward-title {
  font-size: 32rpx;
  font-weight: 600;
  color: #333333;
}
.reward-types {
  margin-top: 28rpx;
  display: flex;
}
.rt {
  padding: 12rpx 40rpx;
  border-radius: 32rpx;
  background: #f2f2f6;
  color: #666666;
  font-size: 26rpx;
  margin-right: 20rpx;
}
.rt.on {
  background: #7b7de5;
  color: #ffffff;
}
.reward-nums {
  margin-top: 28rpx;
  display: flex;
  flex-wrap: wrap;
}
.rn {
  min-width: 120rpx;
  text-align: center;
  padding: 16rpx 0;
  margin: 0 20rpx 20rpx 0;
  background: #f2f2f6;
  border-radius: 12rpx;
  color: #333333;
  font-size: 28rpx;
}
.rn.on {
  background: #ecebf7;
  color: #7b7de5;
  border: 1rpx solid #7b7de5;
}
.reward-btn {
  margin-top: 12rpx;
  padding: 22rpx 0;
  background: #7b7de5;
  color: #ffffff;
  text-align: center;
  border-radius: 44rpx;
  font-size: 30rpx;
}
</style>
