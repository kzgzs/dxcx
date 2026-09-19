<script setup lang="ts">
/**
 * 手机端后台修改密码（对照参考项目 admin/index/setpwd 的表单职责）
 * - POST /api/adminidx/setpwd {oldpwd,newpwd}
 * - 成功后清除会话回登录页重新登录（安全惯例）
 */
import { onLoad } from "@dcloudio/uni-app";
import { ref } from "vue";
import { clearAdminSession, guardAdmin, setAdminPwd } from "../api";

/** 表单数据 */
const oldpwd = ref("");
const newpwd = ref("");
const repwd = ref("");
/** 提交中防重复点击 */
const submitting = ref(false);

onLoad(() => {
  // 未登录先跳登录页
  guardAdmin();
});

/** 提交修改（空值/长度/两次一致校验） */
function formSubmit() {
  if (oldpwd.value === "") {
    uni.showToast({ title: "请输入原密码", icon: "none" });
    return;
  }
  if (newpwd.value === "") {
    uni.showToast({ title: "请输入新密码", icon: "none" });
    return;
  }
  if (newpwd.value.length < 6) {
    uni.showToast({ title: "新密码至少 6 位", icon: "none" });
    return;
  }
  if (newpwd.value !== repwd.value) {
    uni.showToast({ title: "两次输入的新密码不一致", icon: "none" });
    return;
  }
  if (submitting.value) return;
  submitting.value = true;
  uni.showLoading({ title: "提交中", mask: true });
  setAdminPwd(oldpwd.value, newpwd.value)
    .then(() => {
      uni.hideLoading();
      uni.showToast({ title: "密码修改成功，请重新登录", icon: "success" });
      // 修改成功清会话回登录页
      setTimeout(() => {
        clearAdminSession();
        uni.reLaunch({ url: "/admin/index/login" });
      }, 1200);
    })
    .catch((err: { msg?: string }) => {
      uni.hideLoading();
      uni.showToast({ title: (err && err.msg) || "密码修改失败", icon: "none" });
      submitting.value = false;
    });
}
</script>

<template>
  <view class="container">
    <view class="title">修改密码</view>
    <view class="loginform">
      <view class="form-item">
        <text class="icon">🔒</text>
        <input
          v-model="oldpwd"
          :password="true"
          type="text"
          class="input"
          placeholder="请输入原密码"
          placeholder-style="font-size:30rpx;color:#B2B5BE"
        />
      </view>
      <view class="form-item">
        <text class="icon">🔑</text>
        <input
          v-model="newpwd"
          :password="true"
          type="text"
          class="input"
          placeholder="请输入新密码（至少6位）"
          placeholder-style="font-size:30rpx;color:#B2B5BE"
        />
      </view>
      <view class="form-item">
        <text class="icon">🔑</text>
        <input
          v-model="repwd"
          :password="true"
          type="text"
          class="input"
          placeholder="请再次输入新密码"
          placeholder-style="font-size:30rpx;color:#B2B5BE"
        />
      </view>
      <button class="form-btn" :disabled="submitting" @click="formSubmit">确认修改</button>
    </view>
  </view>
</template>

<style scoped>
page {
  background: #ffffff;
}
.container {
  min-height: 100vh;
  width: 100%;
  background: #ffffff;
  box-sizing: border-box;
}
.title {
  margin: 70rpx 50rpx 50rpx 40rpx;
  height: 60rpx;
  line-height: 60rpx;
  font-size: 48rpx;
  font-weight: bold;
  color: #000000;
}
.loginform {
  width: 100%;
  padding: 0 50rpx;
  background: #ffffff;
  box-sizing: border-box;
}
.form-item {
  display: flex;
  align-items: center;
  width: 100%;
  height: 88rpx;
  line-height: 88rpx;
  border-bottom: 1px solid #f0f3f6;
  margin-top: 20rpx;
}
.form-item .icon {
  width: 44rpx;
  height: 44rpx;
  margin-right: 30rpx;
  font-size: 36rpx;
  text-align: center;
}
.form-item .input {
  flex: 1;
  color: #000000;
}
.form-btn {
  margin-top: 60rpx;
  width: 100%;
  height: 96rpx;
  line-height: 96rpx;
  color: #ffffff;
  font-size: 30rpx;
  border-radius: 48rpx;
  background: linear-gradient(90deg, #7b7de5 0%, rgba(123, 125, 229, 0.8) 100%);
}
</style>
