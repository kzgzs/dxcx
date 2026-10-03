// 统一响应结构
export interface ApiResponse<T = any> {
  code: number
  msg: string
  data: T
}

// 登录返回
export interface UserInfo {
  id: number
  username: string
  isadmin: number
  aid?: number
}

export interface LoginResult {
  token: string
  userinfo: UserInfo
}

// 角色：console 超管 / admin 租户
export type Role = 'console' | 'admin'

// 租户资料（GET /admin/index/profile）
export interface AdminTenantInfo {
  id: number
  username: string
  /** 优先使用此名称（后端返回的租户显示名） */
  name?: string
  /** 备用公司名称 */
  company: string
  endtime: string
  expired: number
}

export interface AdminProfile {
  id: number
  username: string
  isadmin: number
  aid: number
  /** 超管代登录标记（1=平台超管，最高权限，租户壳放行全部菜单） */
  super?: number
  tenant: AdminTenantInfo
}

// 超管资料（GET /console/index/profile）
export interface ConsoleProfile {
  id: number
  username: string
  isadmin: number
}

// 租户后台菜单（GET /admin/menu）
export interface AdminMenuItem {
  name: string
  // 一级菜单全称（飞梭子导航面板标题，如「商城系统」）
  fullname?: string
  path: string
  icon?: string
  children?: AdminMenuItem[]
}

// ============ 租户后台：商品管理 ============

// 商品分类
export interface CategoryItem {
  id: number
  name: string
  pid: number
  sort: number
  /** 分类图标 */
  pic: string
  /** 0 隐藏 1 显示 */
  status: number
  goods_count: number
  create_time: string
  /** 前端组装树用 */
  children?: CategoryItem[]
  /** 层级深度，从 0 起 */
  deep?: number
}

export interface CategoryListResult {
  list: CategoryItem[]
  total: number
  page: number
  pagesize: number
}

export interface CategoryListQuery {
  page?: number
  pagesize?: number
  keyword?: string
}

export interface CategoryAddParams {
  name: string
  pid: number
  sort: number
  pic?: string
  status?: number
}

export interface CategoryEditParams {
  id: number
  name: string
  pid?: number
  sort: number
  pic?: string
  status?: number
}

// 商品
export interface GoodsItem {
  id: number
  name: string
  image: string
  price: number
  market_price: number
  stock: number
  /** 显示销量 */
  sales?: number
  /** 实际销量 */
  realsalenum?: number
  /** 分组ID（逗号分隔） */
  gid?: string
  category_id: number
  category_name: string
  status: number // 1上架 0下架
  sort: number
  create_time: string
}

export interface GoodsListResult {
  list: GoodsItem[]
  total: number
  page: number
  pagesize: number
}

export interface GoodsListQuery {
  page?: number
  pagesize?: number
  keyword?: string
  category_id?: number | ''
  status?: number | ''
  /** 分组ID */
  gid?: number | ''
  /** 排序字段：id/stock/sales/sort/createtime */
  sort_field?: string
  /** 排序方向：asc/desc */
  sort_order?: string
  /** 商户维度：空/all=全部 0=自营 gt0=商户 >0为指定商户ID（任务177） */
  bid?: string
}

// 商品新增 / 编辑（images 为逗号分隔的图片 url 串）
export interface GoodsSaveParams {
  id?: number
  name: string
  category_id: number
  price: number
  market_price: number
  stock: number
  image: string
  images: string
  detail: string
  /** 单品商品描述富文本（任务281，非空时 C 端优先渲染） */
  description: string
  sort: number
  status: number
  procode?: string
  /** 多规格规格组（guigedata 口径）；空数组=单规格走原逻辑 */
  specs?: GoodsSpecGroup[]
  lvprice?: number
  /** 会员等级价格明细，键为等级 id */
  lvprice_data?: Record<string, number> | string
  /** 商品别名（小票打印用，留空用商品名） */
  print_name?: string
  barcode?: string
  /** 商品卖点 */
  sellpoint?: string
  /** 商品视频链接 */
  video?: string
  /** 多分类 id（最多 5 个） */
  cid?: number[]
  /** 商品分组 id 数组 */
  gid?: number[]
  /** 商品服务 id 数组 */
  fwid?: number[]
  cost_price?: number
  weight?: number
  givescore?: number
  /** 赠积分时机 0确认收货 1付款 */
  givescore_time?: number
  /** 价格模式 0默认 1询价 */
  price_type?: number
  /** 会员等级折扣 -1跟随系统 0参与 1不参与 */
  no_discount?: number
  /** 配送模板 1全部铺货 0选择铺货 3自动发货 4在线卡密 */
  freighttype?: number
  freightdata?: string
  /** 分销设置 0按会员等级 1单独比例 2单独金额 3分销送积分 5比例+积分 6金额+积分 -1不参与 */
  commissionset?: number
  /** 积分抵扣 0按系统 1单独比例 2单独金额 -1不可抵扣 */
  scoredkmaxset?: number
  scoredkmaxval?: number
  /** 抽成费率，空=商户统一费率 */
  feepercent?: number | null
  sales?: number
  /** 起售数量 */
  limit_start?: number
  /** 每人限购 */
  perlimit?: number
  /** 每单限购 */
  perlimitdan?: number
  /** 显示条件（-1所有人 -2未登录 0关注 等级id） */
  showtj?: string[]
  /** 购买条件（-1所有人 0关注 等级id） */
  gettj?: string[]
  start_hours?: string
  end_hours?: string
  start_time?: string
  end_time?: string
  /** 商品参数，键为参数名 */
  paramdata?: Record<string, unknown> | string
  sharetitle?: string
  sharepic?: string
  sharedesc?: string
  /** SKU 字段 map，键为规格组合 ks（如 "0,1"） */
  options?: Record<string, GoodsSkuOption>
}

// 规格组（对标参考系统 guigedata：[{title,k,items:[{title,k,ggpic_wholesale}]}]）
export interface GoodsSpecValue {
  title: string
  k?: string
  /** 规格值图片 */
  ggpic_wholesale?: string
}

export interface GoodsSpecGroup {
  title: string
  k?: string
  items: GoodsSpecValue[]
}

// SKU 单行提交字段（按 ks 定位）
export interface GoodsSkuOption {
  name: string
  pic?: string
  market_price: number
  cost_price?: number
  sell_price: number
  stock: number
  weight?: number
  procode?: string
  barcode?: string
  givescore?: number
  limit_start?: number
}

// SKU 行（详情回显）
export interface GoodsSkuItem {
  id: number
  ks: string
  name: string
  pic: string
  market_price: number
  cost_price: number
  sell_price: number
  weight: number
  stock: number
  procode: string
  barcode: string
  sales: number
  givescore: number
  limit_start: number
}

// 商品详情（编辑回显：基础字段 + specs + skus）
export interface GoodsDetailResult {
  id: number
  name: string
  category_id: number
  price: number
  market_price: number
  cost_price: number
  stock: number
  image: string
  images: string
  detail: string
  /** 单品商品描述富文本（任务281） */
  description: string
  sort: number
  status: number
  procode: string
  lvprice: number
  lvprice_data: Record<string, number>
  print_name: string
  barcode: string
  sellpoint: string
  video: string
  weight: number
  givescore: number
  givescore_time: number
  cids: number[]
  gids: number[]
  fwids: number[]
  showtjs: string[]
  gettjs: string[]
  price_type: number
  no_discount: number
  freighttype: number
  freightdata: string
  contact_require: number
  freightcontent: string
  commissionset: number
  commissiondata1?: Record<string, Record<string, number | string>>
  commissiondata2?: Record<string, Record<string, number | string>>
  commissiondata3?: Record<string, Record<string, number | string>>
  scoredkmaxset: number
  scoredkmaxval: number
  feepercent: number | null
  sales: number
  limit_start: number
  perlimit: number
  perlimitdan: number
  start_hours: string
  end_hours: string
  start_time: string
  end_time: string
  paramdata: Record<string, unknown>
  sharetitle: string
  sharepic: string
  sharedesc: string
  specs: GoodsSpecGroup[]
  skus: GoodsSkuItem[]
}

// Excel 库存导入结果
export interface StockImportResult {
  updated: number
  missed: number
}

// ============ 商品卡密（shop_codelist） ============

export interface CodeItem {
  id: number
  proid: number
  content: string
  ordernum: string
  orderid: number
  nickname: string
  status: number // 0未售出 1已售出
  create_time: string
  buy_time: string
}

export interface CodeListQuery {
  page?: number
  pagesize?: number
  proid?: number
  content?: string
  ordernum?: string
  nickname?: string
  status?: number | ''
}

export interface CodeListResult {
  list: CodeItem[]
  total: number
  page: number
  pagesize: number
}

export interface CodeMakeParams {
  proid: number
  makecount: number
  codelength: number
  codetype: number // 1纯数字 2大小写字母 3小写 4大写 5数字+小写 6数字+大写
}

export interface CodeImportResult {
  inserted: number
  duplicated: number
}

// 上传返回（POST /admin/upload）
export interface UploadResult {
  url: string
}

// 上传接口原始响应（el-upload 不走 axios 拦截器，需自行判断 code）
export interface UploadRawResponse {
  code: number
  msg: string
  data: UploadResult
}

