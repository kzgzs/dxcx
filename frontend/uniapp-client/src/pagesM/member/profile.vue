<script setup lang="ts">
import { onLoad } from "@dcloudio/uni-app";
import { computed, ref } from "vue";
import { fetchProfile, memberEditProfile, uploadImage } from "../../api/mall";
import type { MemberProfile } from "../../api/types";
import { imgUrl, price2 } from "../../utils/format";
import { useSessionStore } from "../../stores/session";

const session = useSessionStore();

const profile = ref<MemberProfile | null>(null);
const uploading = ref(false);
const saving = ref(false);

/** 资料表单（后端 profile 接口不回传 realname/sex/birthday，本地草稿缓存回显） */
const nickname = ref("");
const realname = ref("");
const sex = ref("");
const birthday = ref("");

const DRAFT_KEY = "dxcx_profile_draft";

const avatarSrc = computed(() => {
  if (profile.value && profile.value.headimg) return imgUrl(profile.value.headimg);
  return "";
});

/** 四项齐全（realname+手机号+sex+birthday）即已完善，后端送分只触发一次 */
const profileComplete = computed(() => {
  const phone = (profile.value && profile.value.phone) || session.member.phone || "";
  return (
    realname.value.trim() !== "" &&
    phone.trim() !== "" &&
    sex.value !== "" &&
    birthday.value !== ""
  );
});

onLoad(async () => {
  readDraft();
  try {
    profile.value = await fetchProfile();
    if (profile.value && profile.value.nickname && !nickname.value) {
      nickname.value = profile.value.nickname;
    }
  } catch {
    profile.value = null;
  }
});

function readDraft() {
  try {
    const raw = uni.getStorageSync(DRAFT_KEY);
    if (raw && typeof raw === "object") {
      const d = raw as Record<string, string>;
      nickname.value = d.nickname || "";
      realname.value = d.realname || "";
      sex.value = d.sex || "";
      birthday.value = d.birthday || "";
    }
  } catch {
    /* 忽略本地缓存异常 */
  }
}

function saveDraft() {
  uni.setStorageSync(DRAFT_KEY, {
    nickname: nickname.value,
    realname: realname.value,
    sex: sex.value,
    birthday: birthday.value,
  });
}

function chooseAvatar() {
  uni.chooseImage({
    count: 1,
    sizeType: ["compressed"],
    sourceType: ["album", "camera"],
    success: (res) => {
      const path = res.tempFilePaths && res.tempFilePaths[0];
      if (!path) return;
      doUpload(path);
    },
  });
}

async function doUpload(filePath: string) {
  if (uploading.value) return;
  uploading.value = true;
  uni.showLoading({ title: "上传中...", mask: true });
  try {
    const res = await uploadImage(filePath);
    await memberEditProfile({ headimg: res.url });
    uni.hideLoading();
    uni.showToast({ title: "头像已保存", icon: "success" });
    if (profile.value) {
      profile.value.headimg = res.url;
    }
  } catch {
    uni.hideLoading();
  } finally {
    uploading.value = false;
  }
}

function selectSex(value: string) {
  sex.value = value;
}

function onBirthdayChange(e: { detail: { value: string } }) {
  birthday.value = e.detail.value;
}

async function save() {
  if (saving.value) return;
  if (!nickname.value.trim()) {
    uni.showToast({ title: "请填写昵称", icon: "none" });
    return;
  }
  saving.value = true;
  try {
    const res = await memberEditProfile({
      nickname: nickname.value.trim(),
      realname: realname.value.trim(),
      sex: sex.value,
      birthday: birthday.value,
    });
    saveDraft();
    if (res.wanshan_score > 0) {
      uni.showToast({ title: "资料已完善，赠送" + res.wanshan_score + "积分", icon: "none", duration: 2500 });
    } else {
      uni.showToast({ title: "保存成功", icon: "success" });
    }
    // 刷新资料（昵称/积分可能变化）
    profile.value = await fetchProfile();
  } catch {
    // request 已弹 toast
  } finally {
    saving.value = false;
  }
}
</script>

