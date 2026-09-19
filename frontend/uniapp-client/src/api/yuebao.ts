import { get, post } from "../utils/request";

/** 余额宝页数据（GET /api/yuebao/info） */
export interface YuebaoInfo {
  open: boolean;
  yuebao_money: number;
  money: number;
  rate: number;
  today_income: number;
  total_income: number;
  config: {
    open_yuebao: number;
    yuebao_rate: number;
    yuebao_withdrawmin: number;
    yuebao_withdrawfee: number;
    yuebao_turn_yue: number;
  };
  aliaccount: string;
  bankname: string;
  bankcarduser: string;
  bankcardnum: string;
}

/** 收益流水（st=0） */
export interface YuebaoMoneyLogItem {
  id: number;
  money: number;
  after: number;
  type: number;
  remark: string;
  createtime: number;
}

/** 提现记录（st=1） */
export interface YuebaoWithdrawLogItem {
  id: number;
  money: number;
  txmoney: number;
  paytype: string;
  status: number;
  status_text: string;
  reason: string;
  createtime: number;
}

export interface YuebaoRecordPage<T> {
  list: T[];
  total: number;
  page: number;
  pagesize: number;
}

/** 余额宝页数据 */
export function fetchYuebaoInfo() {
  return get<YuebaoInfo>("/api/yuebao/info");
}

/** 收益明细（st=0） */
export function fetchYuebaoLog(page: number, pagesize = 15) {
  return get<YuebaoRecordPage<YuebaoMoneyLogItem>>("/api/yuebao/log", {
    st: 0,
    page,
    pagesize,
  });
}

/** 提现记录（st=1） */
export function fetchYuebaoWithdrawLog(page: number, pagesize = 15) {
  return get<YuebaoRecordPage<YuebaoWithdrawLogItem>>("/api/yuebao/log", {
    st: 1,
    page,
    pagesize,
  });
}

/** 申请提现（POST /api/yuebao/withdraw） */
export function applyYuebaoWithdraw(money: number, paytype: string) {
  return post<{ id: number; ordernum: string; money: number }>(
    "/api/yuebao/withdraw",
    { money, paytype },
  );
}

/** 收益转余额（POST /api/yuebao/turnMoney） */
export function yuebaoTurnMoney(money: number) {
  return post<{ money: number }>("/api/yuebao/turnMoney", { money });
}
