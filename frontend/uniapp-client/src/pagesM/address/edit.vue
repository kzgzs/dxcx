<script setup lang="ts">
import { reactive, ref } from "vue";
import { onLoad } from "@dcloudio/uni-app";
import { addressAdd, addressEdit, fetchAddressList } from "../../api/mall";

const id = ref(0);
const saving = ref(false);

const form = reactive({
  name: "",
  phone: "",
  province: "",
  city: "",
  district: "",
  detail: "",
  is_default: 0,
});

onLoad(async (options) => {
  id.value = Number(options && options.id) || 0;
  if (id.value > 0) {
    uni.setNavigationBarTitle({ title: "编辑地址" });
    try {
      const list = (await fetchAddressList()) || [];
      const row = list.find((x) => x.id === id.value);
      if (row) {
        form.name = row.name;
        form.phone = row.phone;
        form.province = row.province;
        form.city = row.city;
        form.district = row.district;
        form.detail = row.detail;
        form.is_default = row.is_default;
      }
    } catch {
      // request 已弹 toast
    }
  } else {
    uni.setNavigationBarTitle({ title: "新增地址" });
  }
});

function onDefaultChange(e: Event) {
  form.is_default = (e as unknown as { detail: { value: boolean } }).detail.value ? 1 : 0;
}

async function save() {
  if (saving.value) return;
  if (!form.name.trim()) {
    uni.showToast({ title: "请填写收货人姓名", icon: "none" });
    return;
  }
  if (!/^1\d{10}$/.test(form.phone.trim())) {
    uni.showToast({ title: "手机号格式不正确", icon: "none" });
    return;
  }
  if (!form.province.trim() || !form.city.trim()) {
    uni.showToast({ title: "请填写省/市", icon: "none" });
    return;
  }
  if (!form.detail.trim()) {
    uni.showToast({ title: "请填写详细地址", icon: "none" });
    return;
  }
  saving.value = true;
  const payload = {
    name: form.name.trim(),
    phone: form.phone.trim(),
    province: form.province.trim(),
    city: form.city.trim(),
    district: form.district.trim(),
    detail: form.detail.trim(),
    is_default: form.is_default,
  };
  try {
    if (id.value > 0) {
      await addressEdit(id.value, payload);
    } else {
      await addressAdd(payload);
    }
    uni.showToast({ title: "保存成功", icon: "success" });
    setTimeout(() => uni.navigateBack({ delta: 1 }), 500);
  } catch {
    // request 已弹 toast
  } finally {
    saving.value = false;
  }
}
</script>

<template>
  <view class="page">
    <view class="form-card">
      <view class="form-row">
        <text class="label">收货人</text>
        <input v-model="form.name" class="input" placeholder="姓名" placeholder-class="ph" />
      </view>
      <view class="form-row">
        <text class="label">手机号</text>
        <input v-model="form.phone" class="input" type="number" maxlength="11" placeholder="11位手机号" placeholder-class="ph" />
      </view>
      <view class="form-row">
        <text class="label">省份</text>
        <input v-model="form.province" class="input" placeholder="如：广东省" placeholder-class="ph" />
      </view>
      <view class="form-row">
        <text class="label">城市</text>
        <input v-model="form.city" class="input" placeholder="如：深圳市" placeholder-class="ph" />
      </view>
      <view class="form-row">
        <text class="label">区/县</text>
        <input v-model="form.district" class="input" placeholder="如：南山区" placeholder-class="ph" />
      </view>
      <view class="form-row">
        <text class="label">详细地址</text>
        <input v-model="form.detail" class="input" placeholder="街道、门牌号等" placeholder-class="ph" />
      </view>
      <view class="form-row">
        <text class="label">设为默认</text>
        <switch :checked="form.is_default === 1" color="#7b7de5" style="transform: scale(0.8)" @change="onDefaultChange" />
      </view>
    </view>

    <view class="save-btn" :class="{ disabled: saving }" @click="save">
      <text>{{ saving ? "保存中..." : "保存" }}</text>
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
  padding: 0 28rpx;
}
.form-row {
  display: flex;
  align-items: center;
  padding: 28rpx 0;
  border-bottom: 1rpx solid #f5f5f5;
}
.form-row:last-child {
  border-bottom: none;
}
.label {
  width: 160rpx;
  font-size: 28rpx;
  color: #333333;
  flex-shrink: 0;
}
.input {
  flex: 1;
  font-size: 28rpx;
  color: #333333;
}
.ph {
  color: #b5b5b5;
}
.save-btn {
  margin-top: 48rpx;
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