// ============ 租户后台：订单管理 ============

export interface OrderGoodsItem {
  name: string
  pic: string
  num: number
  price: number
  total?: number // 该行小计
  sku?: string // 规格名称
  procode?: string // 商品编码
  refund_num?: number // 该行退款数量
}

export interface OrderAddress {
  name: string
  phone: string
  province?: string
  city?: string
  district?: string
  detail: string
}

export interface OrderItem {
  id: number
  order_sn: string
  mid: number
  /** 所属商户ID（0=平台自营，商户订单视图使用） */
  bid?: number
  /** 所属商户名（0=平台自营，商户订单视图使用） */
  bname?: string
  member_phone: string
  pay_amount: number
  status: number // 0待支付 1待发货 2已发货 3已完成 4已取消
  status_text: string
  pay_time: number // Unix 秒
  ship_time: number // Unix 秒
  create_time: number // Unix 秒
  goods: OrderGoodsItem[]
  address: OrderAddress
  product_amount?: number // 商品总价
  coupon_money?: number // 优惠券抵扣
  promotion_money?: number // 促销优惠
  score_money?: number // 积分抵扣
  use_score?: number // 使用积分
  freight?: number // 运费
  pay_type?: string // 支付方式名称
  platform?: string // 下单来源
  freight_text?: string // 配送方式
  refund_status?: number // 售后状态 0无 1待审核 2已退款 3驳回
  refund_money?: number
  refund_count?: number // 售后单数
  buyer_message?: string // 买家留言
  member_nickname?: string // 会员昵称
  member_headimg?: string // 会员头像
  express_company?: string // 物流公司
  express_no?: string // 物流单号
  // 详情接口可能返回的扩展字段
  ship_company?: string
  ship_sn?: string
  finish_time?: number
  remark?: string
  message?: string
}

export interface OrderListResult {
  list: OrderItem[]
  total: number
  page: number
  pagesize: number
}

export interface OrderListQuery {
  page?: number
  pagesize?: number
  status?: number | ''
  keyword?: string
  /** 商户维度：空/all=全部 0=自营 gt0=商户 >0为指定商户ID（任务177） */
  bid?: string
  /** 字段类型搜索：1订单号 2会员ID 3会员信息 4收货信息 5快递单号 6商品ID 7商品名称 8商品编码 */
  keyword_type?: number
  /** 时间类型：1下单 2付款 3发货 4完成 */
  time_type?: number
  start?: string
  end?: string
  freight_id?: number | ''
  price_min?: string
  price_max?: string
  sort_field?: string
  sort_order?: string
}

// 订单统计（GET /admin/order/stat）
export interface OrderStat {
  wait_pay: number
  wait_ship: number
  shipped: number
  done: number
  cancelled: number
  total: number
}

// 发货参数
export interface OrderShipParams {
  id: number
  ship_company: string
  ship_sn: string
}

// ============ 订单批量操作（P17-2） ============

// 批量发货明细行
export interface BatchShipResultItem {
  ordernum: string
  ok: boolean
  msg: string
}

// 批量发货结果（Excel 导入 / 选中项共用）
export interface BatchShipResult {
  total: number
  success: number
  fail: number
  items: BatchShipResultItem[]
}

// 选中项批量发货参数
export interface BatchShipSelectedParams {
  ids: number[]
  ship_company: string
  ship_sn: string
}

// 批量删除明细行
export interface BatchDeleteResultItem {
  id: number
  ok: boolean
  msg: string
}

// 批量删除结果
export interface BatchDeleteResult {
  total: number
  success: number
  fail: number
  items: BatchDeleteResultItem[]
}

// 送货单设置（shop_sysset 三字段）
export interface ShdSetData {
  shipping_pagetitle: string // 送货单标题
  shipping_pagenum: number // 每页打印行数（0=不分页）
  shipping_linenum: number // 每页表格固定行数（0=不固定）
}

// ============ 租户后台：退款管理 ============

// 退款单内商品快照
export interface RefundGoodsItem {
  goods_id: number
  name: string
  pic: string
  sku: string
  num: number
  price: number
}

export interface RefundItem {
  id: number
  orderid: number
  mid: number
  refund_sn: string // 退款单号
  ordernum: string // 订单号
  refund_type: string // refund 仅退款 / return 退货退款 / exchange 换货
  refund_type_label: string
  refund_amount: number
  reason: string
  status: number // 契约：0待审核 1已退款 2已驳回
  status_text: string
  apply_time: string // Y-m-d H:i:s
  audit_time: string
  audit_remark: string
  member_phone: string
  member_nickname?: string
  member_headimg?: string
  express_company?: string // 退货快递公司
  express_no?: string // 退货快递单号
  refund_pics?: string[] // 凭证图片
  goods?: RefundGoodsItem[]
}

export interface RefundListResult {
  list: RefundItem[]
  total: number
  page: number
  pagesize: number
}

export interface RefundListQuery {
  page?: number
  pagesize?: number
  status?: number | ''
  keyword?: string
  refund_sn?: string
  express_no?: string
  ordernum?: string
  tel?: string
  start?: string
  end?: string
  sort_field?: 'id' | 'refund_ordernum' | 'ordernum'
  sort_order?: 'asc' | 'desc'
}

// 退款详情内会员
export interface RefundDetailMember {
  id: number
  nickname: string
  headimg: string
  tel: string
}

// 退款详情（含订单商品快照、订单、寄回信息）
export interface RefundDetail extends RefundItem {
  goods: RefundGoodsItem[]
  member: RefundDetailMember | null
  return: { name: string; tel: string; address: string }
  express_content: string
  order: {
    order_sn: string
    status: number
    status_text: string
    pay_amount: number
    product_amount: number
    pay_type: string
    create_time: string
    pay_time: string
    address: { name: string; phone: string; detail: string }
  } | null
}

// 退款统计（GET /admin/refund/stat）
export interface RefundStat {
  pending: number
  done: number
  rejected: number
}

// 退款审核（pass：1同意 2驳回）
export interface RefundAuditParams {
  id: number
  pass: number
  remark: string
}

// ============ 租户后台：商户管理 ============

export interface MerchantItem {
  id: number
  name: string
  logo: string
  contacts: string
  phone: string
  remark: string
  status: number // 1正常 0停用
  store_count: number
  createtime: number // Unix 秒
}

export interface MerchantListResult {
  list: MerchantItem[]
  total: number
  page: number
  pagesize: number
}

export interface MerchantListQuery {
  page?: number
  pagesize?: number
  keyword?: string
  status?: number | ''
}

export interface MerchantSaveParams {
  id?: number
  name: string
  contacts: string
  phone: string
  remark: string
  status: number
}

// ============ 租户后台：门店管理 ============

export interface StoreItem {
  id: number
  merchant_id: number
  merchant_name: string
  name: string
  contacts: string
  phone: string
  province: string
  city: string
  district: string
  address: string
  status: number // 1正常 0停用
  createtime: number // Unix 秒
  // 列表接口未必返回，编辑时兜底用
  lng?: number | string
  lat?: number | string
}

export interface StoreListResult {
  list: StoreItem[]
  total: number
  page: number
  pagesize: number
}

export interface StoreListQuery {
  page?: number
  pagesize?: number
  keyword?: string
  merchant_id?: number | ''
}

export interface StoreSaveParams {
  id?: number
  merchant_id: number
  name: string
  contacts: string
  phone: string
  province: string
  city: string
  district: string
  address: string
  lng: number | string
  lat: number | string
  status: number
}

// ============ 租户后台：优惠券管理 ============

export interface CouponItem {
  id: number
  name: string
  type: number // 1满减 2折扣
  money: number // 减免额（满减）
  discount: number // 折扣值（折扣，如 8.5 = 8.5折）
  full_money: number // 使用门槛
  total: number // 发行总量
  received: number // 已领取
  used: number // 已使用
  per_limit: number // 每人限领
  start_time: number // Unix 秒
  end_time: number // Unix 秒
  status: number // 1启用 0停用
  create_time: number // Unix 秒
}

export interface CouponListResult {
  list: CouponItem[]
  total: number
  page: number
  pagesize: number
}

export interface CouponListQuery {
  page?: number
  pagesize?: number
  keyword?: string
  status?: number | ''
}

export interface CouponSaveParams {
  id?: number
  name: string
  type: number
  money: number
  discount: number
  full_money: number
  total: number
  per_limit: number
  start_time: number // Unix 秒
  end_time: number // Unix 秒
}

// 优惠券领取记录
export interface CouponRecordItem {
  id: number
  mid: number
  member_phone: string
  status: number // 0未使用 1已使用 2已过期
  receive_time: number // Unix 秒
  use_time: number // Unix 秒
  order_sn: string
}

export interface CouponRecordListResult {
  list: CouponRecordItem[]
  total: number
  page: number
  pagesize: number
}

export interface CouponRecordQuery {
  coupon_id: number
  status?: number | ''
  page?: number
  pagesize?: number
  field?: string
  order?: string
}

// ============ 租户后台：优惠券管理补全（参考口径） ============

