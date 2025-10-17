import {
    defineStore
} from 'pinia'
import {
    ref,
    computed
} from 'vue'
import axios from 'axios'

export const useAuthStore = defineStore('auth', () => {
    // State
    const user = ref(null)
    const token = ref(localStorage.getItem('sanctum_token'))
    const isLoading = ref(false)
    const error = ref(null)

    // Getters
    const isAuthenticated = computed(() => !!token.value && !!user.value)
    const isAdmin = computed(() => user.value?.role === 'admin')

    // Actions
    const login = async (email, password) => {
        try {
            isLoading.value = true
            error.value = null

            const response = await axios.post('/api/login', {
                email,
                password,
            })

            const {
                user: userData,
                token: authToken
            } = response.data

            user.value = userData
            token.value = authToken

            // Store token in localStorage
            localStorage.setItem('sanctum_token', authToken)

            // Set default Authorization header
            axios.defaults.headers.common['Authorization'] = `Bearer ${authToken}`

            return userData
        } catch (err) {
            error.value = err.response?.data?.message || 'Login failed'
            throw err
        } finally {
            isLoading.value = false
        }
    }

    const logout = async () => {
        try {
            if (token.value) {
                await axios.post('/api/logout')
            }
        } catch (err) {
            // Ignore logout errors
            console.warn('Logout error:', err)
        } finally {
            // Clear state regardless of API call success
            user.value = null
            token.value = null
            localStorage.removeItem('sanctum_token')
            axios.defaults.headers.common['Authorization'] = ''
        }
    }

    const fetchUser = async () => {
        try {
            if (!token.value) return null

            isLoading.value = true
            error.value = null

            const response = await axios.get('/api/user')
            user.value = response.data

            return response.data
        } catch (err) {
            // Token might be expired
            if (err.response?.status === 401) {
                await logout()
            }
            error.value = err.response?.data?.message || 'Failed to fetch user'
            throw err
        } finally {
            isLoading.value = false
        }
    }

    const initializeAuth = async () => {
        if (token.value) {
            // Set Authorization header
            axios.defaults.headers.common['Authorization'] = `Bearer ${token.value}`

            // Try to fetch user data
            try {
                await fetchUser()
            } catch (err) {
                // Token is invalid, clear it
                await logout()
            }
        }
    }

    const clearError = () => {
        error.value = null
    }

    return {
        // State
        user,
        token,
        isLoading,
        error,

        // Getters
        isAuthenticated,
        isAdmin,

        // Actions
        login,
        logout,
        fetchUser,
        initializeAuth,
        clearError,
    }
})