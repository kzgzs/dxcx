<script setup lang="ts">
/**
 * 团购订单评价页
 * - 星级评分（1-5）+ 文字评价 + 晒图上传（最多3张，走 /api/upload）
 * - 提交 tuangou/comment/create，content_pic 逗号分隔相对路径
 */
import { ref } from "vue";
import { onLoad } from "@dcloudio/uni-app";
import { tuangouCommentCreate, uploadImage } from "../api";
import { imgUrl } from "../../utils/format";
import { navigateBack } from "../../utils/navigate";

const orderId = ref(0);
const proName = ref("");
const score = ref(5);
const content = ref("");
/** 已上传成功的图片相对路径 */
const uploaded = ref<string[]>([]);
/** 本地预览路径（与 uploaded 一一对应 + 上传中占位） */
const previews = ref<{ local: string; url: string; uploading: boolean }[]>([]);
const submitting = ref(false);

const scoreLabels = ["非常差", "差", "一般", "满意", "非常满意"];

onLoad((options) => {
  orderId.value = Number(options?.id || 0);
  proName.value = decodeURIComponent(options?.name || "");
});

function pickScore(n: number) {
  score.value = n;
}

function chooseImages() {
  const remain = 3 - previews.value.length;
  if (remain <= 0) {
    uni.showToast({ title: "最多上传3张图片", icon: "none" });
    return;
  }
  uni.chooseImage({
    count: remain,
    success: (res) => {
      const paths = Array.isArray(res.tempFilePaths) ? res.tempFilePaths : [res.tempFilePaths];
      paths.forEach((p: string) => void uploadOne(p));
    },
  });
}

async function uploadOne(local: string) {
  const item = { local, url: "", uploading: true };
  previews.value.push(item);
  const idx = previews.value.length - 1;
  try {
    const data = await uploadImage(local);
    previews.value[idx].url = data.url;
    previews.value[idx].uploading = false;
    uploaded.value.push(data.url);
  } catch {
    previews.value.splice(idx, 1);
    uni.showToast({ title: "图片上传失败", icon: "none" });
  }
}

function removeImage(idx: number) {
  const item = previews.value[idx];
  if (item && item.url !== "") {
    uploaded.value = uploaded.value.filter((u) => u !== item.url);
  }
  previews.value.splice(idx, 1);
}

async function submit() {
  if (submitting.value) return;
  if (previews.value.some((p) => p.uploading)) {
    uni.showToast({ title: "图片上传中，请稍候", icon: "none" });
    return;
  }
  if (content.value.trim() === "") {
    uni.showToast({ title: "请填写评价内容", icon: "none" });
    return;
  }
  submitting.value = true;
  try {
    await tuangouCommentCreate({
      orderid: orderId.value,
      score: score.value,
      content: content.value.trim(),
      content_pic: uploaded.value.join(","),
    });
    uni.showToast({ title: "评价成功", icon: "success" });
    setTimeout(() => {
      navigateBack();
    }, 800);
  } catch {
    // request 层已提示
  } finally {
    submitting.value = false;
  }
}
</script>

<template>
  <view class="page">
    <view v-if="proName !== ''" class="pro-tip"><text>评价商品：{{ proName }}</text></view>

    <!-- 星级 -->
    <view class="card">
      <view class="card-title"><text>商品评分</text></view>
      <view class="star-row">
        <text
          v-for="n in 5"
          :key="n"
          class="star"
          :class="{ active: n <= score }"
          @click="pickScore(n)"
        >★</text>
        <text class="star-label">{{ scoreLabels[score - 1] }}</text>
      </view>
    </view>

    <!-- 内容 -->
    <view class="card">
      <view class="card-title"><text>评价内容</text></view>
      <textarea v-model="content" class="form-textarea" placeholder="分享一下你的使用体验吧～" maxlength="500" />

      <!-- 图片 -->
      <view class="pic-row">
        <view v-for="(p, i) in previews" :key="i" class="pic-item">
          <image class="pic-img" :src="imgUrl(p.local)" mode="aspectFill" />
          <view v-if="p.uploading" class="pic-mask"><text>上传中</text></view>
          <view class="pic-del" @click="removeImage(i)"><text>×</text></view>
        </view>
        <view v-if="previews.length < 3" class="pic-add" @click="chooseImages"><text>+</text></view>
      </view>
      <text class="pic-tip">最多上传3张图片</text>
    </view>

    <view class="footer-holder"></view>
    <view class="footer">
      <view class="footer-btn" :class="{ disabled: submitting }" @click="submit"><text>提交评价</text></view>
    </view>
  </view>
</template>

<style scoped>
.page {
  min-height: 100vh;
  background: #f8f8f8;
  padding: 20rpx 24rpx 0;
  box-sizing: border-box;
}
.pro-tip {
  padding: 8rpx 8rpx 20rpx;
  font-size: 24rpx;
  color: #999999;
}
.card {
  background: #ffffff;
  border-radius: 16rpx;
  padding: 28rpx 24rpx;
  margin-bottom: 20rpx;
}
.card-title {
  font-size: 28rpx;
  color: #333333;
  font-weight: 600;
  margin-bottom: 20rpx;
}
.star-row {
  display: flex;
  align-items: center;
}
.star {
  font-size: 56rpx;
  color: #e3e3e8;
  margin-right: 16rpx;
}
.star.active {
  color: #ffb400;
}
.star-label {
  margin-left: 12rpx;
  font-size: 24rpx;
  color: #ff8a3d;
}
.form-textarea {
  width: 100%;
  height: 200rpx;
  background: #f7f7fa;
  border-radius: 12rpx;
  padding: 16rpx;
  font-size: 26rpx;
  color: #333333;
  box-sizing: border-box;
}
.pic-row {
  display: flex;
  flex-wrap: wrap;
  margin-top: 20rpx;
}
.pic-item {
  position: relative;
  width: 160rpx;
  height: 160rpx;
  margin: 0 16rpx 16rpx 0;
}
.pic-img {
  width: 160rpx;
  height: 160rpx;
  border-radius: 12rpx;
  background: #f2f2f5;
}
.pic-mask {
  position: absolute;
  left: 0;
  top: 0;
  width: 160rpx;
  height: 160rpx;
  border-radius: 12rpx;
  background: rgba(0, 0, 0, 0.4);
  display: flex;
  align-items: center;
  justify-content: center;
  color: #ffffff;
  font-size: 22rpx;
}
.pic-del {
  position: absolute;
  right: -10rpx;
  top: -10rpx;
  width: 40rpx;
  height: 40rpx;
  border-radius: 50%;
  background: #ff4d4f;
  color: #ffffff;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 28rpx;
}
.pic-add {
  width: 160rpx;
  height: 160rpx;
  border: 1rpx dashed #d9d9e3;
  border-radius: 12rpx;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #c5c5cf;
  font-size: 56rpx;
}
.pic-tip {
  font-size: 22rpx;
  color: #b5b5b5;
}
.footer-holder {
  height: 140rpx;
}
.footer {
  position: fixed;
  left: 0;
  right: 0;
  bottom: 0;
  background: #ffffff;
  padding: 16rpx 24rpx;
  box-shadow: 0 -2rpx 12rpx rgba(0, 0, 0, 0.04);
}
.footer-btn {
  height: 80rpx;
  border-radius: 40rpx;
  background: linear-gradient(90deg, #9b9df0, #7b7de5);
  color: #ffffff;
  font-size: 30rpx;
  font-weight: 600;
  display: flex;
  align-items: center;
  justify-content: center;
}
.footer-btn.disabled {
  opacity: 0.6;
}
</style>
