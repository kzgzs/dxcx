<script setup lang="ts">
import { computed, ref } from "vue";
import { onLoad, onReachBottom } from "@dcloudio/uni-app";
import {
  fetchLtDetail,
  ltDelPinglun,
  ltDelPlReply,
  ltDelTie,
  ltPzan,
  ltSubPinglun,
  ltZan,
} from "../api/luntan";
import type { LtComment, LtDetailResult } from "../api/luntan";
import { imgUrl } from "../../utils/format";
import { goBack } from "../../utils/navigate";

const id = ref(0);
const data = ref<LtDetailResult | null>(null);
const page = ref(1);
const loading = ref(false);
const hasMore = ref(true);
const plText = ref("");
const replyTarget = ref(0);
const submitting = ref(false);

const detail = computed(() => data.value?.detail || null);
/** 当前登录会员 id（后端下发，用于判断是否本人可删） */
const myMid = computed(() => data.value?.mid || 0);
const canComment = computed(() => Number(data.value?.pinglunstatus ?? 1) === 1);

onLoad((options) => {
  id.value = Number(options && options.id) || 0;
  if (id.value > 0) void load(true);
});

async function load(reset: boolean) {
  if (loading.value) return;
  loading.value = true;
  try {
    const p = reset ? 1 : page.value + 1;
    const res = await fetchLtDetail(id.value, p);
    if (reset) {
      data.value = res;
    } else if (data.value) {
      data.value.datalist = data.value.datalist.concat(res.datalist || []);
    }
    page.value = p;
    hasMore.value = (res.datalist || []).length >= 10;
  } catch {
    /* request 已弹 toast */
  } finally {
    loading.value = false;
  }
}

async function onZan() {
  try {
    const res = await ltZan(id.value);
    if (data.value && data.value.detail) {
      data.value.iszan = res.type;
      data.value.detail.zan = res.zancount;
    }
  } catch {
    /* 忽略 */
  }
}

async function onPzan(item: LtComment) {
  try {
    await ltPzan(item.id);
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
      await ltSubPinglun(id.value, 1, content, replyTarget.value);
    } else {
      await ltSubPinglun(id.value, 0, content);
    }
    plText.value = "";
    replyTarget.value = 0;
    uni.showToast({ title: "回复成功", icon: "none" });
    void load(true);
  } catch {
    /* request 已弹 toast */
  } finally {
    submitting.value = false;
  }
}

function delTie() {
  uni.showModal({
    title: "提示",
    content: "确定删除该帖子？",
    success: async (r) => {
      if (!r.confirm) return;
      try {
        await ltDelTie(id.value);
        uni.showToast({ title: "已删除", icon: "none" });
        setTimeout(() => goBack(), 600);
      } catch {
        /* request 已弹 toast */
      }
    },
  });
}

function delComment(item: LtComment) {
  uni.showModal({
    title: "提示",
    content: "确定删除该评论？",
    success: async (r) => {
      if (!r.confirm) return;
      try {
        await ltDelPinglun(item.id);
        void load(true);
      } catch {
        /* request 已弹 toast */
      }
    },
  });
}

function delReply(rid: number | undefined) {
  if (!rid) return;
  uni.showModal({
    title: "提示",
    content: "确定删除该回帖？",
    success: async (r) => {
      if (!r.confirm) return;
      try {
        await ltDelPlReply(rid);
        void load(true);
      } catch {
        /* request 已弹 toast */
      }
    },
  });
}

function preview(src: string) {
  uni.previewImage({
    urls: ((detail.value && detail.value.pics) || []).map((p) => imgUrl(p)),
    current: imgUrl(src),
  });
}

onReachBottom(() => {
  if (hasMore.value && !loading.value) void load(false);
});
</script>