// 券行（GET /admin/couponManage/list|detail）
export interface CouponManageItem {
  id: number
  name: string
  type: number // 1代金 2礼品 3计次 4运费抵 10折扣 11兑换
  type_txt: string
  money: number
  discount: number
  minprice: number
  stock: number
  getnum: number
  used: number
  perlimit: number
  yxqtype: number // 1定时间范围 2领取后N天
  yxqdate: number
  yxqtime: string
  starttime: string // varchar 'Y-m-d H:i:s'
  endtime: string
  start_stamp: number
  end_stamp: number
  phase: string // not_started/ongoing/ended
  gettj: string
  showtj: string
  isgive: number // 0仅自用 1自用+转赠 2仅转赠
  tolist: number
  fwtype: number // 0所有商品 1指定类目 2指定商品 4指定服务商品
  categoryids: string
  productids: string
  yuyue_productids: string
  price: number
  score: number
  paygive: number
  buyprogive: number
  buyproids: string
  buypro_give_num: string
  buyyuyueprogive: number
  buyyuyueproids: string
  buyyuyuepro_give_num: string
  give_num_type: number
  font_color: string
  title_color: string
  bg_color: string
  limit_count: number
  value: number
  gettips: string
  usetips: string
  sort: number
  status: number
  create_time: number
}

export interface CouponManageListQuery {
  type?: number | ''
  status?: number | ''
  keyword?: string
  page?: number
  pagesize?: number
  field?: string
  order?: string
}

export interface CouponManageListResult {
  list: CouponManageItem[]
  total: number
  page: number
  pagesize: number
}

// 保存参数（POST /admin/couponManage/save；时间传 Unix 秒，后端转 varchar 落库）
export interface CouponManageSaveParams {
  id?: number
  name: string
  type: number
  money: number
  discount: number
  minprice: number
  stock: number
  perlimit: number
  yxqtype: number
  yxqdate: number
  yxqtime: string
  starttime: number
  endtime: number
  gettj: string
  showtj?: string
  isgive: number
  tolist: number
  fwtype: number
  categoryids: string
  productids: string
  yuyue_productids?: string
  price: number
  score: number
  paygive?: number
  buyprogive?: number
  buyproids?: string
  buypro_give_num?: string
  buyyuyueprogive?: number
  buyyuyueproids?: string
  buyyuyuepro_give_num?: string
  give_num_type?: number
  font_color?: string
  title_color?: string
  bg_color?: string
  limit_count?: number
  gettips?: string
  usetips?: string
  sort?: number
}

// 领取记录行（过期 status=2 为后端实时计算）
export interface CouponManageRecordItem {
  id: number
  mid: number
  nickname: string
  headimg: string
  member_phone: string
  couponid: number
  couponname: string
  money: number
  discount: number
  minprice: number
  type: number
  type_txt: string
  limit_count: number
  used_count: number
  status: number // 0未使用 1已使用 2已过期
  starttime: number
  endtime: number
  receive_time: number
  use_time: number
  order_id: number
  remark: string
  from_mid: number
}

export interface CouponManageRecordQuery {
  coupon_id?: number
  mid?: number
  status?: number | ''
  keyword?: string
  remark?: string
  start_time?: number
  end_time?: number
  page?: number
  pagesize?: number
  field?: string
  order?: string
}

export interface CouponManageRecordResult {
  list: CouponManageRecordItem[]
  total: number
  page: number
  pagesize: number
}

// 批量发放（POST /admin/couponManage/send）
export interface CouponSendParams {
  coupon_id: number
  send_type: number // 0指定会员 1按条件
  mids?: number[]
  condition?: {
    nickname?: string
    levelid?: number
    ctime_start?: number
    ctime_end?: number
  }
  per_num: number
}

export interface CouponSendResult {
  sucnum: number
  errnum: number
}

// 过期提醒设置（GET/POST /admin/couponManage/set）
export interface CouponSetData {
  expire_status: number
  expire_rules: number[]
}

// ============ 租户后台：积分签到 ============

export interface SignRuleItem {
  days: number
  score: number
  coupon_id: number
  coupon_name: string
}

export interface SignSetData {
  score: number
  lxqdset: SignRuleItem[]
  lxzsset: SignRuleItem[]
  name: string
  status: number
  display: number
  guize: string
  style: number
  bgpic: string
  rewardedvideoad: string
}

export interface SignRecordItem {
  id: number
  mid: number
  nickname: string
  headimg: string
  member_phone: string
  signdate: string
  signtimes: number
  signtimeslx: number
  score: number
  remark: string
  create_time: number
  lxqd_coupon_id: number
  lxqd_coupon_name: string
  lxzs_coupon_id: number
  lxzs_coupon_name: string
}

export interface SignRecordQuery {
  mid?: number
  nickname?: string
  start_time?: number
  end_time?: number
  page?: number
  pagesize?: number
  field?: string
  order?: string
}

export interface SignRecordResult {
  list: SignRecordItem[]
  total: number
  page: number
  pagesize: number
}

// ============ 租户后台：赠送奖励设置 ============

// 支付后赠（GET/POST /admin/reward/payaftergive）
export interface PayaftergiveData {
  name: string
  gettj: string
  paygive_scene: string
  limittimes: number
  pricestart: number
  priceend: number
  starttime: number
  endtime: number
  money: number
  score: number
  give_coupon: number
  coupon_ids: string
  tourl: string
  btntext: string
  status: number
  sort: number
}

// 确认收货奖励（GET/POST /admin/reward/collect）
export interface CollectRewardData {
  start_time: number
  end_time: number
  reward_type: number // 1积分 2优惠券 3余额 4佣金
  score: number
  coupon_id: number
  coupon_name: string
  money: number
  commission: number
  order_type: string[]
  platform: string[]
  gettj: string[]
  min_order_amount: number
  max_daily_return: number
  max_month_return: number
  prompt: string
  bgcolor: string
  fontcolor: string
  status: number
}

// ============ 租户后台：积分 ============

// 积分配置（GET/POST /admin/score/config）
export interface ScoreConfigData {
  score_switch: number // 0关闭 1开启
  score_ratio: number // N 积分抵 1 元
  pay_score: number // 每满 1 元送 N 积分
}

// 会员积分
export interface ScoreMemberItem {
  mid: number
  phone: string
  score: number
}

export interface ScoreMemberListResult {
  list: ScoreMemberItem[]
  total: number
  page: number
  pagesize: number
}

// 积分调整（score 整数可负）
export interface ScoreAdjustParams {
  mid: number
  score: number
  remark: string
}

// 积分流水
export interface ScoreLogItem {
  id: number
  mid: number
  phone: string
  score: number // 变动积分，正加负减
  remark: string
  type: number // 1支付赠送 2下单抵扣 3取消返还 4后台调整
  create_time: number // Unix 秒
}

export interface ScoreLogListResult {
  list: ScoreLogItem[]
  total: number
  page: number
  pagesize: number
}

// ============ 租户后台：满减促销 ============

export interface PromotionItem {
  id: number
  name: string
  type: number // 1满额减 2满件折
  full_money: number // 金额门槛（满额减）
  full_num: number // 件数门槛（满件折）
  cut_money: number // 减免额（满额减）
  discount: number // 折扣值（满件折，如 8.5 = 8.5折）
  start_time: number // Unix 秒
  end_time: number // Unix 秒
  status: number // 1启用 0停用
  create_time: number // Unix 秒
}

export interface PromotionListResult {
  list: PromotionItem[]
  total: number
  page: number
  pagesize: number
}

export interface PromotionListQuery {
  page?: number
  pagesize?: number
  keyword?: string
  status?: number | ''
}

export interface PromotionSaveParams {
  id?: number
  name: string
  type: number
  full_money: number
  full_num: number
  cut_money: number
  discount: number
  start_time: number // Unix 秒
  end_time: number // Unix 秒
}



// 控制台统计（首页四卡）
export interface DashboardData {
  // 旧版兼容字段
  tenant_total: number
  tenant_normal: number
  tenant_expired: number
  member_total: number
  order_total: number
  order_amount: number
  // 卡1 平台会员总数
  member_yesterday: number
  member_month: number
  // 卡2 平台商品数量
  goods_total: number
  goods_on: number
  goods_off: number
  // 卡3 平台订单数 / 订单金额
  order_yesterday_count: number
  order_yesterday_amount: number
  order_month_count: number
  order_month_amount: number
  // 卡4 平台收款金额
  pay_total: number
  pay_yesterday: number
  pay_month: number
}

// 租户
export interface TenantItem {
  id: number
  username: string
  company: string
  contacts: string
  phone: string
  remark: string
  logintime: string
  bind_mp: string
  bind_wx: string
  createtime: string
  endtime: string
  status: number // 1正常 2关闭
  is_expired: number // 是否已到期
  /** 超管虚拟行标记（1=列表首位 admin，展示 ID=1） */
  is_super?: number
}

export interface TenantListResult {
  list: TenantItem[]
  total: number
  page: number
  pagesize: number
}

export interface TenantListQuery {
  page?: number
  pagesize?: number
  /** ID 筛选（展示语义：1=admin，N=真实 id N-1） */
  id?: string
  /** 账号/联系人/电话 */
  account?: string
  /** 公众号名称/小程序名称 */
  keyword?: string
  status?: number | ''
  sort_field?: string
  sort_order?: string
}

