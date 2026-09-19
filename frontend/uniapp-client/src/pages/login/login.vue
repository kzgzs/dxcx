<script setup lang="ts">
import { computed, ref } from "vue";
import { onLoad } from "@dcloudio/uni-app";
import { memberLogin, memberWxLogin } from "../../api/member";
import { fetchDesignerLogin, type DesignerLoginData } from "../../api/designer";
import { useSessionStore } from "../../stores/session";

const session = useSessionStore();

const phone = ref("");
const password = ref("");
const loading = ref(false);
/** 登录成功回跳页（来源页传入） */
const redirect = ref("");

/* ============ 登录页装修消费（无 data 走默认样式） ============ */
const theme = ref<DesignerLoginData>({});

onLoad((options) => {
  const r = options && options.redirect ? decodeURIComponent(options.redirect) : "";
  redirect.value = r;
  // 装修数据静默拉取：失败/无配置均走默认样式
  fetchDesignerLogin()
    .then((data) => {
      if (data && Object.keys(data).length) theme.value = data;
    })
    .catch(() => {
      /* 走默认样式 */
    });
});

/** 页面背景：bgtype=2 且配置了图片走图片，否则纯色 */
const pageStyle = computed(() => {
  const t = theme.value;
  if (t.bgtype === 2 && t.bgimg) {
    return `background-image:url(${t.bgimg});background-size:cover;background-position:center;`;
  }
  return t.bgcolor ? `background:${t.bgcolor};` : "";
});

/** 表单卡片色（默认白） */
const cardStyle = computed(() => (theme.value.cardcolor ? `background:${theme.value.cardcolor};` : ""));

/** 标题对齐（默认居中） */
const titleAlign = computed(() => (theme.value.titletype === "left" ? "flex-start" : "center"));

/** 主按钮：btntype=2 直角矩形，否则圆角胶囊 */
const btnStyle = computed(() => {
  const t = theme.value;
  const radius = t.btntype === 2 ? "12rpx" : "48rpx";
  let s = `border-radius:${radius};`;
  if (t.btncolor) s += `background:${t.btncolor};`;
  if (t.btnwordcolor) s += `color:${t.btnwordcolor};`;
  return s;
});

/** 协议提示行（xytipword 可配，默认「我已阅读并同意」） */
const xyTip = computed(() => theme.value.xytipword || "我已阅读并同意");

function back() {
  if (redirect.value) {
    uni.reLaunch({ url: redirect.value });
  } else {
    uni.reLaunch({ url: "/pages/my/my" });
  }
}

async function doLogin() {
  if (!/^1\d{10}$/.test(phone.value)) {
    uni.showToast({ title: "请输入正确的手机号", icon: "none" });
    return;
  }
  if (!password.value) {
    uni.showToast({ title: "请输入密码", icon: "none" });
    return;
  }
  loading.value = true;
  try {
    const data = await memberLogin(phone.value, password.value);
    session.setSession(data.token, data.member || data.userinfo || {});
    uni.showToast({ title: "登录成功", icon: "success" });
    setTimeout(back, 400);
  } catch {
    // request 封装已弹 toast
  } finally {
    loading.value = false;
  }
}

/** 微信一键登录：H5 暂不支持；MP-WEIXIN 走 wx.login code 换会话 */
function wxLogin() {
  // #ifdef H5
  uni.showToast({ title: "H5 环境暂不支持微信一键登录", icon: "none" });
  // #endif
  // #ifdef MP-WEIXIN
  uni.login({
    provider: "weixin",
    success: async (loginRes) => {
      if (!loginRes.code) {
        uni.showToast({ title: "获取微信 code 失败", icon: "none" });
        return;
      }
      loading.value = true;
      try {
        const data = await memberWxLogin(loginRes.code);
        session.setSession(data.token, data.member || data.userinfo || {});
        uni.showToast({ title: "登录成功", icon: "success" });
        setTimeout(back, 400);
      } catch {
        // request 封装已弹 toast
      } finally {
        loading.value = false;
      }
    },
    fail: () => {
      uni.showToast({ title: "微信登录失败", icon: "none" });
    },
  });
  // #endif
}
</script>

