<template>
  <div class="min-h-screen flex bg-slate-100">
    <Head title="Admin Login" />

    <div class="hidden lg:flex flex-1 items-center justify-center bg-gradient-to-br from-sky-500 to-blue-700 p-12 text-white">
      <div class="max-w-md space-y-6">
        <div>
          <p class="text-sm uppercase tracking-widest font-semibold text-white/70">
            Soil Erosion Watch
          </p>
          <h1 class="mt-2 text-4xl font-bold leading-tight">
            Admin Dashboard Access
          </h1>
        </div>
        <p class="text-base text-white/80 leading-relaxed">
          Manage datasets, review system usage, and monitor erosion analytics in
          Tajikistan. Authorized personnel only.
        </p>
      </div>
    </div>

    <div class="flex-1 flex items-center justify-center px-6 sm:px-12 lg:px-16">
      <div class="w-full max-w-md bg-white rounded-2xl shadow-xl border border-slate-200/70 p-8 space-y-8">
        <div class="space-y-2">
          <h2 class="text-2xl font-semibold text-slate-900">
            Sign in to admin console
          </h2>
          <p class="text-sm text-slate-500">
            Enter your credentials to continue.
          </p>
        </div>

        <div v-if="generalError" class="rounded-lg bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-700">
          {{ generalError }}
        </div>

        <form class="space-y-6" @submit.prevent="submit">
          <div class="space-y-2">
            <label for="email" class="text-sm font-medium text-slate-700">Email address</label>
            <input
              id="email"
              v-model="email"
              type="email"
              autocomplete="username"
              required
              class="form-input"
              :class="inputClass(errors.email)"
              placeholder="admin@example.com"
            />
            <p v-if="errors.email" class="form-error">
              {{ errors.email }}
            </p>
          </div>

          <div class="space-y-2">
            <div class="flex items-center justify-between">
              <label for="password" class="text-sm font-medium text-slate-700">Password</label>
              <button type="button" class="text-xs text-blue-600 hover:text-blue-500 font-medium" @click="toggleShowPassword">
                {{ showPassword ? 'Hide' : 'Show' }}
              </button>
            </div>
            <input
              id="password"
              v-model="password"
              :type="showPassword ? 'text' : 'password'"
              autocomplete="current-password"
              required
              class="form-input"
              :class="inputClass(errors.password)"
              placeholder="••••••••"
            />
            <p v-if="errors.password" class="form-error">
              {{ errors.password }}
            </p>
          </div>

          <label class="inline-flex items-center space-x-2">
            <input
              v-model="remember"
              type="checkbox"
              class="h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500"
            />
            <span class="text-sm text-slate-600">Remember me</span>
          </label>

          <button
            type="submit"
            class="w-full btn-primary h-11 text-sm font-semibold"
            :disabled="isSubmitting"
          >
            <span v-if="isSubmitting" class="inline-flex items-center justify-center space-x-2">
              <svg class="w-4 h-4 animate-spin" viewBox="0 0 24 24" fill="none">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z" />
              </svg>
              <span>Signing in...</span>
            </span>
            <span v-else>Sign in</span>
          </button>
        </form>

        <div class="pt-4 border-t border-slate-100">
          <p class="text-xs text-slate-400 leading-relaxed">
            By signing in you agree to comply with internal usage policies. Contact the system administrator if you need access.
          </p>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { Head, router } from '@inertiajs/vue3'
import { computed, onMounted, ref } from 'vue'
import axios from 'axios'
import { useAuthStore } from '@/Stores/useAuthStore'

const authStore = useAuthStore()

const email = ref('')
const password = ref('')
const remember = ref(false)
const showPassword = ref(false)
const isSubmitting = ref(false)
const errors = ref({})
const generalError = ref('')

const inputClass = computed(() => (fieldError) => [
  'block w-full rounded-lg border px-3.5 py-2.5 text-sm shadow-sm transition',
  'focus:outline-none focus:ring-2 focus:ring-offset-1 focus:ring-blue-500 focus:border-blue-500',
  fieldError ? 'border-red-300 text-red-900 focus:ring-red-500 focus:border-red-500 bg-red-50/40 placeholder:text-red-300' : 'border-slate-300 text-slate-900 placeholder:text-slate-400 bg-white'
])

const toggleShowPassword = () => {
  showPassword.value = !showPassword.value
}

const submit = async () => {
  isSubmitting.value = true
  errors.value = {}
  generalError.value = ''

  try {
    const response = await axios.post('/admin/login', {
      email: email.value,
      password: password.value,
      remember: remember.value,
    })

    if (response.data?.token) {
      localStorage.setItem('sanctum_token', response.data.token)
      axios.defaults.headers.common.Authorization = `Bearer ${response.data.token}`
      authStore.user = response.data.user
      authStore.token = response.data.token
    }

    router.visit(response.data?.redirect || '/admin/dashboard')
  } catch (error) {
    if (error.response?.status === 422) {
      errors.value = error.response.data.errors || {}
      generalError.value = errors.value.email?.[0] || errors.value.password?.[0] || 'Unable to sign in with those credentials.'
    } else {
      generalError.value = error.response?.data?.message || 'Unexpected error occurred. Please try again.'
    }
  } finally {
    isSubmitting.value = false
  }
}

onMounted(async () => {
  if (authStore.token) {
    try {
      await authStore.initializeAuth()
    } catch (error) {
      // ignore token initialization errors on login screen
    }
  }

  if (authStore.user && authStore.user.role === 'admin') {
    router.visit('/admin/dashboard')
  }
})
</script>

<style scoped>
.form-input {
  transition: box-shadow 0.2s ease, transform 0.2s ease;
}

.form-input:focus {
  transform: translateY(-1px);
}

.form-error {
  font-size: 0.75rem;
  color: #dc2626;
}

.btn-primary {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 0.75rem;
  background-image: linear-gradient(to right, #1d4ed8, #2563eb, #1d4ed8);
  color: white;
  box-shadow: 0 10px 30px -12px rgba(37, 99, 235, 0.7);
  transition: all 0.2s ease;
}

.btn-primary:hover {
  transform: translateY(-1px);
  box-shadow: 0 18px 45px -18px rgba(37, 99, 235, 0.8);
}

.btn-primary:disabled {
  opacity: 0.7;
  cursor: not-allowed;
  transform: none;
  box-shadow: none;
}
</style>


