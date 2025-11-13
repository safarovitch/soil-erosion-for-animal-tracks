import axios from 'axios';
window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

// Set up CSRF token for axios
const token = document.head.querySelector('meta[name="csrf-token"]');

if (token) {
    window.axios.defaults.headers.common['X-CSRF-TOKEN'] = token.content;
} else {
    console.error('CSRF token not found: https://laravel.com/docs/csrf#csrf-x-csrf-token');
}

// Set up Sanctum token if available
const sanctumToken = localStorage.getItem('sanctum_token');
if (sanctumToken) {
    window.axios.defaults.headers.common['Authorization'] = `Bearer ${sanctumToken}`;
}

// Add response interceptor to handle token expiration
window.axios.interceptors.response.use(
    response => response,
    error => {
        if (error.response?.status === 401) {
            const requestUrl = error.config?.url ?? '';
            const isAdminRequest =
                requestUrl.includes('/api/admin') ||
                window.location.pathname.startsWith('/admin');

            // Clear stored token if we had one
            if (localStorage.getItem('sanctum_token')) {
                localStorage.removeItem('sanctum_token');
                window.axios.defaults.headers.common['Authorization'] = '';
            }

            if (isAdminRequest && !window.location.pathname.includes('/admin/login')) {
                window.location.href = '/admin/login';
                return Promise.reject(error);
            }
        }

        return Promise.reject(error);
    }
);