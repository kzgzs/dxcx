<script setup lang="ts">
/** 论坛帖子卡片（index/list/myfatie 复用） */
import type { LtPost } from "../api/luntan";
import { imgUrl, price2 } from "../../utils/format";

const props = defineProps<{ post: LtPost }>();
const emit = defineEmits<{ (e: "tap", id: number): void }>();

function preview(src: string) {
  uni.previewImage({
    urls: (props.post.pics || []).map((p) => imgUrl(p)),
    current: imgUrl(src),
  });
}

function onTap() {
  emit("tap", props.post.id);
}
</script>

<template>
  <view class="post-card" @click="onTap">
    <view class="post-head">
      <image v-if="post.headimg" class="avatar" :src="imgUrl(post.headimg)" mode="aspectFill"></image>
      <view v-else class="avatar ph"><text>友</text></view>
      <view class="head-info">
        <text class="nick">{{ post.nickname || "匿名用户" }}</text>
        <text class="time">{{ post.showtime }}</text>
      </view>
      <view v-if="Number(post.top) === 1" class="top-tag"><text>置顶</text></view>
    </view>

    <text v-if="post.content" class="post-content">{{ post.content }}</text>

    <view v-if="post.pics && post.pics.length" class="pics">
      <image
        v-for="(p, i) in post.pics"
        :key="i"
        class="pic"
        :class="{ multi: post.pics.length > 1 }"
        :src="imgUrl(p)"
        mode="aspectFill"
        @click.stop="preview(p)"
      ></image>
    </view>
    <view v-if="post.video" class="video-tip"><text>▶ 含视频，点击查看详情播放</text></view>

    <view v-if="Number(post.pronum || 0) > 0" class="goods" @click.stop>
      <image v-if="post.propic" class="goods-pic" :src="imgUrl(post.propic)" mode="aspectFill"></image>
      <text class="goods-name">{{ post.proname }}</text>
      <text class="goods-price">￥{{ price2(post.proprice) }}</text>
    </view>

    <view class="post-foot">
      <text class="foot-item">👍 {{ post.zan || 0 }}</text>
      <text class="foot-item">💬 {{ post.plcount || 0 }}</text>
    </view>
  </view>
</template>

<style scoped>
.post-card {
  background: #ffffff;
  border-radius: 16rpx;
  padding: 28rpx;
  margin-bottom: 20rpx;
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
.top-tag {
  margin-left: auto;
  padding: 6rpx 20rpx;
  background: #fff3e6;
  color: #e08a3c;
  border-radius: 20rpx;
  font-size: 22rpx;
}
.post-content {
  margin-top: 20rpx;
  font-size: 28rpx;
  color: #333333;
  line-height: 1.6;
  display: -webkit-box;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 5;
  overflow: hidden;
}
.pics {
  margin-top: 20rpx;
  display: flex;
  flex-wrap: wrap;
}
.pic {
  width: 100%;
  height: 360rpx;
  border-radius: 12rpx;
  margin-bottom: 8rpx;
}
.pic.multi {
  width: 212rpx;
  height: 212rpx;
  margin-right: 8rpx;
}
.video-tip {
  margin-top: 16rpx;
  font-size: 24rpx;
  color: #7b7de5;
}
.goods {
  margin-top: 20rpx;
  display: flex;
  align-items: center;
  background: #f7f7fa;
  border-radius: 12rpx;
  padding: 16rpx;
}
.goods-pic {
  width: 80rpx;
  height: 80rpx;
  border-radius: 8rpx;
}
.goods-name {
  flex: 1;
  margin-left: 16rpx;
  font-size: 26rpx;
  color: #333333;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.goods-price {
  font-size: 28rpx;
  color: #e64340;
}
.post-foot {
  margin-top: 20rpx;
  display: flex;
}
.foot-item {
  font-size: 24rpx;
  color: #999999;
  margin-right: 48rpx;
}
</style>
