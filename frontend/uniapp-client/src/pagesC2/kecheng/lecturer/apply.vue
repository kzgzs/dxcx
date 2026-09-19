<template>
	<view class="ap-page">
		<!-- 状态提示 -->
		<view v-if="checkstatus === 1" class="ap-status ap-status--ok">您已成功入驻，可修改资料</view>
		<view v-else-if="checkstatus === 0" class="ap-status ap-status--wait">您已提交申请，等待审核中</view>
		<view v-else-if="checkstatus === -1" class="ap-status ap-status--no">申请未通过，可重新提交</view>

		<view class="ap-card">
			<!-- 头像 -->
			<view class="ap-row ap-row--pic" @click="choosePic">
				<view class="ap-row__lab">头像</view>
				<image v-if="form.pic" class="ap-row__pic" :src="imgUrl(form.pic)" mode="aspectFill" />
				<view v-else class="ap-row__pic ap-row__pic--empty">+</view>
			</view>
			<view class="ap-row">
				<view class="ap-row__lab">昵称</view>
				<input v-model="form.nickname" class="ap-row__input" placeholder="请输入昵称" maxlength="29" />
			</view>
			<view class="ap-row">
				<view class="ap-row__lab">姓名</view>
				<input v-model="form.realname" class="ap-row__input" placeholder="请输入真实姓名" maxlength="29" />
			</view>
			<view class="ap-row">
				<view class="ap-row__lab">手机号</view>
				<input v-model="form.tel" class="ap-row__input" type="number" placeholder="请输入手机号" maxlength="11" />
			</view>
			<view class="ap-row ap-row--area">
				<view class="ap-row__lab">简介</view>
				<textarea
					v-model="form.shortdesc"
					class="ap-row__textarea"
					placeholder="一句话介绍自己（选填，200字内）"
					maxlength="199"
				/>
			</view>
		</view>

		<view class="ap-btn" :class="{ 'ap-btn--off': submitting || !cansub }" @click="submit">
			{{ checkstatus === 1 ? "保存修改" : "提交申请" }}
		</view>
	</view>
</template>

<script setup lang="ts">
import { ref } from "vue";
import { onLoad } from "@dcloudio/uni-app";
import { fetchLecturerApplyInfo, submitLecturerApply } from "../../api/kecheng";
import { uploadImage } from "../../api/common";
import { imgUrl } from "@/utils/format";

const cansub = ref(1);
const checkstatus = ref<number | null>(null);
const hasRecord = ref(false);
const submitting = ref(false);

const form = ref({ pic: "", nickname: "", realname: "", tel: "", shortdesc: "" });

async function loadInfo() {
	try {
		const res = await fetchLecturerApplyInfo();
		cansub.value = Number(res.cansub ?? 1);
		const info = res.info;
		if (info && Number(info.id ?? 0) > 0) {
			hasRecord.value = true;
			checkstatus.value = Number(info["checkstatus"] ?? 0);
			form.value = {
				pic: String(info.headimg ?? info.pic ?? ""),
				nickname: String(info.nickname ?? ""),
				realname: String(info.realname ?? ""),
				tel: String(info.tel ?? ""),
				shortdesc: String(info.shortdesc ?? ""),
			};
		}
	} catch (err) {
		uni.showToast({ title: (err as Error).message || "加载失败", icon: "none" });
	}
}

function choosePic() {
	void uni.chooseImage({
		count: 1,
		success: (r) => {
			const path = r.tempFilePaths[0];
			if (!path) return;
			void uploadImage(path)
				.then((res) => {
					form.value.pic = res.url;
				})
				.catch((err: Error) => {
					uni.showToast({ title: err.message || "上传失败", icon: "none" });
				});
		},
	});
}

function submit() {
	if (submitting.value || !cansub.value) return;
	const f = form.value;
	if (f.pic === "") {
		uni.showToast({ title: "请上传头像", icon: "none" });
		return;
	}
	if (f.nickname === "") {
		uni.showToast({ title: "请填写昵称", icon: "none" });
		return;
	}
	if (f.realname === "") {
		uni.showToast({ title: "请填写姓名", icon: "none" });
		return;
	}
	if (!/^1[3-9]\d{9}$/.test(f.tel)) {
		uni.showToast({ title: "请填写正确的手机号", icon: "none" });
		return;
	}
	submitting.value = true;
	const opttype: 0 | 1 = hasRecord.value && checkstatus.value === 1 ? 1 : 0;
	void submitLecturerApply({ ...f }, opttype)
		.then(() => {
			uni.showToast({ title: opttype === 1 ? "保存成功" : "申请已提交", icon: "success" });
			setTimeout(() => uni.navigateBack(), 1200);
		})
		.catch((err: Error) => {
			uni.showToast({ title: err.message || "提交失败", icon: "none" });
		})
		.finally(() => {
			submitting.value = false;
		});
}

onLoad(() => {
	void loadInfo();
});
</script>

<style scoped>
.ap-page {
	min-height: 100vh;
	background: #f6f6fa;
	padding: 24rpx;
	box-sizing: border-box;
}
.ap-status {
	padding: 20rpx 24rpx;
	border-radius: 12rpx;
	font-size: 26rpx;
	margin-bottom: 24rpx;
}
.ap-status--ok {
	color: #7b7de5;
	background: #f0f0fc;
}
.ap-status--wait {
	color: #e6a23c;
	background: #fdf6ec;
}
.ap-status--no {
	color: #fa5151;
	background: #fef0f0;
}
.ap-card {
	background: #fff;
	border-radius: 16rpx;
	padding: 0 32rpx;
}
.ap-row {
	display: flex;
	align-items: center;
	padding: 28rpx 0;
	border-bottom: 1rpx solid #f2f2f8;
}
.ap-row:last-child {
	border-bottom: none;
}
.ap-row--area {
	align-items: flex-start;
}
.ap-row__lab {
	width: 140rpx;
	color: #666;
	font-size: 28rpx;
	flex-shrink: 0;
}
.ap-row__input {
	flex: 1;
	font-size: 28rpx;
	color: #333;
}
.ap-row__pic {
	width: 120rpx;
	height: 120rpx;
	border-radius: 50%;
	margin-left: auto;
	background: #f2f2f8;
}
.ap-row__pic--empty {
	display: flex;
	align-items: center;
	justify-content: center;
	color: #bbb;
	font-size: 48rpx;
}
.ap-row__textarea {
	flex: 1;
	font-size: 28rpx;
	color: #333;
	height: 160rpx;
	line-height: 1.6;
}
.ap-btn {
	margin-top: 48rpx;
	height: 88rpx;
	line-height: 88rpx;
	text-align: center;
	border-radius: 44rpx;
	background: #7b7de5;
	color: #fff;
	font-size: 32rpx;
}
.ap-btn--off {
	opacity: 0.5;
}
</style>
