import { computed, ref } from "vue";
import { defineStore } from "pinia";

/** 会员信息（后端 /api/member/login 返回 member 对象，字段宽松） */
export interface MemberInfo {
  id?: number;
  nickname?: string;
  phone?: string;
  avatar?: string;
  score?: number;
  [key: string]: unknown;
}

const TOKEN_KEY = "dxcx_token";
const MEMBER_KEY = "dxcx_member";

function readMember(): MemberInfo {
  try {
    const raw = uni.getStorageSync(MEMBER_KEY);
    return raw && typeof raw === "object" ? (raw as MemberInfo) : {};
  } catch {
    return {};
  }
}

/**
 * 会员会话 store：token + member，同步持久化到 uni storage
 */
export const useSessionStore = defineStore("session", () => {
  const token = ref<string>(uni.getStorageSync(TOKEN_KEY) || "");
  const member = ref<MemberInfo>(readMember());

  const isLoggedIn = computed(() => token.value !== "");

  function setSession(newToken: string, newMember: MemberInfo) {
    token.value = newToken;
    member.value = newMember;
    uni.setStorageSync(TOKEN_KEY, newToken);
    uni.setStorageSync(MEMBER_KEY, newMember);
  }

  function clearSession() {
    token.value = "";
    member.value = {};
    uni.removeStorageSync(TOKEN_KEY);
    uni.removeStorageSync(MEMBER_KEY);
  }

  return { token, member, isLoggedIn, setSession, clearSession };
});