export interface TenantAddParams {
  username: string
  company: string
  contacts: string
  phone: string
  remark: string
  expire_days: number
  password?: string
  status?: number
}

export interface TenantEditParams {
  id: number
  username?: string
  password?: string
  contacts?: string
  phone?: string
  remark?: string
  endtime?: string
  status?: number
}

// 代登录返回
export interface ProxyLoginResult {
  token: string
  username: string
}

// ============ 开放平台 ============

// 平台设置（GET /console/open/config）
export interface OpenConfigData {
  status: number // 0关闭 1开启
  component_appid: string
  component_appsecret: string // 明文 AppSecret（控制台页回显，对照参考系统）
  appsecret_masked: string // 脱敏后的 secret，仅作占位展示
  token: string
  encoding_aes_key: string
  kfappid: string // 开发小程序AppID
  ip_whitelist: string
  auth_domain: string
  mock_mode: number // 0关闭 1开启（模拟模式）
  domain: string // 当前站点域名（去协议头，只读项）
  server_ip: string // 服务器出口IP（只读项）
  event_url: string
  msg_url_pattern: string
  callback_url: string
}

// 平台设置保存参数（POST /console/open/config）
export interface OpenConfigSaveParams {
  status: number
  component_appid: string
  component_appsecret: string // 传空串表示不修改
  token: string
  encoding_aes_key: string
  kfappid?: string
  ip_whitelist?: string
  auth_domain?: string
  mock_mode?: number
}

// 授权账号
export interface AuthorizerItem {
  id: number
  authorizer_appid: string
  nick_name: string
  head_img: string
  principal_name: string
  verify_type_id: number // -1未认证 0微信认证
  service_type_id: number
  account_type: string // official公众号 mini小程序（后端根据 service_type_id 计算）
  func_info: unknown // 权限集（数组或 JSON 字符串）
  auth_status: number // 1已授权 0已解绑
  auth_time: number
  unauth_time: number
  tenant_username: string
}

// 授权账号完整详情（GET /console/open/authorizer/detail）
export interface AuthorizerDetail extends AuthorizerItem {
  aid: number | null
  user_name: string // 原始ID（gh_开头）
  qrcode_url: string
  refresh_token: string // 脱敏后（前8位+****）
  account_status: number
  refresh_alert: string
  create_time: number
  update_time: number
}

export interface AuthorizerListResult {
  list: AuthorizerItem[]
  total: number
  page: number
  pagesize: number
}

export interface AuthorizerListQuery {
  page?: number
  pagesize?: number
  nick_name?: string // 昵称模糊筛选
  authorizer_appid?: string // AppID 模糊筛选
  account_type?: string // official公众号 mini小程序
}

// 发起授权
export interface BindUrlParams {
  auth_type: number // 1公众号 2小程序 3都有
  tenant_id?: number
}

export interface BindUrlResult {
  url: string
}

// ============ 租户后台：网约车 ============
export interface CarCategoryItem {
  id: number
  name: string
  sort: number
  status: number
  car_count: number
  create_time: number
}

export interface CarCategoryListResult {
  list: CarCategoryItem[]
  total: number
  page: number
  pagesize: number
}

export interface CarCategorySaveParams {
  id?: number
  name: string
  sort: number
  status: number
}

export interface CarProductItem {
  id: number
  category_id: number
  category_name: string
  name: string
  pic: string
  price_day: number
  price_half: number
  car_num: number
  status: number
  create_time: number
  // 编辑回填用：列表契约未列出，后端若返回则直接回填
  desc?: string
}

export interface CarProductListResult {
  list: CarProductItem[]
  total: number
  page: number
  pagesize: number
}

export interface CarProductListQuery {
  page?: number
  pagesize?: number
  keyword?: string
  category_id?: number | ''
  status?: number | ''
}

export interface CarProductSaveParams {
  id?: number
  name: string
  category_id: number
  pic: string
  price_day: number
  price_half: number
  car_num: number
  desc: string
  status: number
}

export interface CarOrderItem {
  id: number
  order_sn: string
  member_phone: string
  product_name: string
  start_time: number
  end_time: number
  days: number
  pay_amount: number
  status: number
  status_text: string
  pay_time: number
  create_time: number
}

export interface CarOrderListResult {
  list: CarOrderItem[]
  total: number
  page: number
  pagesize: number
}

export interface CarOrderListQuery {
  page?: number
  pagesize?: number
  status?: number | ''
  keyword?: string
}

// ============ 租户后台：酒店 ============
export interface HotelItem {
  id: number
  name: string
  star: number
  address: string
  contacts: string
  phone: string
  room_count: number
  status: number
  create_time: number
  // 编辑回填用：列表契约未列出，后端若返回则直接回填
  province?: string
  city?: string
  district?: string
  lng?: number
  lat?: number
  pic?: string
  desc?: string
}

export interface HotelListResult {
  list: HotelItem[]
  total: number
  page: number
  pagesize: number
}

export interface HotelListQuery {
  page?: number
  pagesize?: number
  keyword?: string
  status?: number | ''
}

export interface HotelSaveParams {
  id?: number
  name: string
  star: number
  province: string
  city: string
  district: string
  address: string
  lng: number
  lat: number
  pic: string
  contacts: string
  phone: string
  desc: string
  status: number
}

export interface HotelRoomItem {
  id: number
  hotel_id: number
  hotel_name: string
  name: string
  bed_type: string
  area: number
  price: number
  deposit: number
  room_num: number
  status: number
  create_time: number
  // 编辑回填用：列表契约未列出，后端若返回则直接回填
  pic?: string
  desc?: string
}

export interface HotelRoomListResult {
  list: HotelRoomItem[]
  total: number
  page: number
  pagesize: number
}

export interface HotelRoomListQuery {
  hotel_id?: number | ''
  page?: number
  pagesize?: number
  field?: string
  order?: string
}

export interface HotelRoomSaveParams {
  id?: number
  hotel_id: number
  name: string
  bed_type: string
  area: number
  price: number
  deposit: number
  room_num: number
  pic: string
  desc: string
  status: number
}

export interface HotelOrderItem {
  id: number
  order_sn: string
  member_phone: string
  hotel_name: string
  room_name: string
  checkin_date: string
  checkout_date: string
  nights: number
  pay_amount: number
  deposit_total: number
  status: number
  status_text: string
  name: string
  phone: string
  create_time: number
}

export interface HotelOrderListResult {
  list: HotelOrderItem[]
  total: number
  page: number
  pagesize: number
}

export interface HotelOrderListQuery {
  page?: number
  pagesize?: number
  status?: number | ''
  keyword?: string
}

// ============ 租户后台：预约 ============
export interface YuyueCategoryItem {
  id: number
  name: string
  pic?: string
  sort: number
  status?: number
  create_time?: number
  product_count?: number
  pid?: number
  depth?: number
  children?: YuyueCategoryItem[]
}

export interface YuyueCategoryListResult {
  list: YuyueCategoryItem[]
}

export interface YuyueCategorySaveParams {
  id?: number
  name: string
  pic?: string
  sort: number
  status?: number
}

export interface YuyueProductItem {
  id: number
  name: string
  category_id: number
  category_name: string
  pic: string
  price: number
  duration: number
  desc: string
  status: number
  create_time: number
}

export interface YuyueProductListResult {
  list: YuyueProductItem[]
  total: number
  page: number
  pagesize: number
}

export interface YuyueProductListQuery {
  page?: number
  pagesize?: number
  keyword?: string
  category_id?: number | ''
  status?: number | ''
  /** 分组ID */
  gid?: number | ''
  /** 排序字段：id/stock/sales/sort/createtime */
  sort_field?: string
  /** 排序方向：asc/desc */
  sort_order?: string
  /** 商户维度：空/all=全部 0=自营 gt0=商户（任务177） */
  bid?: string
}

export interface YuyueProductSaveParams {
  id?: number
  name: string
  category_id: number
  pic: string
  price: number
  desc: string
  duration: number
  status: number
}

export interface YuyueWorkerItem {
  id: number
  name: string
  pic: string
  phone: string
  desc: string
  status: number
  order_count: number
  create_time: number
}

export interface YuyueWorkerListResult {
  list: YuyueWorkerItem[]
  total: number
  page: number
  pagesize: number
}

export interface YuyueWorkerListQuery {
  page?: number
  pagesize?: number
  realname?: string
  status?: number | ''
}

export interface YuyueWorkerSaveParams {
  id?: number
  un?: string
  pwd?: string
  mid?: number
  realname?: string
  tel?: string
  cid?: number
  headimg?: string
  jineng?: string
  desc?: string
  sort?: number
  status?: number
}

export interface YuyueOrderItem {
  id: number
  order_sn: string
  member_phone: string
  product_name: string
  worker_name: string
  book_date: string
  book_time: string
  pay_amount: number
  status: number
  status_text: string
  hexiao_code: string
  create_time: number
}

export interface YuyueOrderListResult {
  list: YuyueOrderItem[]
  total: number
  page: number
  pagesize: number
}

export interface YuyueOrderListQuery {
  page?: number
  pagesize?: number
  status?: number | ''
  keyword?: string
}

