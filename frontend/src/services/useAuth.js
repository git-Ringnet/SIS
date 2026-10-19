import { ref, computed } from 'vue';
import { authApi } from './api';
import router from '../router';

const token = ref(localStorage.getItem('auth_token') || '');
const user = ref(JSON.parse(localStorage.getItem('auth_user') || 'null'));
const isInitialized = ref(false);

export function useAuth() {
  const isAuthenticated = computed(() => !!token.value);

  const setAuth = (newToken, newUser) => {
    token.value = newToken;
    user.value = newUser;
    localStorage.setItem('auth_token', newToken);
    localStorage.setItem('auth_user', JSON.stringify(newUser));
  };

  const clearAuth = () => {
    token.value = '';
    user.value = null;
    localStorage.removeItem('auth_token');
    localStorage.removeItem('auth_user');
  };

  const login = async (credentials) => {
    const res = await authApi.login(credentials);
    setAuth(res.data.token, res.data.user);
    return res.data;
  };

  const register = async (userData) => {
    const res = await authApi.register(userData);
    setAuth(res.data.token, res.data.user);
    return res.data;
  };

  const logout = async () => {
    const currentToken = token.value;
    // 1. Immediately clear authentication and redirect to login (instant 0ms response)
    clearAuth();
    router.push('/login');

    // 2. Notify backend in background to revoke token without blocking user
    if (currentToken) {
      try {
        await authApi.logout(currentToken);
      } catch (err) {
        console.warn('Background logout notice: ', err);
      }
    }
  };

  const checkAuth = async () => {
    if (!token.value) {
      isInitialized.value = true;
      return null;
    }
    try {
      const res = await authApi.me();
      user.value = res.data.user;
      localStorage.setItem('auth_user', JSON.stringify(res.data.user));
      return res.data.user;
    } catch (err) {
      clearAuth();
      return null;
    } finally {
      isInitialized.value = true;
    }
  };

  return {
    token,
    user,
    isAuthenticated,
    isInitialized,
    login,
    register,
    logout,
    checkAuth,
    setAuth,
    clearAuth,
  };
}