<template>
  <view class="page">
    <view class="group">
      <view class="row" @click="chooseAvatar">
        <text class="row-label">头像</text>
        <view class="row-value">
          <image v-if="avatarSrc" class="avatar" :src="avatarSrc" mode="aspectFill" />
          <view v-else class="avatar avatar-text">
            <text>{{ nickname || (profile && profile.nickname) || "会员" }}</text>
          </view>
          <text class="arrow">></text>
        </view>
      </view>
      <view class="row">
        <text class="row-label">昵称</text>
        <view class="row-value input-value">
          <input
            v-model="nickname"
            class="row-input"
            type="text"
            placeholder="请输入昵称"
            placeholder-class="ph"
            maxlength="20"
          />
        </view>
      </view>
      <view class="row">
        <text class="row-label">真实姓名</text>
        <view class="row-value input-value">
          <input
            v-model="realname"
            class="row-input"
            type="text"
            placeholder="请输入真实姓名"
            placeholder-class="ph"
            maxlength="20"
          />
        </view>
      </view>
      <view class="row">
        <text class="row-label">性别</text>
        <view class="row-value">
          <view class="sex-group">
            <text class="sex-chip" :class="{ active: sex === '男' }" @click="selectSex('男')">男</text>
            <text class="sex-chip" :class="{ active: sex === '女' }" @click="selectSex('女')">女</text>
          </view>
        </view>
      </view>
      <view class="row">
        <text class="row-label">生日</text>
        <view class="row-value">
          <picker mode="date" :value="birthday" @change="onBirthdayChange">
            <view class="picker-text">
              <text :class="{ placeholder: !birthday }">{{ birthday || "请选择生日" }}</text>
            </view>
          </picker>
        </view>
      </view>
      <view class="row">
        <text class="row-label">手机号</text>
        <view class="row-value">
          <text class="row-text">{{ (profile && profile.phone) || session.member.phone || "--" }}</text>
        </view>
      </view>
    </view>

    <view class="group">
      <view class="row">
        <text class="row-label">会员等级</text>
        <view class="row-value">
          <text class="row-text">{{ profile && profile.levelid > 0 ? `Lv.${profile.levelid}` : "普通会员" }}</text>
        </view>
      </view>
      <view class="row">
        <text class="row-label">账户余额</text>
        <view class="row-value">
          <text class="row-text">￥{{ profile ? price2(profile.money) : "--" }}</text>
        </view>
      </view>
      <view class="row">
        <text class="row-label">我的积分</text>
        <view class="row-value">
          <text class="row-text">{{ profile ? profile.score : "--" }}</text>
        </view>
      </view>
      <view class="row">
        <text class="row-label">会员ID</text>
        <view class="row-value">
          <text class="row-text">{{ profile ? profile.mid : session.member.id || "--" }}</text>
        </view>
      </view>
    </view>

    <view class="complete-tip">
      <text>{{ profileComplete ? "资料已完善" : "完善资料（姓名+手机号+性别+生日）可获得积分奖励" }}</text>
    </view>

    <view class="save-btn" :class="{ disabled: saving }" @click="save">
      <text>{{ saving ? "保存中..." : "保存资料" }}</text>
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
.group {
  background: #ffffff;
  border-radius: 16rpx;
  overflow: hidden;
  margin-bottom: 24rpx;
}
.row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 28rpx 32rpx;
  border-bottom: 1rpx solid #f2f2f2;
}
.row:last-child {
  border-bottom: none;
}
.row-label {
  font-size: 28rpx;
  color: #333333;
  flex-shrink: 0;
}
.row-value {
  display: flex;
  align-items: center;
}
.input-value {
  flex: 1;
  margin-left: 32rpx;
  justify-content: flex-end;
}
.row-input {
  width: 100%;
  text-align: right;
  font-size: 28rpx;
  color: #333333;
}
.ph {
  color: #b5b5b5;
}
.row-text {
  font-size: 28rpx;
  color: #999999;
}
.avatar {
  width: 88rpx;
  height: 88rpx;
  border-radius: 50%;
  background: #eeeeee;
  margin-right: 16rpx;
}
.avatar-text {
  display: flex;
  align-items: center;
  justify-content: center;
  color: #7b7de5;
  font-size: 24rpx;
}
.arrow {
  margin-left: 12rpx;
  color: #cccccc;
}
.sex-group {
  display: flex;
}
.sex-chip {
  margin-left: 16rpx;
  padding: 8rpx 36rpx;
  border-radius: 28rpx;
  background: #f2f2f5;
  color: #555555;
  font-size: 26rpx;
}
.sex-chip.active {
  background: #ecebfc;
  color: #7b7de5;
  font-weight: 600;
}
.picker-text {
  font-size: 28rpx;
  color: #333333;
}
.picker-text .placeholder {
  color: #b5b5b5;
}
.complete-tip {
  padding: 0 8rpx 24rpx;
  font-size: 22rpx;
  color: #999999;
}
.save-btn {
  padding: 24rpx 0;
  background: #7b7de5;
  color: #ffffff;
  font-size: 32rpx;
  text-align: center;
  border-radius: 48rpx;
}
.save-btn.disabled {
  opacity: 0.6;
}
</style>