<template>
  <view v-if="detail" class="page">
    <!-- 帖子主体 -->
    <view class="post-card">
      <view class="post-head">
        <image v-if="detail.headimg" class="avatar" :src="imgUrl(detail.headimg)" mode="aspectFill"></image>
        <view v-else class="avatar ph"><text>友</text></view>
        <view class="head-info">
          <text class="nick">{{ detail.nickname || "匿名用户" }}</text>
          <text class="time">{{ detail.showtime }}</text>
        </view>
        <text v-if="myMid > 0 && detail.mid === myMid" class="del-link" @click="delTie">删除</text>
      </view>
      <text v-if="detail.content" class="post-content">{{ detail.content }}</text>
      <view v-if="detail.pics && detail.pics.length" class="pics">
        <image
          v-for="(p, i) in detail.pics"
          :key="i"
          class="pic"
          :src="imgUrl(p)"
          mode="widthFix"
          @click="preview(p)"
        ></image>
      </view>
      <video
        v-if="detail.video"
        class="post-video"
        :src="imgUrl(detail.video)"
        controls
        :show-fullscreen-btn="true"
      ></video>
      <view class="post-foot">
        <view class="zan-btn" :class="{ on: data && data.iszan === 1 }" @click="onZan">
          <text>{{ data && data.iszan === 1 ? "已赞" : "点赞" }} {{ detail.zan || 0 }}</text>
        </view>
      </view>
    </view>

    <!-- 评论区 -->
    <view class="pl-section">
      <text class="pl-title">全部回帖 {{ (data && data.plcount) || 0 }}</text>
      <view v-for="pl in (data && data.datalist) || []" :key="pl.id" class="pl-item">
        <view class="pl-head">
          <image v-if="pl.headimg" class="pl-avatar" :src="imgUrl(pl.headimg)" mode="aspectFill"></image>
          <view v-else class="pl-avatar ph"><text>友</text></view>
          <text class="pl-nick">{{ pl.nickname || "游客" }}</text>
          <text class="pl-time">{{ pl.createtime_fmt }}</text>
        </view>
        <text class="pl-content">{{ pl.content }}</text>
        <view class="pl-acts">
          <text class="pl-act" :class="{ on: pl.iszan === 1 }" @click="onPzan(pl)">赞 {{ pl.zan || 0 }}</text>
          <text v-if="canComment" class="pl-act" @click="setReply(pl.id)">回复</text>
          <text v-if="myMid > 0 && pl.mid === myMid" class="pl-act danger" @click="delComment(pl)">删除</text>
        </view>
        <view v-if="pl.replylist && pl.replylist.length" class="reply-box">
          <view v-for="r in pl.replylist" :key="r.id" class="reply-item">
            <text class="reply-nick">{{ r.nickname || "游客" }}：</text>
            <text class="reply-text">{{ r.content }}</text>
            <text v-if="myMid > 0" class="reply-del" @click="delReply(r.id)">删除</text>
          </view>
        </view>
      </view>
      <view v-if="!(data && data.datalist.length) && !loading" class="pl-empty"><text>暂无回帖</text></view>
      <view v-if="data && data.datalist.length" class="list-tip">
        <text>{{ loading ? "加载中..." : hasMore ? "上拉加载更多" : "没有更多了" }}</text>
      </view>
    </view>

    <!-- 输入栏 -->
    <view v-if="canComment" class="pl-input-bar">
      <input
        v-model="plText"
        class="pl-input"
        :placeholder="replyTarget > 0 ? '回复回帖中...' : '写下你的回帖'"
        confirm-type="send"
        @confirm="submitPl"
      />
      <view class="pl-send" @click="submitPl"><text>{{ submitting ? "..." : "发送" }}</text></view>
    </view>
  </view>
</template>

<style scoped>
.page {
  min-height: 100vh;
  background: #f8f8f8;
  padding: 24rpx 24rpx 140rpx;
}
.post-card {
  background: #ffffff;
  border-radius: 16rpx;
  padding: 28rpx;
}
.post-head {
  display: flex;
  align-items: center;
}
.avatar {
  width: 72rpx;
  height: 72rpx;
  border-radius: 50%;
}
.avatar.ph {
  background: #ecebf7;
  color: #7b7de5;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 28rpx;
}
.head-info {
  margin-left: 16rpx;
  display: flex;
  flex-direction: column;
}
.nick {
  font-size: 28rpx;
  color: #333333;
  font-weight: 600;
}
.time {
  font-size: 22rpx;
  color: #b5b5b5;
}
.del-link {
  margin-left: auto;
  font-size: 24rpx;
  color: #999999;
}
.post-content {
  margin-top: 20rpx;
  font-size: 30rpx;
  color: #333333;
  line-height: 1.7;
  white-space: pre-wrap;
}
.pics {
  margin-top: 20rpx;
}
.pic {
  width: 100%;
  border-radius: 12rpx;
  margin-bottom: 12rpx;
}
.post-video {
  margin-top: 20rpx;
  width: 100%;
  border-radius: 12rpx;
}
.post-foot {
  margin-top: 24rpx;
}
.zan-btn {
  display: inline-block;
  padding: 12rpx 44rpx;
  border-radius: 36rpx;
  border: 1rpx solid #e5e5ea;
  color: #666666;
  font-size: 26rpx;
}
.zan-btn.on {
  background: #7b7de5;
  border-color: #7b7de5;
  color: #ffffff;
}
.pl-section {
  background: #ffffff;
  margin-top: 20rpx;
  border-radius: 16rpx;
  padding: 28rpx;
}
.pl-title {
  font-size: 30rpx;
  font-weight: 600;
  color: #333333;
}
.pl-item {
  margin-top: 24rpx;
  padding-top: 24rpx;
  border-top: 1rpx solid #f2f2f2;
}
.pl-head {
  display: flex;
  align-items: center;
}
.pl-avatar {
  width: 60rpx;
  height: 60rpx;
  border-radius: 50%;
}
.pl-avatar.ph {
  background: #ecebf7;
  color: #7b7de5;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 24rpx;
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
  margin-top: 14rpx;
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
.pl-act.danger {
  color: #e64340;
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
.reply-del {
  margin-left: 16rpx;
  font-size: 22rpx;
  color: #b5b5b5;
}
.pl-empty {
  padding: 60rpx 0;
  text-align: center;
  color: #b5b5b5;
  font-size: 26rpx;
}
.list-tip {
  text-align: center;
  font-size: 24rpx;
  color: #b5b5b5;
  padding: 20rpx 0;
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
</style>