// ============ 租户后台：预约域批次8（任务222） ============
// 商品服务
export interface YuyueFuwuItem {
  id: number
  name: string
  desc: string
  status: number
  sort: number
  createtime: number
}

export interface YuyueFuwuListQuery {
  page?: number
  pagesize?: number
  name?: string
  status?: number | ''
  bid?: string
}

export interface YuyueFuwuListResult {
  list: YuyueFuwuItem[]
  total: number
  page: number
  pagesize: number
}

export interface YuyueFuwuSaveParams {
  id?: number
  name: string
  desc?: string
  status?: number
  sort?: number
}

// 商品评价
export interface YuyueCommentItem {
  id: number
  mid: number
  proid: number
  proname: string
  propic: string
  ggname: string
  ordernum: string
  nickname: string
  headimg: string
  score: number
  content: string
  content_pic: string
  pics: string[]
  reply_content: string
  reply_time: number
  append_content: string
  status: number
  createtime: number
}

export interface YuyueCommentListQuery {
  page?: number
  pagesize?: number
  content?: string
  status?: number | ''
  start_date?: string
  end_date?: string
  bid?: string
}

export interface YuyueCommentListResult {
  list: YuyueCommentItem[]
  total: number
  page: number
  pagesize: number
}

// 人员类型
export interface YuyueWorkerCategoryItem {
  id: number
  pid: number
  name: string
  pic: string
  status: number
  sort: number
  createtime: number
}

export interface YuyueWorkerCategorySaveParams {
  id?: number
  pid: number
  name: string
  pic?: string
  status?: number
  sort?: number
}

// 人员评价
export interface YuyueWorkerCommentItem {
  id: number
  mid: number
  worker_id: number
  worker_name: string
  worker_tel: string
  ordernum: string
  nickname: string
  headimg: string
  score: number
  content: string
  content_pic: string
  pics: string[]
  reply_content: string
  status: number
  createtime: number
}

export interface YuyueWorkerCommentListQuery {
  page?: number
  pagesize?: number
  worker_id?: number | ''
  content?: string
  start_date?: string
  end_date?: string
  bid?: string
}

export interface YuyueWorkerCommentListResult {
  list: YuyueWorkerCommentItem[]
  total: number
  page: number
  pagesize: number
}

// 提成明细
export interface YuyueMoneyLogItem {
  id: number
  uid: number
  realname: string
  tel: string
  money: string | number
  after: string | number
  remark: string
  createtime: number
}

export interface YuyueMoneyLogListQuery {
  page?: number
  pagesize?: number
  realname?: string
  uid?: number | ''
  bid?: string
}

export interface YuyueMoneyLogListResult {
  list: YuyueMoneyLogItem[]
  total: number
  page: number
  pagesize: number
}

export interface YuyueMoneyLogExportResult {
  title: string[]
  data: string[][]
  count: number
}

// 提现记录
export interface YuyueWithdrawLogItem {
  id: number
  uid: number
  realname: string
  tel: string
  money: string | number
  txmoney: string | number
  paytype: string
  weixin: string
  aliaccount: string
  bankname: string
  bankcarduser: string
  bankcardnum: string
  ordernum: string
  status: number
  status_text: string
  reason: string
  createtime: number
  paytime: number
  paynum: string
}

export interface YuyueWithdrawLogListQuery {
  page?: number
  pagesize?: number
  realname?: string
  uid?: number | ''
  status?: number | ''
  bid?: string
}

export interface YuyueWithdrawLogListResult {
  list: YuyueWithdrawLogItem[]
  total: number
  page: number
  pagesize: number
}

// 系统设置
export interface YuyueSetConfig {
  id: number
  status: number
  paidantype: number
  withdraw_weixin: number
  withdraw_aliaccount: number
  withdraw_bankcard: number
  withdrawmin: string
  withdrawfee: string
  businessst: number
  businessfee: string | number
  diyname: string
  autoshdays: number
  autoclose: number
  minminute: number
  pic: string
  desc: string
  zaohour: number
  wanhour: number
  timejg: number
  timepoint: string
  rqtype: number
  yybegintime: string
  yyendtime: string
  yyzhouqi: string[]
  pdprehour: number
  detailpic: string
  discount: number
  iscoupon: number
  formurl: string
  formdata: string
  apply_paymoney: string | number
  xieyi_show: number
  xieyi: string
  sign_juli: string | number
  ad_status: number
  ad_pic: string
  ad_link: string
  video_status: number
  video_tag: string
  video_title: string
  yuyue_numtext: string
  yuyue_success: string
  comment_check: number
  isautopd: number
  show_free: number
  finish_confirm: number
  confirm_type: number
  autoconfirm: number
  [key: string]: unknown
}

export type YuyueSetSaveParams = Partial<Omit<YuyueSetConfig, 'yyzhouqi'>> & {
  yyzhouqi?: string[]
}

// ============ 租户后台：餐饮 ============
export interface RestaurantAreaItem {
  id: number
  name: string
  sort: number
  table_count: number
}

export interface RestaurantAreaListResult {
  list: RestaurantAreaItem[]
}

export interface RestaurantAreaSaveParams {
  id?: number
  name: string
  sort: number
}

export interface RestaurantTableItem {
  id: number
  area_id: number
  area_name: string
  name: string
  persons: number
  sort: number
  busy: number
}

export interface RestaurantTableListResult {
  list: RestaurantTableItem[]
  total: number
  page: number
  pagesize: number
}

export interface RestaurantTableListQuery {
  area_id?: number | ''
  page?: number
  pagesize?: number
  field?: string
  order?: string
}

export interface RestaurantTableSaveParams {
  id?: number
  area_id: number
  name: string
  persons: number
  sort: number
}

export interface RestaurantCategoryItem {
  id: number
  name: string
  sort: number
  product_count: number
}

export interface RestaurantCategoryListResult {
  list: RestaurantCategoryItem[]
}

export interface RestaurantCategorySaveParams {
  id?: number
  name: string
  sort: number
}

export interface RestaurantProductItem {
  id: number
  name: string
  category_id: number
  category_name: string
  pic: string
  price: number
  desc: string
  status: number
  create_time: number
}

export interface RestaurantProductListResult {
  list: RestaurantProductItem[]
  total: number
  page: number
  pagesize: number
}

export interface RestaurantProductListQuery {
  page?: number
  pagesize?: number
  keyword?: string
  category_id?: number | ''
  status?: number | ''
}

export interface RestaurantProductSaveParams {
  id?: number
  name: string
  category_id: number
  pic: string
  price: number
  desc: string
  status: number
}

export interface RestaurantOrderGoods {
  name: string
  num: number
  price: number
}

export interface RestaurantOrderItem {
  id: number
  order_sn: string
  table_name: string
  goods: RestaurantOrderGoods[]
  amount: number
  pay_amount: number
  status: number
  status_text: string
  pay_time: number
  create_time: number
}

export interface RestaurantOrderListResult {
  list: RestaurantOrderItem[]
  total: number
  page: number
  pagesize: number
}

export interface RestaurantOrderListQuery {
  page?: number
  pagesize?: number
  status?: number | ''
  keyword?: string
}

// ============ 租户统计（首页面板 + 趋势 + 销售统计） ============

// 首页面板汇总（GET /admin/dashboard）
export interface AdminDashboardData {
  wait_pay: number
  wait_ship: number
  shipped: number
  received: number
  wait_refund: number
  today_money: number
  today_orders: number
  month_money: number
  month_orders: number
  total_money: number
  total_orders: number
  member_total: number
  member_today: number
  member_month: number
  balance_total: number
  spend_total: number
  recharge_total: number
  refund_money: number
}

// 单日趋势点
export interface TrendItem {
  date: string
  money: number
  orders: number
  members: number
}

// 趋势返回（GET /admin/stat/trend）
export interface TrendResult {
  days: number
  list: TrendItem[]
}

// 销售统计单期
export interface SalesStatItem {
  label: string
  val: number
  percent: number
}

// 销售统计返回（GET /admin/stat/sales）
export interface SalesStatResult {
  level: 'year' | 'month' | 'day'
  tjtype: number
  year: number
  month: number
  day: number
  list: SalesStatItem[]
  totalval: number
  maxval: number
  maxdate: string
}

// 商品销售统计查询（GET /admin/stat/goods_sales）
export interface GoodsSalesQuery {
  type?: number // 2=按规格统计，其它=按商品统计
  page?: number
  limit?: number
  field?: string // 排序字段：num/totalprice/avgprice/chengben/lirun
  order?: 'asc' | 'desc'
  mdid?: number | '' // 门店
  freight_id?: number | '' // 配送方式
  mid?: number | '' // 会员ID
  proname?: string // 商品名称
  ctime_start?: number // 下单时间起（Unix 秒，og.createtime）
  ctime_end?: number
  paytime_start?: number // 付款时间起（Unix 秒，order.paytime）
  paytime_end?: number
}

// 商品销售统计行
export interface GoodsSalesRow {
  ph: number
  proid: number
  ggid: number
  name: string
  pic: string
  ggname: string
  num: number
  totalprice: number
  avgprice: number
  chengben: number
  lirun: number
}

// 商品销售统计汇总
export interface GoodsSalesTotal {
  num: number
  totalprice: number
  chengben: number
  lirun: number
}

