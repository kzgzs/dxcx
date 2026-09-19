import request from './request'

// ============ 音乐控制（admin_music，任务311/315） ============

/** 音乐记录 */
export interface MusicRow {
  id: number
  name: string
  url: string
  size: string
  duration: number
  createtime: number
}

/** 音乐列表返回（GET {base}/list） */
export interface MusicListData {
  list: MusicRow[]
  total: number
}

/** 音频上传返回（附 size 展示值） */
export interface AudioUploadResult {
  url: string
  size?: string
}

export interface MusicApi {
  /** 音乐列表（keyword 搜索 + page/size 分页，size 上限 100） */
  getMusicList(params: { keyword?: string; page?: number; size?: number }): Promise<MusicListData>
  /** 添加音乐（名称 + 音频URL） */
  addMusic(data: { name: string; url: string; size?: string; duration?: number }): Promise<{ id: number }>
  /** 删除音乐（本地文件一并删，外链只删记录） */
  delMusic(id: number): Promise<null>
  /** 清理音频：删除全部音乐记录，本地文件存在则一并删除 */
  cleanMusic(): Promise<{ count: number }>
  /** 音频文件上传（mp3/wav/m4a/aac ≤20MB） */
  uploadAudio(file: File): Promise<AudioUploadResult>
}

/** 按后端前缀构建音乐接口集（任务315：接口集同构，壳不同前缀不同） */
function buildMusicApi(base: string, uploadUrl: string): MusicApi {
  return {
    getMusicList(params) {
      return request.get<unknown, MusicListData>(`${base}/list`, { params })
    },
    addMusic(data) {
      return request.post<unknown, { id: number }>(`${base}/add`, data)
    },
    delMusic(id) {
      return request.post<unknown, null>(`${base}/del`, { id })
    },
    cleanMusic() {
      return request.post<unknown, { count: number }>(`${base}/clean`)
    },
    uploadAudio(file) {
      const formData = new FormData()
      formData.append('file', file)
      return request.post<unknown, AudioUploadResult>(uploadUrl, formData, {
        headers: { 'Content-Type': 'multipart/form-data' },
      })
    },
  }
}

/** 超管控制台侧（任务315：平台级音乐库维护入口，上传落 uploads/platform/） */
export const consoleMusicApi = buildMusicApi('/console/music', '/console/music/upload')

/** 租户侧（任务315：平台列表透传，仅设计编辑器音乐播放消费 getMusicList） */
export const adminMusicApi = buildMusicApi('/admin/music', '/admin/upload')

// 兼容既有具名导入（设计编辑器消费）：默认指向租户侧透传接口
export const getMusicList = adminMusicApi.getMusicList
