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
            // Token expired or invalid
            localStorage.removeItem('sanctum_token');
            window.axios.defaults.headers.common['Authorization'] = '';

            // Redirect to login if not already there
            if (!window.location.pathname.includes('/login')) {
                window.location.href = '/login';
            }
        }
        return Promise.reject(error);
    }
);