// 商品销售统计返回
export interface GoodsSalesResult {
  list: GoodsSalesRow[]
  total: number
  totaldata: GoodsSalesTotal
}

// 销售统计查询参数
export interface SalesStatQuery {
  year?: number
  month?: number
  day?: number
  tjtype?: number
}

// ============ 会员管理（任务138） ============

// 会员列表行（GET /admin/member/list）
export interface MemberItem {
  id: number
  headimg: string
  nickname: string
  realname: string
  tel: string
  levelid: number
  level_name: string
  money: number
  score: number
  buynum: number
  buymoney: number
  createtime: number
  last_visittime: number
  isfreeze: number
  islock: number
  remark: string
}

export interface MemberListQuery {
  page?: number
  pagesize?: number
  keyword?: string
  levelid?: number
  start_time?: string
  end_time?: string
}

export interface MemberListResult {
  list: MemberItem[]
  total: number
  page: number
  pagesize: number
}

// 会员详情（GET /admin/member/detail）
export interface MemberDetail extends MemberItem {
  sex: string
  levelendtime: number
  last_buytime: number
  platform: string
  order_count: number
  order_amount: number
  address_count: number
}

// 会员编辑参数（POST /admin/member/edit）
export interface MemberEditParams {
  id: number
  nickname: string
  tel: string
  levelid: number
  levelendtime?: string
  remark: string
}

// 余额流水行（GET /admin/member/moneylog）
export interface MemberMoneyLogItem {
  id: number
  mid: number
  money: number
  after: number
  remark: string
  paytype: string
  createtime: number
}

// 积分流水行（GET /admin/member/scorelog）
export interface MemberScoreLogItem {
  id: number
  mid: number
  score: number
  after: number
  remark: string
  channel: string
  createtime: number
}

// 会员订单行（GET /admin/member/orders）
export interface MemberOrderItem {
  id: number
  ordernum: string
  totalprice: number
  status: number
  createtime: number
}

// 收货地址行（GET /admin/member/address）
export interface MemberAddressItem {
  id: number
  name: string
  tel: string
  region: string
  address: string
  isdefault: number
  createtime: number
}

// 明细分页通用返回
export interface MemberLogResult<T> {
  list: T[]
  total: number
  page: number
  pagesize: number
}

// 会员等级行（GET /admin/member/level/list）
export interface MemberLevelItem {
  id: number
  name: string
  icon: string
  sort: number
  discount: number
  isdefault: number
  can_apply: number
  can_up: number
  can_agent: number
  applytj: string
  apply_paymoney: number
  uptj: string
  commission: string
  up_ordermoney: number
  up_rechargemoney: number
  up_orderprice: number
  member_count: number
}

// 等级保存参数（POST /admin/member/level/save）
export interface MemberLevelSaveParams {
  id: number
  name: string
  icon: string
  sort: number
  discount: number
  isdefault?: number
  up_ordermoney?: number
  up_rechargemoney?: number
  up_orderprice?: number
  maxnum?: number
  // ---- 申请设置组（主动申请升级） ----
  can_up?: number
  can_apply?: number
  apply_check?: number
  apply_ordermoney?: number
  apply_rechargemoney?: number
  apply_paymoney?: number
  apply_paytxt?: string
  // ---- 升级条件组（P19-2） ----
  up_wxpaymoney?: number
  up_perpaymoney?: number
  up_getmembercard?: number
  up_fxordermoney?: number
  up_fxorderlevelnum?: string
  up_fxorderlevelid?: string
  up_fxdowncount?: number
  up_fxdownlevelnum?: string
  up_fxdownlevelid?: string
  up_fxdowncount2?: number
  up_fxdownlevelnum2?: string
  up_fxdownlevelid2?: string
  up_fxdowncount3?: number
  up_fxdownlevelnum3?: string
  up_fxdownlevelid3?: string
  up_proid?: string
  up_pronum?: string
  up_catid?: string
  up_cat_ordermoney?: number
  gettj?: string
  // ---- 分销配置组 ----
  can_agent?: number
  agent_rule?: number
  commissionset?: number
  commissiontype?: number
  commission1?: number
  commission2?: number
  commission3?: number
  commission4?: number
  commission5?: number
  commission6?: number
  commission_parent?: number
  commission_parent_pj?: number
  commission_appointlevelid?: string
  commission_max?: number
  fx_jinsuo?: number
  // ---- 推荐积分（一/二/三级 + 每人最高限制） ----
  score1?: number
  score2?: number
  score3?: number
  scoremax?: number
  // ---- 分红配置组 ----
  fenhong?: number
  fenhong_num?: number
  fenhong_max_money?: number
  teamfenhonglv?: number
  teamfenhongbl?: number
  teamfenhong_money?: number
  areafenhong?: number
  areafenhongbl?: number
  areafenhongmaxnum?: number
  // ---- 升级赠送组 ----
  up_give_score?: number
  up_give_commission?: number
  up_give_money?: number
  up_give_parent_money?: number
  // ---- 自己拿一级提成 / 我的团队权限 / 特权说明 ----
  commission1own?: number
  team_showtel?: number
  team_givemoney?: number
  team_givescore?: number
  explain?: string
}

// 等级编辑详情（GET /admin/member/level/detail，P19-2）
export type MemberLevelDetail = Required<MemberLevelSaveParams>

// 区域代理归属记录（GET /admin/member/level/record/list）
export interface MemberLevelRecordItem {
  id: number
  mid: number
  nickname: string
  tel: string
  areafenhong: number
  areafenhongbl: number
  areafenhong_province: string
  areafenhong_city: string
  areafenhong_area: string
  createtime: number
}

// 区域代理归属保存（POST /admin/member/level/record/save）
export interface MemberLevelRecordSaveParams {
  id: number
  areafenhong?: number
  areafenhongbl?: number
  areafenhong_province?: string
  areafenhong_city?: string
  areafenhong_area?: string
}

// ============ 租户系统设置（admin_set 白名单字段） ============
// 注册协议（admin_set_xieyi 独立表，随 /admin/sysset 一并读写）
export interface SyssetXyinfo {
  status: number // 0关 1开
  agree_type: number // 0打勾同意 1阅读到最后
  name: string // 协议名称
  content: string // 协议内容（富文本 HTML）
  name2: string // 隐私政策名称
  content2: string // 隐私政策内容（富文本 HTML）
}

export interface TenantSyssetData {
  // 基本信息
  name: string
  logo: string
  desc: string
  tel: string
  province: string
  city: string
  district: string
  address: string
  longitude: string
  latitude: string
  main_business: string // 主营业务
  official_account_status: number // 公众号关注组件 0关 1开
  // 基础设置增量（任务308）
  guanzhu_tip: string // 关注提示 CSV 1首页 2商品详情页
  order_broadcast: string // 订单播报 CSV 1首页 2商品详情页
  navbgmode: number // 导航背景颜色 0跟随系统主色调 1自定义
  navbgcolor: string // 导航自定义背景颜色
  navtextcolor: number // 导航文字颜色 0黑色 1白色
  visitperm: string // 系统前端访问条件 CSV all所有人 member普通会员 dealer经销商
  // 客服
  kfurl: string
  wxkf: number // 0关 1开
  wxkfurl: string
  // 资金开关
  moneypay: number
  recharge: number
  recharge_minimum: string // 充值门槛（0为不限制）
  withdraw: number
  comwithdraw: number
  withdrawmin: string
  withdrawfee: string
  withdrawmax: number // 单笔最高提现金额（0为无限制）
  comwithdrawmin: string
  comwithdrawmax: number // 0为无限制
  comwithdrawfee: string // 单位 %
  day_withdraw_num: number // 每日提现总次数（0为无限制）
  withdraw_desc: string // 提现说明
  withdraw_weixin: number // 提现方式：微信
  withdraw_aliaccount: number // 提现方式：支付宝
  withdraw_bankcard: number // 提现方式：银行卡
  // 积分
  score2money: string
  scoredkmaxpercent: string
  scorein_money: string
  scorein_score: number
  scorecz_money: string
  scorecz_score: number
  scorebdkyf: number // 积分不抵扣运费 0关 1开
  score_from_moneypay: number // 余额支付送积分 0关 1开
  score_transfer: number // 积分转赠 0关 1开
  score_expire_status: number // 积分过期开关 0关 1开
  score_expire_days: number // 过期天数（0为不过期）
  // 登录注册
  logintype_wx: string // 小程序端登录方式 CSV：1注册登录 2手机号登录 3授权登录
  logintype_h5: string // H5端登录方式 CSV：1注册登录 2手机号登录
  login_setnickname: number // 0不需要设置 1可选设置 2强制设置
  login_bind: number // 0不绑定 1可选绑定 2强制绑定
  reg_check: number // 注册审核 0关 1开
  reg_invite_code: number // 0关闭 1开启 2强制邀请
  // 加载图标
  loading_icon: string
  // 文本自定义（键=原词，值=替换词）
  textset: Record<string, string>
  // 注册协议
  xyinfo: SyssetXyinfo
  // 主题
  color1: string
  color2: string
  // 分销（参考系统 admin_set 分销板块口径）
  fxjiesuantype: number // 分销结算方式 0按商品价格 1按成交价格 2按销售利润
  fxjiesuantype_pj: number // 平级奖结算方式 0按上级奖励 1按商品价格 2按成交价格 3按销售利润
  fxjiesuantime: number // 结算时间 0确认收货后 1付款后
  fxjiesuantime_delaydays: string // 延迟结算天数（周结时为星期几）
  fxjiesuantime_perweek: number // 结算周期 0日结 1周结
  fx_differential: number // 分销级差 0关 1开
  commission_send_wallet: number // 佣金发放钱包 0到佣金 1到余额
  commission_send_wallet_levelids: string // 发放余额等级限制（逗号分隔，-1不限制）
  // 发票
  invoice: number
  invoice_type: string
  invoice_rate: string
  // 关停
  status: number
  close_tip: string
}

