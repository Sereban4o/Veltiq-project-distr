import axios, { AxiosInstance, InternalAxiosRequestConfig, AxiosResponse, AxiosError } from 'axios'
import { getBundleId } from './bundleId'

const API_BASE_URL = '/api'

const apiClient: AxiosInstance = axios.create({
  baseURL: API_BASE_URL,
  timeout: 20000,
  headers: {
    Accept: 'application/json',
  },
})

// Request interceptor - add auth token
apiClient.interceptors.request.use(
  (config: InternalAxiosRequestConfig) => {
    const token = localStorage.getItem('auth_token')
    if (token) {
      config.headers.Authorization = `Bearer ${token}`
    }

    // Bundle identity for the server-side integrity check (informational).
    const bundleId = getBundleId()
    if (bundleId) {
      config.headers['X-Veltiq-Bundle'] = bundleId
    }

    return config
  },
  (error: AxiosError) => {
    return Promise.reject(error)
  },
)

// Response interceptor - handle errors
apiClient.interceptors.response.use(
  (response: AxiosResponse) => response,
  (error: AxiosError) => {
    // Public, unauthenticated endpoints (e.g. shared pages) may legitimately
    // answer 401 (password required) without meaning the session expired.
    const url = error.config?.url ?? ''
    if (error.response?.status === 401 && !url.includes('/v1/public/')) {
      localStorage.removeItem('auth_token')
      window.location.href = '/login'
    }
    return Promise.reject(error)
  },
)

export default apiClient
