<script setup lang="ts">
import { computed, ref } from "vue";
import { onLoad } from "@dcloudio/uni-app";
import { fetchFatieForm, payLtTop, submitFatie } from "../api/luntan";
import type { LtCategory, LtTopOption } from "../api/luntan";
import { uploadImage } from "../api/common";
import { imgUrl, price2 } from "../../utils/format";
import { goBack } from "../../utils/navigate";

const clist = ref<LtCategory[]>([]);
const canSend = ref(1);
const sendMsg = ref("");
const cid = ref(0);
const cid2 = ref(0);
const content = ref("");
const pics = ref<string[]>([]);
const video = ref("");
const topIndex = ref(-1);
const submitting = ref(false);
const loadFail = ref(false);

onLoad((options) => {
  if (options && options.cid) cid.value = Number(options.cid) || 0;
  void loadForm();
});

async function loadForm() {
  try {
    const res = await fetchFatieForm();
    clist.value = res.clist || [];
    canSend.value = res.can_send;
    sendMsg.value = res.send_msg || "";
  } catch {
    loadFail.value = true;
  }
}

const curCategory = computed<LtCategory | null>(() => clist.value.find((c) => c.id === cid.value) || null);
const children = computed<LtCategory[]>(() => curCategory.value?.children || []);
/** 置顶套餐取最终版块（有二级取二级） */
const topOptions = computed<LtTopOption[]>(() => {
  const cat = children.value.find((c) => c.id === cid2.value) || curCategory.value;
  return cat?.top_options || [];
});

function onCidChange(e: { detail: { value: number } }) {
  const idx = Number(e.detail.value);
  cid.value = clist.value[idx] ? clist.value[idx].id : 0;
  cid2.value = 0;
  topIndex.value = -1;
}

function onCid2Change(e: { detail: { value: number } }) {
  const idx = Number(e.detail.value);
  cid2.value = children.value[idx] ? children.value[idx].id : 0;
  topIndex.value = -1;
}

function choosePics() {
  if (pics.value.length >= 9) {
    uni.showToast({ title: "最多 9 张图片", icon: "none" });
    return;
  }
  uni.chooseImage({
    count: 9 - pics.value.length,
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

function chooseVideo() {
  uni.chooseVideo({
    sourceType: ["album", "camera"],
    success: async (res) => {
      try {
        uni.showLoading({ title: "上传中..." });
        const up = await uploadImage(res.tempFilePath);
        video.value = up.url;
      } catch {
        /* request 已弹 toast */
      } finally {
        uni.hideLoading();
      }
    },
  });
}

async function submit() {
  if (!canSend.value) {
    uni.showToast({ title: sendMsg.value || "暂不可发帖", icon: "none" });
    return;
  }
  if (cid.value <= 0) {
    uni.showToast({ title: "请选择版块", icon: "none" });
    return;
  }
  if (children.value.length && cid2.value <= 0) {
    uni.showToast({ title: "请选择二级版块", icon: "none" });
    return;
  }
  if (!content.value.trim() && !pics.value.length && !video.value) {
    uni.showToast({ title: "请输入帖子内容", icon: "none" });
    return;
  }
  if (submitting.value) return;
  submitting.value = true;
  try {
    const res = await submitFatie({
      cid: cid.value,
      cid2: cid2.value || undefined,
      content: content.value.trim(),
      pics: pics.value.join(","),
      video: video.value || undefined,
      top_index: topIndex.value,
    });
    if (res.need_pay && res.orderid) {
      // 置顶套餐：PAY_MOCK 直接入账
      await payLtTop(res.orderid);
      uni.showToast({ title: "发布成功，置顶已生效", icon: "none" });
    } else {
      uni.showToast({ title: res.msg || "发布成功", icon: "none" });
    }
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
    <view v-if="!canSend" class="warn"><text>{{ sendMsg || "当前账号暂无发帖权限" }}</text></view>

    <view class="form-card">
      <picker :range="clist" range-key="name" @change="onCidChange">
        <view class="form-row picker">
          <text class="row-label">一级版块</text>
          <text class="row-value">{{ curCategory ? curCategory.name : "请选择版块" }}</text>
        </view>
      </picker>
      <picker v-if="children.length" :range="children" range-key="name" @change="onCid2Change">
        <view class="form-row picker">
          <text class="row-label">二级版块</text>
          <text class="row-value">{{ children.find((c) => c.id === cid2)?.name || "请选择二级版块" }}</text>
        </view>
      </picker>
      <view v-if="topOptions.length" class="top-row">
        <text class="row-label">置顶套餐</text>
        <view class="top-opts">
          <view class="top-opt" :class="{ on: topIndex === -1 }" @click="topIndex = -1"><text>不置顶</text></view>
          <view
            v-for="(t, i) in topOptions"
            :key="i"
            class="top-opt"
            :class="{ on: topIndex === i }"
            @click="topIndex = i"
          >
            <text>{{ t.name || t.hour + "小时" }} ￥{{ price2(t.price) }}</text>
          </view>
        </view>
      </view>
    </view>

    <view class="form-card">
      <textarea v-model="content" class="content-input" placeholder="说点什么吧..." :maxlength="2000"></textarea>

      <view class="pics">
        <view v-for="(p, i) in pics" :key="i" class="pic-item">
          <image class="pic" :src="imgUrl(p)" mode="aspectFill"></image>
          <view class="pic-del" @click="removePic(i)"><text>×</text></view>
        </view>
        <view v-if="pics.length < 9" class="pic-add" @click="choosePics"><text>＋图片</text></view>
        <view v-if="!video" class="pic-add" @click="chooseVideo"><text>＋视频</text></view>
        <view v-else class="pic-item">
          <view class="video-tag"><text>▶ 已传视频</text></view>
          <view class="pic-del" @click="video = ''"><text>×</text></view>
        </view>
      </view>
    </view>

    <view class="sub-btn" :class="{ disabled: submitting || !canSend }" @click="submit">
      <text>{{ submitting ? "发布中..." : "发布帖子" }}</text>
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
.warn {
  background: #fff3e6;
  color: #e08a3c;
  border-radius: 12rpx;
  padding: 20rpx 24rpx;
  font-size: 26rpx;
  margin-bottom: 20rpx;
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
.form-row.picker .row-value {
  margin-left: auto;
  color: #666666;
  font-size: 28rpx;
}
.row-label {
  font-size: 28rpx;
  color: #333333;
}
.top-row {
  padding: 28rpx 0;
}
.top-opts {
  margin-top: 16rpx;
  display: flex;
  flex-wrap: wrap;
}
.top-opt {
  padding: 12rpx 24rpx;
  background: #f2f2f6;
  color: #666666;
  border-radius: 28rpx;
  font-size: 24rpx;
  margin: 0 16rpx 16rpx 0;
}
.top-opt.on {
  background: #ecebf7;
  color: #7b7de5;
  border: 1rpx solid #7b7de5;
}
.content-input {
  width: 100%;
  height: 260rpx;
  font-size: 28rpx;
  padding: 24rpx 0;
  box-sizing: border-box;
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
  margin: 0 16rpx 16rpx 0;
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
  margin: 0 16rpx 16rpx 0;
}
.video-tag {
  width: 100%;
  height: 100%;
  border-radius: 12rpx;
  background: #ecebf7;
  color: #7b7de5;
  font-size: 24rpx;
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