<template>
  <view class="page" :style="pageStyle">
    <view class="brand">
      <image v-if="theme.logo" :src="theme.logo" class="brand-logo" mode="aspectFill" />
      <text v-else class="brand-title">鼎极多端</text>
      <text class="brand-sub" :style="theme.subheadcolor ? `color:${theme.subheadcolor}` : ''">
        {{ theme.subhead || "会员登录" }}
      </text>
    </view>

    <view class="form" :style="cardStyle">
      <view class="form-title-row" :style="`align-items:${titleAlign}`">
        <text class="form-title" :style="theme.titlecolor ? `color:${theme.titlecolor}` : ''">
          {{ theme.title || "欢迎登录" }}
        </text>
      </view>

      <view class="form-item">
        <input
          v-model="phone"
          class="form-input"
          type="number"
          maxlength="11"
          placeholder="请输入手机号"
          placeholder-class="form-placeholder"
        />
      </view>
      <view class="form-item">
        <input
          v-model="password"
          class="form-input"
          password
          placeholder="请输入密码"
          placeholder-class="form-placeholder"
        />
      </view>
      <button class="submit-btn" :style="btnStyle" :disabled="loading" @click="doLogin">
        {{ loading ? "登录中..." : "登 录" }}
      </button>
      <button
        class="wx-btn"
        :style="theme.regpwdbtncolor ? `color:${theme.regpwdbtncolor};border-color:${theme.regpwdbtncolor}` : ''"
        :disabled="loading"
        @click="wxLogin"
      >
        微信一键登录
      </button>

      <view class="xy-row">
        <text class="xy-tip" :style="theme.xytipcolor ? `color:${theme.xytipcolor}` : ''">{{ xyTip }}</text>
        <text class="xy-link" :style="theme.xycolor ? `color:${theme.xycolor}` : ''">《用户协议》《隐私政策》</text>
      </view>
    </view>
  </view>
</template>

<style scoped>
.page {
  min-height: 100vh;
  background: #ffffff;
  padding: 0 56rpx;
}
.brand {
  display: flex;
  flex-direction: column;
  align-items: center;
  padding-top: 140rpx;
}
.brand-logo {
  width: 120rpx;
  height: 120rpx;
  border-radius: 24rpx;
}
.brand-title {
  font-size: 48rpx;
  font-weight: 700;
  color: #7b7de5;
}
.brand-sub {
  margin-top: 16rpx;
  font-size: 26rpx;
  color: #999999;
}
.form {
  margin-top: 72rpx;
  padding: 40rpx 32rpx;
  border-radius: 24rpx;
}
.form-title-row {
  display: flex;
  flex-direction: column;
  margin-bottom: 24rpx;
}
.form-title {
  font-size: 36rpx;
  font-weight: 700;
  color: #333333;
}
.form-item {
  border-bottom: 1rpx solid #eeeeee;
  padding: 24rpx 8rpx;
}
.form-input {
  font-size: 30rpx;
  color: #333333;
}
.form-placeholder {
  color: #bbbbbb;
}
.submit-btn {
  margin-top: 56rpx;
  background: #7b7de5;
  color: #ffffff;
  font-size: 32rpx;
  border-radius: 48rpx;
}
.submit-btn[disabled] {
  opacity: 0.6;
  color: #ffffff;
}
.wx-btn {
  margin-top: 32rpx;
  background: #ffffff;
  color: #09bb07;
  font-size: 30rpx;
  border: 1rpx solid #09bb07;
  border-radius: 48rpx;
}
.xy-row {
  margin-top: 40rpx;
  display: flex;
  justify-content: center;
  flex-wrap: wrap;
  font-size: 22rpx;
}
.xy-tip {
  color: #d8d8d8;
}
.xy-link {
  color: #0256ff;
}
</style>
