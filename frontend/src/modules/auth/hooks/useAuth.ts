import { useAuth as useAuthStore } from '@/modules/auth/store/authStore'

export { useAuthStore as useAuth }

export function useAuthContext() {
  const { user, token, loading, error, login, verifyTwoFactor, register, logout, fetchUser, clearError } =
    useAuthStore()

  return {
    user,
    token,
    loading,
    error,
    isAuthenticated: !!token && !!user,
    login,
    verifyTwoFactor,
    register,
    logout,
    fetchUser,
    clearError,
  }
}
