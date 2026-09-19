<script setup lang="ts">
import { onLoad } from "@dcloudio/uni-app";
import { ref } from "vue";
import { depositAdd, depositOrderDetailByBid } from "../api/deposit";
import { uploadImage } from "../../api/mall";
import { imgUrl } from "../../utils/format";

/** 寄存开关（一期 bid=0 主商家口径） */
const open = ref(false);
const setTime = ref("");
const setLoaded = ref(false);

const name = ref("");
const num = ref("");
const linkman = ref("");
const tel = ref("");
const message = ref("");
const pic = ref("");
const uploading = ref(false);
const submitting = ref(false);

onLoad(() => {
  depositOrderDetailByBid(0, 1, 1)
    .then((res) => {
      open.value = res.sysset.status === 1;
      setTime.value = res.sysset.time || "";
    })
    .catch(() => {
      open.value = false;
    })
    .finally(() => {
      setLoaded.value = true;
    });
});

function choosePic() {
  uni.chooseImage({
    count: 1,
    success: (res) => {
      const filePath = res.tempFilePaths[0];
      if (!filePath) return;
      uploading.value = true;
      uploadImage(filePath)
        .then((r) => {
          pic.value = r.url;
        })
        .catch(() => {
          /* 上传失败 toast 已提示 */
        })
        .finally(() => {
          uploading.value = false;
        });
    },
  });
}

function clearPic() {
  pic.value = "";
}

function submit() {
  if (submitting.value) return;
  if (!open.value) {
    uni.showToast({ title: "当前不能寄存", icon: "none" });
    return;
  }
  if (!name.value.trim()) {
    uni.showToast({ title: "请填写物品名称", icon: "none" });
    return;
  }
  const n = Number(num.value);
  if (!n || n <= 0) {
    uni.showToast({ title: "请填写寄存数量", icon: "none" });
    return;
  }
  if (!linkman.value.trim()) {
    uni.showToast({ title: "请填写联系人", icon: "none" });
    return;
  }
  if (!/^1[3-9]\d{9}$/.test(tel.value.trim())) {
    uni.showToast({ title: "请检查手机号格式", icon: "none" });
    return;
  }
  submitting.value = true;
  depositAdd({
    name: name.value.trim(),
    num: n,
    linkman: linkman.value.trim(),
    tel: tel.value.trim(),
    message: message.value.trim(),
    pic: pic.value,
  })
    .then(() => {
      uni.showToast({ title: "提交成功，请等待审核", icon: "success" });
      setTimeout(() => {
        uni.redirectTo({ url: "/restaurant/deposit/orderlog" });
      }, 800);
    })
    .catch(() => {
      /* 后端 toast 已提示 */
    })
    .finally(() => {
      submitting.value = false;
    });
}
</script>

<template>
  <view class="page">
    <view v-if="setLoaded && !open" class="empty"><text>商家暂未开启寄存服务</text></view>

    <template v-if="open">
      <view class="tip-bar" v-if="setTime"><text>寄存时间：{{ setTime }}</text></view>

      <view class="card">
        <view class="form-line">
          <text class="form-label">物品名称</text>
          <input class="form-input" v-model="name" placeholder="如：红酒/白酒" maxlength="30" />
        </view>
        <view class="form-line">
          <text class="form-label">数量</text>
          <input class="form-input" v-model="num" type="number" placeholder="请填写数量" maxlength="5" />
        </view>
        <view class="form-line">
          <text class="form-label">联系人</text>
          <input class="form-input" v-model="linkman" placeholder="请填写联系人" maxlength="20" />
        </view>
        <view class="form-line">
          <text class="form-label">手机号</text>
          <input class="form-input" v-model="tel" type="number" placeholder="请填写手机号" maxlength="11" />
        </view>
        <view class="form-line">
          <text class="form-label">留言</text>
          <input class="form-input" v-model="message" placeholder="选填" maxlength="100" />
        </view>
      </view>

      <view class="card">
        <text class="card-title">物品照片（选填）</text>
        <view class="pic-row">
          <view v-if="pic" class="pic-box">
            <image class="pic-img" :src="imgUrl(pic)" mode="aspectFill" />
            <view class="pic-del" @click="clearPic"><text>删除</text></view>
          </view>
          <view v-else-if="!uploading" class="pic-add" @click="choosePic"><text>上传照片</text></view>
          <view v-else class="pic-add"><text>上传中...</text></view>
        </view>
      </view>

      <view class="submit-bar">
        <view class="submit-btn" :class="{ disabled: submitting || uploading }" @click="submit">
          <text>{{ submitting ? "提交中..." : "提交寄存申请" }}</text>
        </view>
      </view>
    </template>
  </view>
</template>

<style scoped>
.page {
  min-height: 100vh;
  background: #f8f8f8;
  padding: 24rpx 0 180rpx;
}
.empty {
  padding: 160rpx 0;
  text-align: center;
  color: #999999;
  font-size: 28rpx;
}
.tip-bar {
  margin: 0 24rpx 24rpx;
  padding: 16rpx 24rpx;
  background: rgba(123, 125, 229, 0.08);
  border-radius: 12rpx;
  font-size: 24rpx;
  color: #7b7de5;
}
.card {
  margin: 0 24rpx 24rpx;
  padding: 8rpx 28rpx;
  background: #ffffff;
  border-radius: 16rpx;
}
.card-title {
  display: block;
  padding: 20rpx 0 8rpx;
  font-size: 28rpx;
  font-weight: 600;
  color: #333333;
}
.form-line {
  display: flex;
  align-items: center;
  padding: 24rpx 0;
  border-bottom: 1rpx solid #f2f2f2;
}
.form-line:last-child {
  border-bottom: none;
}
.form-label {
  width: 160rpx;
  font-size: 26rpx;
  color: #666666;
  flex-shrink: 0;
}
.form-input {
  flex: 1;
  font-size: 28rpx;
  color: #333333;
}
.pic-row {
  display: flex;
  padding: 16rpx 0 24rpx;
}
.pic-box {
  position: relative;
  width: 180rpx;
  height: 180rpx;
}
.pic-img {
  width: 180rpx;
  height: 180rpx;
  border-radius: 12rpx;
}
.pic-del {
  position: absolute;
  right: 0;
  bottom: 0;
  padding: 6rpx 16rpx;
  background: rgba(0, 0, 0, 0.5);
  color: #ffffff;
  font-size: 20rpx;
  border-radius: 12rpx 0 12rpx 0;
}
.pic-add {
  width: 180rpx;
  height: 180rpx;
  border: 2rpx dashed #c5c6f0;
  border-radius: 12rpx;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #7b7de5;
  font-size: 26rpx;
}
.submit-bar {
  position: fixed;
  left: 0;
  right: 0;
  bottom: 0;
  padding: 20rpx 32rpx calc(20rpx + env(safe-area-inset-bottom));
  background: #ffffff;
  box-shadow: 0 -4rpx 16rpx rgba(0, 0, 0, 0.04);
}
.submit-btn {
  padding: 22rpx 0;
  border-radius: 44rpx;
  background: #7b7de5;
  color: #ffffff;
  font-size: 30rpx;
  text-align: center;
}
.submit-btn.disabled {
  opacity: 0.6;
}
</style>