// ============ 配送方式（freight） ============
// 计费地区分组行
export interface FreightPriceRow {
  region: string // 地区名称
  fristweight: number // 首重（克）/首件数
  fristprice: string // 首价
  secondweight: number // 续量
  secondprice: string // 续价
}

// 配送/提货时段行
export interface FreightTimeRow {
  day: number // 1当天 2~15第N天
  hour: number
  minute: number
  hour2: number
  minute2: number
}

// 下单附加表单项
export interface FreightFormRow {
  key: string // input/textarea/radio/checkbox/selector/time/date/upload
  val1: string // 字段名称
  val2: string // 字段内容/占位
  val3: number // 是否必填 1必填 0选填
}

export interface FreightItem {
  id: number
  name: string
  pstype: number // 0快递 1到店自提 2同城配送 3自动发货 5门店配送 6预约服务
  type: number // 1按重量克 2按件数
  pricedata: FreightPriceRow[]
  freeset: number // 满额免邮开关
  free_price: string
  minpriceset: number // 最低消费开关
  minprice: string
  minnumset: number // 最低件数开关
  minnum: number
  status: number
  sort: number
  createtime?: number
  // 门店（pstype 1/5/6）
  storetype?: number // 0全部 1指定门店
  storeids?: number[]
  // 自动发货（pstype 3）
  pscontenttype?: number // 0固定信息 1在线卡密
  pscontent?: string
  needlinkinfo?: number // 联系方式是否必填
  fwprice?: string // 服务费
  // 配送/提货时段
  pstimeset?: number
  pstimedata?: FreightTimeRow[]
  psprehour?: number // 提前小时数
  // 同城配送（pstype 2）
  peisong_juli1?: string
  peisong_fee1?: string
  peisong_juli2?: string
  peisong_fee2?: string
  peisong_lng?: string
  peisong_lat?: string
  peisong_range?: number // 圆形范围半径（米）
  peisong_rangetype?: number // 0圆形 1多边形
  peisong_rangepath?: string // 多边形路径
  peisong_lng2?: string
  peisong_lat2?: string
  // 下单附加表单
  formdata?: FreightFormRow[]
}

export interface FreightMapConfig {
  map_type: string // 地图类型：amap 高德（对照参考系统），空为未配置
  map_key: string // 地图 JS API key
  map_jscode: string // 高德安全密钥
  map_qq_key: string // 腾讯地图 key（预留）
}

export interface FreightListResult {
  list: FreightItem[]
  map_config?: FreightMapConfig
}

export type FreightSaveParams = Omit<FreightItem, 'createtime'>

// ============ 快递设置（express_data） ============
// 公司名 => 编码 映射
export type ExpressDataMap = Record<string, string>

export interface ExpressDataResult {
  dict: ExpressDataMap // 内置候选字典
  data: ExpressDataMap // 已启用
}

// ============ 租户后台：商品评价管理 ============

export interface CommentItem {
  id: number
  mid: number
  nickname: string
  headimg: string
  orderid: number
  ordernum: string
  proid: number
  proname: string
  propic: string
  ggname: string
  score: number // 1-5
  content: string
  content_pic: string[]
  reply_content: string
  reply_content_pic: string[]
  append_content: string
  status: number // 0待审核 1已通过 2已驳回
  createtime: number // Unix 秒
  reply_time: number // Unix 秒
}

export interface CommentStat {
  total: number
  avg_score: number
  good_percent: number
}

export interface CommentListQuery {
  page?: number
  pagesize?: number
  keyword?: string
  score?: number | ''
  status?: number | ''
  start_time?: number
  end_time?: number
  /** 商户维度：空/all=全部 0=自营 gt0=商户（任务177） */
  bid?: string
  /** 排序字段 id|score|createtime；方向 asc|desc，默认 id desc */
  sort_field?: string
  sort_order?: string
}

export interface CommentListResult {
  list: CommentItem[]
  total: number
  page: number
  pagesize: number
  stat: CommentStat
}

// 虚拟评价新增/编辑参数
export interface CommentSaveParams {
  id?: number
  proid?: number
  proname: string
  propic?: string
  ggname?: string
  score: number
  content: string
  content_pic?: string
  nickname: string
  headimg?: string
  createtime?: number
}

// 评价开关（shop_sysset）
export interface CommentSysset {
  comment: number // 评价总开关
  comment_check: number // 审核开关
}

// ============ 多人拼团（collage） ============

// 拼团设置（GET/POST /admin/collage/set）
export interface CollageSyssetData {
  teamnum: number // 默认成团人数
  teamhour: number // 默认成团时限（小时）
  leadermoney: number // 默认团长优惠金额
  autoshdays: number // 自动收货天数
  comment: number // 商品评价开关 0关 1开
  comment_check: number // 评价审核 0关 1开
  showjd: number // 进店及咨询 0关 1开
  timeset: string // 场次配置
  team_refund: number // 团长退款是否解散团 0否 1是
  pics: string // 活动说明图
}

export type CollageSyssetSaveParams = Partial<CollageSyssetData>

// 拼团商品规格行（collage_guige）
export interface CollageGuigeOption {
  id?: number
  name: string
  sell_price: number | string
  market_price: number | string
  cost_price?: number | string
  stock: number
}

// 拼团商品行
export interface CollageProductItem {
  id: number
  name: string
  pic: string
  cid: number
  sellpoint: string
  sell_price: number
  market_price: number
  stock: number
  sales: number
  teamnum: number
  teamhour: number
  leadermoney: number
  buymax: number
  sort: number
  status: number // 0下架 1上架
  ischecked: number // 0待审核 1通过 2驳回
  check_reason: string
  detail: string
  createtime: number
}

// 商品详情（含规格行）
export interface CollageProductDetail extends CollageProductItem {
  options: CollageGuigeOption[]
}

export interface CollageProductListQuery {
  page?: number
  pagesize?: number
  keyword?: string
  status?: number | ''
  checked?: number | ''
  /** 商户维度：空/all=全部 0=自营 gt0=商户（任务177） */
  bid?: string
}

export interface CollageProductListResult {
  list: CollageProductItem[]
  total: number
  page: number
  pagesize: number
}

export interface CollageProductSaveParams {
  id?: number
  name: string
  pic: string
  cid?: number
  sellpoint?: string
  teamnum: number
  teamhour: number
  leadermoney?: number | string
  buymax?: number
  sort?: number
  status?: number
  detail?: string
  options?: CollageGuigeOption[]
}

// 拼团成员/下单人
export interface CollageBuyer {
  mid: number
  nickname: string
  headimg: string
  isjiqiren: number // 0真人 1机器人
}

// 团行（GET /admin/collage/team/list）
export interface CollageTeamItem {
  id: number
  proid: number
  product_name: string
  teamnum: number // 成团人数
  num: number // 当前已支付成员数
  status: number // 0未支付 1进行中 2成功 3失败
  status_text: string
  members: CollageBuyer[]
  createtime: number
  deadline: number // 截止（createtime + teamhour*3600）
}

export interface CollageTeamListQuery {
  page?: number
  pagesize?: number
  status?: number | ''
}

export interface CollageTeamListResult {
  list: CollageTeamItem[]
  total: number
  page: number
  pagesize: number
}

// 订单行内团摘要
export interface CollageOrderTeamBrief {
  id: number
  status: number
  status_text: string
  teamnum: number
  num: number
}

// 拼团订单行（GET /admin/collage/order/list）
export interface CollageOrderItem {
  id: number
  ordernum: string
  buytype: number // 1单买 2发团 3参团
  buytype_text: string
  teamid: number
  team: CollageOrderTeamBrief | null
  proname: string
  propic: string
  ggname: string
  num: number
  sell_price: number
  totalprice: number
  refund_money: number
  refund_reason: string
  status: number // 0未支付 1已支付 2已发货 3已收货 4关闭
  status_text: string
  refund_status: number // 0无 1审核中 2已同意 3已驳回
  refund_text: string
  buyer: CollageBuyer
  linkman: string
  tel: string
  address: string
  express_com: string
  express_no: string
  paytype: string
  paytime: number
  send_time: number
  createtime: number
}

export interface CollageOrderListQuery {
  page?: number
  pagesize?: number
  status?: string // 空/0-4/r1-r3
  buytype?: number | ''
  keyword?: string
  teamid?: number
}

export interface CollageOrderListResult {
  list: CollageOrderItem[]
  total: number
  page: number
  pagesize: number
}

// ============ 砍价活动（kanjia） ============

