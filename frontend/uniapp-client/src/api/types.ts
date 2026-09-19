/** 客户端商城类型定义（字段与 app/api/controller 实际返回对齐） */

/** 商品列表项（index/home 与 goods/list 同构） */
export interface GoodsItem {
  id: number;
  name: string;
  image: string;
  price: number;
  market_price: number;
  stock: number;
  sales: number;
  create_time: string;
}

/** 首页聚合 */
export interface HomeData {
  banners: { image?: string; url?: string; title?: string }[];
  goods: GoodsItem[];
  promotions: { id?: number; name?: string; [key: string]: unknown }[];
}

/** 商品分类 */
export interface CategoryItem {
  id: number;
  name: string;
  pid: number;
  sort: number;
  goods_count: number;
}

/** 商品详情 */
export interface GoodsDetail {
  id: number;
  name: string;
  image: string;
  images: string[];
  price: number;
  market_price: number;
  stock: number;
  category_name: string;
  detail: string;
  /** 单品商品描述富文本（任务281，非空优先于公共底部描述） */
  description?: string;
}

/** 分页结果 */
export interface PageResult<T> {
  list: T[];
  total: number;
  page: number;
  pagesize: number;
}

/** 购物车行 */
export interface CartItem {
  id: number;
  product_id: number;
  name: string;
  pic: string;
  price: number;
  stock: number;
  num: number;
  selected: number;
}

/** 收货地址 */
export interface AddressItem {
  id: number;
  name: string;
  phone: string;
  province: string;
  city: string;
  district: string;
  detail: string;
  is_default: number;
  create_time: string;
}

/** 我的优惠券记录（status: 0未使用 1已使用 2已过期） */
export interface CouponRecord {
  id: number;
  coupon_id: number;
  name: string;
  type: number;
  money: number;
  discount: number;
  full_money: number;
  start_time: number;
  end_time: number;
  status: number;
}

/** 生效中满减促销活动（GET /api/promotion/list，PromotionService::format 口径） */
export interface PromotionItem {
  id: number;
  name: string;
  /** 1满额减 2满件折 */
  type: number;
  full_money: number;
  full_num: number;
  cut_money: number;
  discount: number;
  start_time: number;
  end_time: number;
  status: number;
  create_time: number;
}

/** 创建订单返回 */
export interface OrderCreateResult {
  id: number;
  order_sn: string;
  pay_amount: number;
  pay_expire_time: number;
  coupon_id: number;
  coupon_money: number;
  promotion_id: number;
  promotion_money: number;
  use_score: number;
  score_money: number;
}

/** 订单商品快照 */
export interface OrderGoodsItem {
  goods_id: number;
  name: string;
  pic: string;
  num: number;
  price: number;
  total: number;
}

/** 订单地址快照 */
export interface OrderAddress {
  name: string;
  phone: string;
  province: string;
  city: string;
  district: string;
  detail: string;
}

/** 订单（0待支付 1已支付待发货 2已发货 3已完成 4已取消） */
export interface OrderItem {
  id: number;
  order_sn: string;
  mid: number;
  status: number;
  status_text: string;
  pay_amount: number;
  coupon_money: number;
  promotion_id: number;
  promotion_money: number;
  use_score: number;
  score_money: number;
  freight: number;
  pay_time: string;
  ship_time: string;
  create_time: string;
  pay_expire_time?: string;
  goods: OrderGoodsItem[];
  address: OrderAddress;
}

/** 支付结果（PAY_MOCK=1 时 mock=true 且直接入账） */
export interface PayResult {
  mock?: boolean;
  status?: number;
  [key: string]: unknown;
}

/** 会员资料（GET /api/member/profile，MemberService::profile 口径） */
export interface MemberProfile {
  mid: number;
  phone: string;
  nickname: string;
  headimg: string;
  levelid: number;
  score: number;
  money: number;
  aid: number;
}

/** 退款单（GET /api/refund/list，RefundService::format 口径；status 0申请中 1已退款 2已驳回） */
export interface RefundItem {
  id: number;
  refund_sn: string;
  ordernum: string;
  order_sn: string;
  pay_amount: number;
  refund_amount: number;
  reason: string;
  status: number;
  status_text: string;
  apply_time: string;
  audit_time: string;
}

/** 充值赠送档位行（recharge_giveset.givedata JSON） */
export interface RechargeGiveItem {
  money: number;
  give: number;
  give_score: number;
}

/** 充值配置（GET /api/recharge/giveset） */
export interface RechargeConfig {
  givedata: RechargeGiveItem[];
  status: number;
  caninput: number;
  shuoming: string;
}

/** 创建充值单返回（POST /api/recharge/create） */
export interface RechargeCreateResult {
  id: number;
  order_sn: string;
  money: number;
}

/** 充值订单行（GET /api/recharge/orders；status 0待支付 1已支付） */
export interface RechargeOrderItem {
  id: number;
  order_sn: string;
  money: number;
  give_money: number;
  give_score: number;
  status: number;
  pay_type: string;
  pay_time: string;
  create_time: string;
}

/** 资料编辑返回（POST /api/member/editProfile；wanshan_score>0 表示本次触发完善资料送分） */
export interface EditProfileResult {
  wanshan_score: number;
}

