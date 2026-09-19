<script setup lang="ts">
import { ref } from "vue";
import { onLoad } from "@dcloudio/uni-app";
import { fetchSvUploadForm, submitSvUpload } from "../api/shortvideo";
import type { C2Option } from "../api/article";
import { uploadImage } from "../api/common";
import { imgUrl } from "../../utils/format";
import { goBack } from "../../utils/navigate";

const title = ref("");
const content = ref("");
const cid = ref(0);
const clist = ref<C2Option[]>([]);
const video = ref("");
const pics = ref<string[]>([]);
const submitting = ref(false);

onLoad(() => {
  void loadForm();
});

async function loadForm() {
  try {
    const res = await fetchSvUploadForm();
    clist.value = res.clist || [];
    if (clist.value.length) cid.value = Number(clist.value[0].value) || 0;
  } catch {
    /* request 已弹 toast */
  }
}

function onCidChange(e: { detail: { value: number } }) {
  const idx = Number(e.detail.value);
  cid.value = clist.value[idx] ? Number(clist.value[idx].value) : 0;
}

function chooseVideo() {
  uni.chooseVideo({
    sourceType: ["album", "camera"],
    success: async (res) => {
      try {
        uni.showLoading({ title: "视频上传中..." });
        const up = await uploadImage(res.tempFilePath);
        video.value = up.url;
        uni.showToast({ title: "视频已就绪", icon: "none" });
      } catch {
        /* request 已弹 toast */
      } finally {
        uni.hideLoading();
      }
    },
  });
}

function choosePics() {
  if (pics.value.length >= 3) {
    uni.showToast({ title: "封面最多 3 张", icon: "none" });
    return;
  }
  uni.chooseImage({
    count: 3 - pics.value.length,
    success: async (res) => {
      for (const path of res.tempFilePaths) {
        try {
          const up = await uploadImage(path);
          pics.value.push(up.url);
        } catch {
          /* request 已弹 toast */
        }
      }
    },
  });
}

function removePic(i: number) {
  pics.value.splice(i, 1);
}

async function submit() {
  if (!title.value.trim()) {
    uni.showToast({ title: "请输入标题", icon: "none" });
    return;
  }
  if (!video.value) {
    uni.showToast({ title: "请上传视频", icon: "none" });
    return;
  }
  if (submitting.value) return;
  submitting.value = true;
  try {
    await submitSvUpload({
      title: title.value.trim(),
      video: video.value,
      pics: pics.value.join(","),
      cid: cid.value || undefined,
      content: content.value.trim() || undefined,
    });
    uni.showToast({ title: "上传成功，等待审核", icon: "none" });
    setTimeout(() => goBack(), 800);
  } catch {
    /* request 已弹 toast */
  } finally {
    submitting.value = false;
  }
}
</script>

<template>
  <view class="page">
    <view class="form-card">
      <view class="form-row">
        <text class="row-label">标题</text>
        <input v-model="title" class="row-input" placeholder="视频标题（必填）" />
      </view>
      <picker :range="clist" range-key="label" @change="onCidChange">
        <view class="form-row picker">
          <text class="row-label">分类</text>
          <text class="row-value">{{ clist.find((c) => Number(c.value) === cid)?.label || "请选择分类" }}</text>
        </view>
      </picker>
      <view class="form-row area">
        <text class="row-label">描述</text>
        <textarea v-model="content" class="area-input" placeholder="视频描述（选填）" :maxlength="500"></textarea>
      </view>
    </view>

    <view class="form-card">
      <view class="upload-video" @click="chooseVideo">
        <template v-if="video">
          <video class="preview-video" :src="imgUrl(video)" controls></video>
        </template>
        <template v-else>
          <text class="up-icon">🎬</text>
          <text class="up-text">点击上传视频（必填）</text>
        </template>
      </view>
      <view class="pics">
        <view v-for="(p, i) in pics" :key="i" class="pic-item">
          <image class="pic" :src="imgUrl(p)" mode="aspectFill"></image>
          <view class="pic-del" @click="removePic(i)"><text>×</text></view>
        </view>
        <view v-if="pics.length < 3" class="pic-add" @click="choosePics"><text>＋封面</text></view>
      </view>
    </view>

    <view class="sub-btn" :class="{ disabled: submitting }" @click="submit">
      <text>{{ submitting ? "提交中..." : "提交上传" }}</text>
    </view>
  </view>
</template>

<style scoped>
.page {
  min-height: 100vh;
  background: #f8f8f8;
  padding: 24rpx;
  box-sizing: border-box;
}
.form-card {
  background: #ffffff;
  border-radius: 16rpx;
  padding: 8rpx 28rpx;
  margin-bottom: 20rpx;
}
.form-row {
  display: flex;
  align-items: center;
  padding: 28rpx 0;
  border-bottom: 1rpx solid #f2f2f2;
}
.form-row.area {
  flex-direction: column;
  align-items: flex-start;
}
.row-label {
  font-size: 28rpx;
  color: #333333;
  width: 120rpx;
  flex-shrink: 0;
}
.row-input {
  flex: 1;
  font-size: 28rpx;
}
.form-row.picker .row-value {
  margin-left: auto;
  color: #666666;
  font-size: 28rpx;
}
.area-input {
  width: 100%;
  height: 160rpx;
  margin-top: 16rpx;
  font-size: 26rpx;
}
.upload-video {
  padding: 24rpx 0;
  display: flex;
  flex-direction: column;
  align-items: center;
}
.preview-video {
  width: 100%;
  height: 380rpx;
  border-radius: 12rpx;
}
.up-icon {
  font-size: 72rpx;
}
.up-text {
  margin-top: 16rpx;
  color: #999999;
  font-size: 26rpx;
}
.pics {
  display: flex;
  flex-wrap: wrap;
  padding-bottom: 24rpx;
}
.pic-item {
  position: relative;
  width: 200rpx;
  height: 200rpx;
  margin: 0 16rpx 0 0;
}
.pic {
  width: 100%;
  height: 100%;
  border-radius: 12rpx;
}
.pic-del {
  position: absolute;
  right: -10rpx;
  top: -10rpx;
  width: 40rpx;
  height: 40rpx;
  border-radius: 50%;
  background: rgba(0, 0, 0, 0.55);
  color: #ffffff;
  font-size: 28rpx;
  display: flex;
  align-items: center;
  justify-content: center;
}
.pic-add {
  width: 200rpx;
  height: 200rpx;
  border: 2rpx dashed #d5d5de;
  border-radius: 12rpx;
  color: #999999;
  font-size: 26rpx;
  display: flex;
  align-items: center;
  justify-content: center;
}
.sub-btn {
  margin-top: 40rpx;
  padding: 24rpx 0;
  background: #7b7de5;
  color: #ffffff;
  font-size: 32rpx;
  text-align: center;
  border-radius: 48rpx;
}
.sub-btn.disabled {
  opacity: 0.6;
}
</style>