// 砍价设置（GET/POST /admin/kanjia/sysset）
export interface KanjiaSyssetData {
  pic: string // 活动横幅图
  autoshdays: number // 自动收货天数
  showjd: number // 砍价进度显示 0关 1开
  mastpay: number // 必须支付 0否 1是
}

export type KanjiaSyssetSaveParams = Partial<KanjiaSyssetData>

// 分段砍价金额行（kjdata）
export interface KanjiaSegment {
  startnum: number
  endnum: string // 数字或「最后一」
  startmoney: number | string
  endmoney: number | string
}

// 砍价商品行
export interface KanjiaProductItem {
  id: number
  name: string
  pic: string
  pics: string
  sell_price: number // 原价
  min_price: number // 底价
  helpnum: number // 砍价人数
  limitHour: number // 砍价时限（小时）
  stock: number
  sales: number
  saleing: number
  helpcount: number // 累计帮砍次数
  directbuy: number // 0须砍到底价 1可直接购买
  freighttype: number
  helpgive_type: number // 帮砍奖励 0积分 1余额
  helpgive_percent: number
  helpgive_ff: number // 0实发 1仅展示
  kjdata: string // 分段砍价金额 JSON
  sort: number
  status: number // 0下架 1上架
  detail: string
  createtime: number
  status_text: string
  directbuy_text: string
  helpgive_text: string
  ongoing_join_num: number
}

export interface KanjiaProductListQuery {
  page?: number
  pagesize?: number
  keyword?: string
  status?: number | ''
  /** 分组ID */
  gid?: number | ''
  /** 排序字段：id/stock/sales/sort/createtime */
  sort_field?: string
  /** 排序方向：asc/desc */
  sort_order?: string
  /** 商户维度：空/all=全部 0=自营 gt0=商户（任务177） */
  bid?: string
}

export interface KanjiaProductListResult {
  list: KanjiaProductItem[]
  total: number
  page: number
  pagesize: number
}

export interface KanjiaProductSaveParams {
  id?: number
  name: string
  pic: string
  pics?: string
  sell_price: number | string
  min_price: number | string
  helpnum: number
  limitHour: number
  stock: number
  directbuy?: number
  freighttype?: number
  helpgive_type?: number
  helpgive_percent?: number | string
  helpgive_ff?: number
  kjdata?: KanjiaSegment[] | string
  sort?: number
  status?: number
  detail?: string
}

// 发起记录行（GET /admin/kanjia/join/list）
export interface KanjiaJoinItem {
  id: number
  mid: number
  proid: number
  now_price: number // 当前价
  helpnum: number // 已帮砍人数
  createtime: number
  status: number // 0进行中 1已完成 2已失败
  status_text: string
  endtime: number
  isbuy: number // 0未下单 1已下单
  nickname: string
  headimg: string
  proname: string
  propic: string
  sell_price: number
  min_price: number
  target_helpnum: number
  limitHour: number
  deadtime: number // 截止时间戳
  leftsecond: number // 剩余秒数
  cut_percent: number // 砍价进度百分比
}

export interface KanjiaJoinListQuery {
  page?: number
  pagesize?: number
  keyword?: string
  status?: number | ''
  proid?: number
}

export interface KanjiaJoinListResult {
  list: KanjiaJoinItem[]
  total: number
  page: number
  pagesize: number
}

// 帮砍明细行（GET /admin/kanjia/join/help）
export interface KanjiaHelpItem {
  id: number
  mid: number
  helpmid: number
  joinid: number
  cut_price: number
  after_price: number
  createtime: number
  nickname: string
  headimg: string
}

// 砍价订单行（GET /admin/kanjia/order/list）
export interface KanjiaOrderItem {
  id: number
  ordernum: string
  mid: number
  proid: number
  proname: string
  propic: string
  num: number
  totalprice: number
  product_price: number // 成交价（砍后价）
  freight_price: number
  sell_price: number // 原价
  status: number // 0未支付 1已支付 2已发货 3已收货 4关闭
  status_text: string
  refund_status: number // 0无 1审核中 2已退款 3已驳回
  refund_text: string
  refund_money: number
  refund_reason: string
  refund_checkremark: string
  linkman: string
  tel: string
  area: string
  address: string
  message: string
  express_com: string
  express_no: string
  hexiao_code: string
  joinid: number
  paytype: string
  paytime: number
  send_time: number
  collect_time: number
  createtime: number
  remark: string
  nickname: string
  headimg: string
}

export interface KanjiaOrderListQuery {
  page?: number
  pagesize?: number
  status?: string // 空/0-4/r1-r3
  refund_status?: number | ''
  ordernum?: string
  proname?: string
  linkman?: string
  tel?: string
  start_date?: string
  end_date?: string
}

export interface KanjiaOrderListResult {
  list: KanjiaOrderItem[]
  total: number
  page: number
  pagesize: number
}

// 核销码行（GET /admin/kanjia/codelist/list）
export interface KanjiaCodelistItem {
  id: number
  proid: number
  content: string // 卡密内容
  ordernum: string
  orderid: number
  nickname: string
  createtime: number // 入库时间
  buytime: number // 售出时间
  status: number // 0未售出 1已售出
  status_text: string
  proname: string
}

export interface KanjiaCodelistListQuery {
  page?: number
  pagesize?: number
  proid?: number
  status?: number | ''
  keyword?: string
}

export interface KanjiaCodelistListResult {
  list: KanjiaCodelistItem[]
  total: number
  page: number
  pagesize: number
}

/* ================= 周期购 ================= */

// 周期购设置（GET /admin/cycle/sysset）
export interface CycleSyssetData {
  autoshdays: number // 自动收货天数
  comment: number // 评价开关 0关 1开
}

export interface CycleSyssetSaveParams {
  autoshdays?: number
  comment?: number
}

// 商品分类行（GET /admin/cycle/category/list）
export interface CycleCategoryItem {
  id: number
  name: string
  pic: string
  pid: number
  sort: number
  status: number // 0停用 1启用
  status_text: string
  product_num: number
  createtime: number
}

export interface CycleCategorySaveParams {
  id?: number
  name: string
  pic?: string
  pid?: number
  sort?: number
  status?: number
}

// 规格动态行
export interface CycleGuigeItem {
  id: number
  proid: number
  name: string
  cycle_days: number // 配送周期（天）
  qsnum: number // 期数（总次数）
  each_num: number // 每期配送数量
  sell_price: number // 价格
  stock: number
}

export interface CycleGuigeSaveItem {
  name?: string
  cycle_days: number
  qsnum: number
  each_num: number
  sell_price: number
  stock: number
}

// 商品行（GET /admin/cycle/product/list）
export interface CycleProductItem {
  id: number
  name: string
  pic: string
  cid: number
  cname: string
  sell_price: number
  stock: number
  sort: number
  status: number // 0下架 1上架
  status_text: string
  detail: string
  guige_list: CycleGuigeItem[]
  createtime: number
}

export interface CycleProductDetail extends CycleProductItem {}

export interface CycleProductListQuery {
  page?: number
  pagesize?: number
  keyword?: string
  status?: number | ''
  cid?: number | ''
  /** 商户维度：空/all=全部 0=自营 gt0=商户（任务177） */
  bid?: string
}

export interface CycleProductListResult {
  list: CycleProductItem[]
  total: number
  page: number
  pagesize: number
}

export interface CycleProductSaveParams {
  id?: number
  name: string
  pic?: string
  cid?: number
  sort?: number
  status?: number
  detail?: string
  guiges: CycleGuigeSaveItem[]
}

// 分期发货计划行
export interface CycleStageItem {
  id: number
  orderid: number
  cycle_number: number // 期数序号
  status: number // 0创建 1待发货 2已发货 3已完成
  status_text: string
  express_com: string
  express_no: string
  send_time: number
  collect_time: number
  plan_time: number
  createtime: number
}

// 订单行（GET /admin/cycle/order/list）
export interface CycleOrderItem {
  id: number
  ordernum: string
  mid: number
  proid: number
  proname: string
  propic: string
  guigeid: number
  buytype: number // 1包月 2包季 3包年
  buytype_text: string
  num: number
  totalprice: number
  status: number // 0未支付 1已支付 2已发货 3已收货 4关闭
  status_text: string
  refund_status: number // 0无 1审核中 2已退款 3已驳回
  refund_text: string
  refund_money: number
  refund_reason: string
  refund_checkremark: string
  linkman: string
  tel: string
  area: string
  address: string
  message: string
  express_com: string
  express_no: string
  paytype: string
  paytime: number
  send_time: number
  collect_time: number
  createtime: number
  remark: string
  nickname: string
  headimg: string
  stage_progress: { total: number; done: number; shipped: number }
}

export interface CycleOrderDetail extends CycleOrderItem {
  member: { id: number; nickname: string; headimg?: string; realname?: string; tel?: string }
  stage_list: CycleStageItem[]
}

export interface CycleOrderListQuery {
  page?: number
  pagesize?: number
  status?: string // 空/0-4/r1-r3
  ordernum?: string
  proname?: string
  linkman?: string
  tel?: string
  start_date?: string
  end_date?: string
}

export interface CycleOrderListResult {
  list: CycleOrderItem[]
  total: number
  page: number
  pagesize: number
}