/** 领券中心券模板（GET /api/coupon/list，CouponService::formatCoupon 口径） */
export interface CouponTemplate {
  id: number;
  name: string;
  type: number;
  money: number;
  discount: number;
  full_money: number;
  total: number;
  received: number;
  used: number;
  per_limit: number;
  start_time: number;
  end_time: number;
  status: number;
  create_time: number;
}

/** 积分明细行（GET /api/score/log，ScoreService::formatLog 口径） */
export interface ScoreLogItem {
  id: number;
  mid: number;
  phone: string;
  score: number;
  remark: string;
  type: number;
  create_time: number;
}

/* ================= 行业四包 ================= */

/** 网约车车辆（CarhailingService::formatProduct 口径） */
export interface CarItem {
  id: number;
  category_id: number;
  name: string;
  pic: string;
  pics: string;
  price_day: number;
  price_half: number;
  car_num: number;
  desc: string;
  status: number;
  create_time: number;
}

/** 网约车订单（状态 0待支付 1已支付 2使用中 3已完成 4已取消 5已退款） */
export interface CarOrder {
  id: number;
  order_sn: string;
  mid: number;
  product_id: number;
  product_name: string;
  pic: string;
  start_time: number;
  end_time: number;
  days: number;
  amount: number;
  coupon_money: number;
  score_money: number;
  pay_amount: number;
  status: number;
  status_text: string;
  pay_time: number;
  use_score: number;
  create_time: number;
}

/** 酒店（HotelService::formatHotel + apiList 附 min_price） */
export interface HotelItem {
  id: number;
  name: string;
  star: number;
  province: string;
  city: string;
  district: string;
  address: string;
  lng: number;
  lat: number;
  pic: string;
  contacts: string;
  phone: string;
  desc: string;
  status: number;
  create_time: number;
  min_price: number;
}

/** 房型（formatRoom 口径） */
export interface HotelRoom {
  id: number;
  hotel_id: number;
  name: string;
  bed_type: string;
  area: number;
  price: number;
  deposit: number;
  room_num: number;
  pic: string;
  desc: string;
  status: number;
  create_time: number;
}

/** 酒店详情（含房型） */
export type HotelDetail = HotelItem & { rooms: HotelRoom[] };

/** 房态查询返回 */
export interface RoomAvailableResult {
  available: number;
  nights: number;
  amount: number;
  deposit: number;
}

/** 酒店订单（状态 0待支付 1已预订 2已入住 3已完成 4已取消 5已退款） */
export interface HotelOrder {
  id: number;
  order_sn: string;
  mid: number;
  hotel_id: number;
  hotel_name: string;
  room_id: number;
  room_name: string;
  checkin_date: string;
  checkout_date: string;
  nights: number;
  amount: number;
  deposit_total: number;
  coupon_money: number;
  score_money: number;
  pay_amount: number;
  status: number;
  status_text: string;
  name: string;
  phone: string;
  pay_time: number;
  use_score: number;
  create_time: number;
}

/** 预约项目（formatProduct：price←sell_price duration←fwlong） */
export interface YuyueProduct {
  id: number;
  category_id: number;
  name: string;
  pic: string;
  price: number;
  desc: string;
  duration: number;
  status: number;
  create_time: number;
}

/** 预约技师（formatWorker 口径） */
export interface YuyueWorker {
  id: number;
  name: string;
  pic: string;
  phone: string;
  category_id: number;
  desc: string;
  status: number;
  create_time: number;
}

/** 时段可约项 */
export interface YuyueSlot {
  time: string;
  available: number;
}

/** 预约订单（状态 0待支付 1已支付 3已完成 4已取消 5已退款；后端无取消接口） */
export interface YuyueOrder {
  id: number;
  order_sn: string;
  mid: number;
  product_id: number;
  product_name: string;
  worker_id: number;
  worker_name: string;
  book_date: string;
  book_time: string;
  amount: number;
  coupon_money: number;
  score_money: number;
  pay_amount: number;
  use_score: number;
  status: number;
  status_text: string;
  hexiao_code: string;
  pay_time: number;
  create_time: number;
}

/** 餐饮店铺信息（apiInfo 口径：区域桌台树） */
export interface RestaurantInfo {
  shop: { name: string };
  areas: { id: number; name: string; tables: RestaurantTable[] }[];
}

/** 桌台（busy 由后端推导：存在进行中订单=1） */
export interface RestaurantTable {
  id: number;
  area_id: number;
  area_name: string;
  name: string;
  persons: number;
  qrcode_url: string;
  status: number;
  sort: number;
  busy: number;
  create_time: number;
}

/** 餐饮菜品（formatProduct 口径） */
export interface RestaurantProduct {
  id: number;
  category_id: number;
  name: string;
  pic: string;
  price: number;
  desc: string;
  status: number;
  create_time: number;
}

/** 堂食订单商品快照 */
export interface RestaurantOrderGoods {
  product_id: number;
  name: string;
  pic: string;
  num: number;
  price: number;
}

/** 堂食订单（状态 0待支付 1已支付 3已完成 4已取消 5已退款） */
export interface RestaurantOrder {
  id: number;
  order_sn: string;
  mid: number;
  table_id: number;
  table_name: string;
  goods: RestaurantOrderGoods[];
  amount: number;
  coupon_money: number;
  score_money: number;
  pay_amount: number;
  use_score: number;
  status: number;
  status_text: string;
  pay_time: number;
  create_time: number;
}
