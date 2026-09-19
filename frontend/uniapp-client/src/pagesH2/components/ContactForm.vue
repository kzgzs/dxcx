<script setup lang="ts">
import type { ContactInfo } from "../api/types";

/**
 * 收货信息表单（砍价/秒杀/积分商城下单页共用）
 * - v-model 双向绑定 ContactInfo
 * - validate() 供父组件提交前校验
 */
const props = defineProps<{
  modelValue: ContactInfo;
}>();

const emit = defineEmits<{ (e: "update:modelValue", value: ContactInfo): void }>();

function setField<K extends keyof ContactInfo>(key: K, value: string) {
  emit("update:modelValue", { ...props.modelValue, [key]: value });
}

function onInput(key: keyof ContactInfo, e: unknown) {
  const value = (e as { detail: { value: string } }).detail.value;
  setField(key, value);
}

function isPhone(tel: string): boolean {
  return /^1\d{10}$/.test(tel.trim());
}

/** 表单校验：通过返回 ""，否则返回错误文案 */
function validate(): string {
  if (!props.modelValue.linkman.trim()) return "请填写收货人姓名";
  if (!isPhone(props.modelValue.tel)) return "请填写正确的手机号";
  if (!props.modelValue.area.trim()) return "请填写所在地区";
  if (!props.modelValue.address.trim()) return "请填写详细地址";
  return "";
}

defineExpose({ validate });
</script>

<template>
  <view class="form">
    <view class="form-title"><text>收货信息</text></view>

    <view class="form-row">
      <text class="form-label">收货人</text>
      <input
        class="form-input"
        :value="modelValue.linkman"
        placeholder="请填写收货人姓名"
        placeholder-class="form-ph"
        @input="onInput('linkman', $event)"
      />
    </view>

    <view class="form-row">
      <text class="form-label">手机号</text>
      <input
        class="form-input"
        type="number"
        maxlength="11"
        :value="modelValue.tel"
        placeholder="请填写手机号"
        placeholder-class="form-ph"
        @input="onInput('tel', $event)"
      />
    </view>

    <view class="form-row">
      <text class="form-label">所在地区</text>
      <input
        class="form-input"
        :value="modelValue.area"
        placeholder="如：广东省 深圳市 南山区"
        placeholder-class="form-ph"
        @input="onInput('area', $event)"
      />
    </view>

    <view class="form-row">
      <text class="form-label">详细地址</text>
      <input
        class="form-input"
        :value="modelValue.address"
        placeholder="街道、门牌号等"
        placeholder-class="form-ph"
        @input="onInput('address', $event)"
      />
    </view>

    <view class="form-row">
      <text class="form-label">留言</text>
      <input
        class="form-input"
        :value="modelValue.message"
        placeholder="选填，给商家留言"
        placeholder-class="form-ph"
        @input="onInput('message', $event)"
      />
    </view>
  </view>
</template>

<style scoped>
.form {
  background: #ffffff;
  border-radius: 16rpx;
  padding: 8rpx 24rpx;
}
.form-title {
  padding: 22rpx 0;
  font-size: 28rpx;
  font-weight: 600;
  color: #333333;
  border-bottom: 1rpx solid #f5f5f5;
}
.form-row {
  display: flex;
  align-items: center;
  padding: 24rpx 0;
  border-bottom: 1rpx solid #f7f7f7;
}
.form-row:last-child {
  border-bottom: none;
}
.form-label {
  width: 150rpx;
  font-size: 26rpx;
  color: #666666;
  flex-shrink: 0;
}
.form-input {
  flex: 1;
  font-size: 26rpx;
  color: #333333;
}
.form-ph {
  color: #bbbbbb;
}
</style>
