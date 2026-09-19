/** 餐饮分包（排队叫号 / 预定订座 / 寄存）类型定义，对齐批次6后端口径 */

/* ================= 排队叫号 ================= */

/** 队列分类（含当日前面等待人数与预计等待分钟） */
export interface QueueCategory {
  id: number;
  name: string;
  code: string;
  per_minute: number;
  seat_min: number;
  seat_max: number;
  status: number;
  call_text?: string;
  waitnum: number;
  need_minute: number;
}

/** 我当日排队中的号 */
export interface MyQueueRow {
  id: number;
  cid: number;
  queue_no: string;
  date: string;
  status: number;
  linkman: string;
  tel: string;
  renshu: string;
  create_time: number;
  beforenum: number;
  need_minute: number;
  cname: string;
}

/** 刚被叫到的号（300 秒内） */
export interface JustQueueRow {
  id: number;
  cid: number;
  queue_no: string;
  call_text: string;
  call_time: number;
  cname: string;
}

/** 排队首页聚合 */
export interface QueueHomeResult {
  clist: QueueCategory[];
  notice: string;
  myqueue: MyQueueRow | null;
  myjustqueue: JustQueueRow | null;
}

/** 取号记录行 */
export interface QueueRecordRow {
  id: number;
  cid: number;
  queue_no: string;
  date: string;
  status: number;
  linkman: string;
  tel: string;
  renshu: string;
  create_time: number;
  cname: string;
}

/* ================= 预定订座 ================= */

/** 可预约时段（booking/add GET timeArr） */
export interface BookingSlot {
  day: number;
  day_text: string;
  date: string;
  start: string;
  end: string;
  title: string;
  value: string;
  start_ts: number;
}

/** 预定设置 + 时段列表 */
export interface BookingSet {
  status: number;
  pay: number;
  pay_money: number;
  prehour: number;
  timeArr: BookingSlot[];
}

/** 桌台分类 */
export interface BookingTableCategory {
  id: number;
  name: string;
  pic: string;
  booking_fee: number;
  limit_fee: number;
  service_fee: number;
  sort: number;
}

/** 可预定桌台 */
export interface BookableTable {
  id: number;
  name: string;
  cid: number;
  category_name: string;
  seat: number;
  pic: string;
  sort: number;
  limit_fee: number;
  service_fee: number;
  booking_fee: number;
}

/** 创建预定单返回 */
export interface BookingCreateResult {
  order_id: number;
  ordernum: string;
  table_id: number;
  totalprice: number;
  status: number;
  need_pay: number;
}

/** 预定订单行（orderlist / detail 公共口径） */
export interface BookingOrder {
  id: number;
  ordernum: string;
  table_id: number;
  table_name: string;
  booking_time: string;
  seat: number;
  tel: string;
  linkman: string;
  message: string;
  booking_fee: number;
  totalprice: number;
  status: number;
  status_text: string;
  check_status: number;
  check_text: string;
  refund_status: number;
  refund_money: number;
  refund_reason: string;
  paytype: string;
  pay_time: number;
  createtime: number;
}

/** 商家信息（详情附带） */
export interface BookingBusiness {
  name: string;
  logo: string;
  tel: string;
  address: string;
}

/** 预定订单详情 */
export interface BookingOrderDetail extends BookingOrder {
  business: BookingBusiness | null;
}

/* ================= 寄存 ================= */

/** 寄存订单行 */
export interface DepositOrder {
  id: number;
  bid: number;
  mid: number;
  name: string;
  num: number;
  linkman: string;
  tel: string;
  pic: string;
  message: string;
  position_name: string;
  number_name: string;
  status: number;
  status_text: string;
  createtime: number;
  takeout_time: number;
}

/** 商家简档（分组头） */
export interface DepositBinfo {
  id: number;
  name: string;
  logo: string;
}

/** 按商家分组的寄存列表 */
export interface DepositGroup {
  bid: number;
  binfo: DepositBinfo;
  prolist: DepositOrder[];
}

/** 寄存设置 */
export interface DepositSysset {
  id: number;
  aid: number;
  bid: number;
  time: string;
  status: number;
}

/** 存取流水行 */
export interface DepositLogRow {
  id: number;
  type: number;
  type_text: string;
  num: number;
  remark: string;
  createtime: number;
}

/** 单笔寄存详情 + 流水 */
export interface DepositOrderDetail extends DepositOrder {
  binfo: DepositBinfo;
  log: DepositLogRow[];
}
