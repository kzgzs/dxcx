<script setup lang="ts">
/**
 * 手机端后台登录页（对照参考项目 admin/index/login.vue 结构）
 * - 账号 + 密码登录，POST /api/adminidx/login {un,pwd}
 * - 成功：token 存 storage 键 adminidx_token，管理员信息存 adminidx_admin，reLaunch 首页
 * - 本期简化：无验证码/短信登录分支（契约仅账号密码）
 */
import { onLoad } from "@dcloudio/uni-app";
import { ref } from "vue";
import { adminLogin, isAdminLoggedIn, saveAdminSession } from "../api";

/** 表单数据 */
const username = ref("");
const password = ref("");
/** 提交中防重复点击 */
const submitting = ref(false);

onLoad((options) => {
  // 免登直达（任务272）：B端后台手机框 iframe 嵌入时携带 mtoken（autologin 换发的手机端 token），
  // 写入 storage 键 adminidx_token 后直接进首页
  const mtoken = String((options && options.mtoken) || "");
  if (mtoken) {
    saveAdminSession(mtoken, null);
    uni.reLaunch({ url: "/admin/index/index" });
    return;
  }
  // 已登录则直接进首页（对照参考页登录成功跳 index 的逻辑）
  if (isAdminLoggedIn()) {
    uni.reLaunch({ url: "/admin/index/index" });
  }
});

/** 提交登录（对照参考页 formSubmit 的空值校验顺序） */
function formSubmit() {
  if (username.value.trim() === "") {
    uni.showToast({ title: "请输入账号", icon: "none" });
    return;
  }
  if (password.value === "") {
    uni.showToast({ title: "请输入密码", icon: "none" });
    return;
  }
  if (submitting.value) return;
  submitting.value = true;
  uni.showLoading({ title: "登录中", mask: true });
  adminLogin(username.value.trim(), password.value)
    .then((res) => {
      // 存 token 与管理员信息（权限显隐本期简化为按 isadmin 全显）
      saveAdminSession(res.token || "", res.admin || {});
      uni.showToast({ title: "登录成功", icon: "success" });
      // 对照参考页：成功后延时跳首页
      setTimeout(() => {
        uni.reLaunch({ url: "/admin/index/index" });
      }, 800);
    })
    .catch((err: { msg?: string }) => {
      uni.showToast({ title: (err && err.msg) || "登录失败，请检查账号密码", icon: "none" });
    })
    .finally(() => {
      uni.hideLoading();
      submitting.value = false;
    });
}
</script>

<template>
  <view class="container">
    <!-- 标题（对照参考页 .title） -->
    <view class="title">管理员登录</view>
    <!-- 表单（对照参考页 .loginform/.form-item 结构） -->
    <view class="loginform">
      <view class="form-item">
        <text class="icon">👤</text>
        <input
          v-model="username"
          type="text"
          class="input"
          placeholder="请输入登录账号"
          placeholder-style="font-size:30rpx;color:#B2B5BE"
        />
      </view>
      <view class="form-item">
        <text class="icon">🔒</text>
        <input
          v-model="password"
          :password="true"
          type="text"
          class="input"
          placeholder="请输入密码"
          placeholder-style="font-size:30rpx;color:#B2B5BE"
        />
      </view>
      <!-- 登录按钮（对照参考页 .form-btn 渐变样式） -->
      <button class="form-btn" :disabled="submitting" @click="formSubmit">登录</button>
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
  border-radius: 5px;
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
