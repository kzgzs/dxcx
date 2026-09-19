import request from './request'
import type {
  HotelListQuery,
  HotelListResult,
  HotelOrderListQuery,
  HotelOrderListResult,
  HotelRoomListQuery,
  HotelRoomListResult,
  HotelRoomSaveParams,
  HotelSaveParams,
} from './types'

// ============ 酒店 ============
export function getHotelList(params: HotelListQuery) {
  return request.get<unknown, HotelListResult>('/admin/hotel/list', { params })
}

export function addHotel(data: HotelSaveParams) {
  return request.post<unknown, null>('/admin/hotel/add', data)
}

export function editHotel(data: HotelSaveParams) {
  return request.post<unknown, null>('/admin/hotel/edit', data)
}

export function deleteHotel(id: number) {
  return request.post<unknown, null>('/admin/hotel/delete', { id })
}

export function setHotelStatus(id: number, status: number) {
  return request.post<unknown, null>('/admin/hotel/status', { id, status })
}

// ============ 房型 ============
export function getHotelRoomList(params: HotelRoomListQuery) {
  return request.get<unknown, HotelRoomListResult>('/admin/hotel/room/list', { params })
}

export function addHotelRoom(data: HotelRoomSaveParams) {
  return request.post<unknown, null>('/admin/hotel/room/add', data)
}

export function editHotelRoom(data: HotelRoomSaveParams) {
  return request.post<unknown, null>('/admin/hotel/room/edit', data)
}

export function deleteHotelRoom(id: number) {
  return request.post<unknown, null>('/admin/hotel/room/delete', { id })
}

export function setHotelRoomStatus(id: number, status: number) {
  return request.post<unknown, null>('/admin/hotel/room/status', { id, status })
}

// ============ 酒店订单 ============
export function getHotelOrderList(params: HotelOrderListQuery) {
  return request.get<unknown, HotelOrderListResult>('/admin/hotel/order/list', { params })
}

export function checkinHotelOrder(id: number) {
  return request.post<unknown, null>('/admin/hotel/order/checkin', { id })
}

export function finishHotelOrder(id: number) {
  return request.post<unknown, null>('/admin/hotel/order/finish', { id })
}
