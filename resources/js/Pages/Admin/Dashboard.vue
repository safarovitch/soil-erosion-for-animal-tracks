<template>

    <Head title="Admin Dashboard" />

    <div class="min-h-screen bg-slate-50">
        <!-- Navigation -->
        <nav class="nav-modern">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="flex justify-between h-16">
                    <div class="flex items-center">
                        <h1 class="heading-md gradient-text">Admin Dashboard</h1>
                    </div>
                    <div class="flex items-center space-x-4">
                        <Link href="/" class="text-slate-600 hover:text-slate-900 transition-colors duration-200 font-medium">View Map</Link>
                        <button @click="logout" class="btn-danger">
                            Logout
                        </button>
                    </div>
                </div>
            </div>
        </nav>

        <div class="max-w-7xl mx-auto py-6 sm:px-6 lg:px-8">
            <!-- Stats Cards -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8 animate-fade-in">
                <div class="stat-card-gradient">
                    <div class="p-6">
                        <div class="flex items-center">
                            <div class="flex-shrink-0">
                                <div class="w-12 h-12 bg-gradient-to-br from-primary-500 to-primary-600 rounded-xl flex items-center justify-center shadow-lg">
                                    <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                                    </svg>
                                </div>
                            </div>
                            <div class="ml-4 w-0 flex-1">
                                <dl>
                                    <dt class="text-sm font-medium text-slate-600 truncate">Total Queries</dt>
                                    <dd class="text-2xl font-bold text-slate-900">{{ stats.totalQueries || 0 }}</dd>
                                </dl>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="stat-card-gradient">
                    <div class="p-6">
                        <div class="flex items-center">
                            <div class="flex-shrink-0">
                                <div class="w-12 h-12 bg-gradient-to-br from-emerald-500 to-emerald-600 rounded-xl flex items-center justify-center shadow-lg">
                                    <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                                    </svg>
                                </div>
                            </div>
                            <div class="ml-4 w-0 flex-1">
                                <dl>
                                    <dt class="text-sm font-medium text-slate-600 truncate">Datasets</dt>
                                    <dd class="text-2xl font-bold text-slate-900">{{ stats.totalDatasets || 0 }}</dd>
                                </dl>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="stat-card-gradient">
                    <div class="p-6">
                        <div class="flex items-center">
                            <div class="flex-shrink-0">
                                <div class="w-12 h-12 bg-gradient-to-br from-amber-500 to-orange-500 rounded-xl flex items-center justify-center shadow-lg">
                                    <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197m13.5-9a2.5 2.5 0 11-5 0 2.5 2.5 0 015 0z"></path>
                                    </svg>
                                </div>
                            </div>
                            <div class="ml-4 w-0 flex-1">
                                <dl>
                                    <dt class="text-sm font-medium text-slate-600 truncate">Active Users</dt>
                                    <dd class="text-2xl font-bold text-slate-900">{{ stats.activeUsers || 0 }}</dd>
                                </dl>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="stat-card-gradient">
                    <div class="p-6">
                        <div class="flex items-center">
                            <div class="flex-shrink-0">
                                <div class="w-12 h-12 bg-gradient-to-br from-violet-500 to-purple-600 rounded-xl flex items-center justify-center shadow-lg">
                                    <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4"></path>
                                    </svg>
                                </div>
                            </div>
                            <div class="ml-4 w-0 flex-1">
                                <dl>
                                    <dt class="text-sm font-medium text-slate-600 truncate">Cache Size</dt>
                                    <dd class="text-2xl font-bold text-slate-900">{{ stats.cacheSize || '0 MB' }}</dd>
                                </dl>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- System Status -->
            <div class="modern-card mb-8">
                <div class="px-6 py-6">
                    <h3 class="heading-sm mb-6">System Status</h3>
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
                        <div class="flex items-center space-x-3">
                            <div class="flex-shrink-0">
                                <div :class="systemStatus.database ? 'status-online' : 'status-offline'" class="status-dot"></div>
                            </div>
                            <div>
                                <p class="text-sm font-medium text-slate-900">Database</p>
                                <p class="text-xs text-slate-500">{{ systemStatus.database ? 'Online' : 'Offline' }}</p>
                            </div>
                        </div>
                        <div class="flex items-center space-x-3">
                            <div class="flex-shrink-0">
                                <div :class="systemStatus.gee ? 'status-online' : 'status-offline'" class="status-dot"></div>
                            </div>
                            <div>
                                <p class="text-sm font-medium text-slate-900">Google Earth Engine</p>
                                <p class="text-xs text-slate-500">{{ systemStatus.gee ? 'Connected' : 'Disconnected' }}</p>
                            </div>
                        </div>
                        <div class="flex items-center space-x-3">
                            <div class="flex-shrink-0">
                                <div :class="systemStatus.gdal ? 'status-online' : 'status-offline'" class="status-dot"></div>
                            </div>
                            <div>
                                <p class="text-sm font-medium text-slate-900">GDAL</p>
                                <p class="text-xs text-slate-500">{{ systemStatus.gdal ? 'Available' : 'Unavailable' }}</p>
                            </div>
                        </div>
                        <div class="flex items-center space-x-3">
                            <div class="flex-shrink-0">
                                <div :class="systemStatus.storage ? 'status-online' : 'status-offline'" class="status-dot"></div>
                            </div>
                            <div>
                                <p class="text-sm font-medium text-slate-900">Storage</p>
                                <p class="text-xs text-slate-500">{{ systemStatus.storage ? 'Ready' : 'Error' }}</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Recent Activity -->
            <div class="modern-card mb-8">
                <div class="px-6 py-6">
                    <div class="flex items-center justify-between mb-6">
                        <h3 class="heading-sm">Recent Activity</h3>
                        <Link href="/admin/usage-history" class="text-sm text-primary-600 hover:text-primary-700 font-medium transition-colors duration-200">
                        View All →
                        </Link>
                    </div>
                    <div class="space-y-4">
                        <div v-for="activity in recentActivity" :key="activity.id" class="flex items-center space-x-4 p-3 rounded-lg bg-slate-50 hover:bg-slate-100 transition-colors duration-200">
                            <div class="w-10 h-10 bg-primary-100 rounded-full flex items-center justify-center">
                                <span class="text-xl">{{ activity.icon }}</span>
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-medium text-slate-900">{{ activity.description }}</p>
                                <p class="text-xs text-slate-500 mt-1">{{ activity.timestamp }}</p>
                            </div>
                        </div>
                        <div v-if="recentActivity.length === 0" class="text-center py-8">
                            <div class="w-16 h-16 bg-slate-100 rounded-full flex items-center justify-center mx-auto mb-4">
                                <svg class="w-8 h-8 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
                                </svg>
                            </div>
                            <p class="text-sm text-slate-500">No recent activity</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 animate-slide-up">
                <Link href="/admin/datasets/upload" class="modern-card p-6 group hover:scale-[1.02] transition-all duration-300">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <div class="w-12 h-12 bg-gradient-to-br from-primary-500 to-primary-600 rounded-xl flex items-center justify-center shadow-lg group-hover:shadow-xl transition-shadow duration-300">
                            <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"></path>
                            </svg>
                        </div>
                    </div>
                    <div class="ml-4">
                        <h3 class="text-lg font-semibold text-slate-900 group-hover:text-primary-600 transition-colors duration-200">Upload Dataset</h3>
                        <p class="text-sm text-slate-500">Upload new GeoTIFF files</p>
                    </div>
                </div>
                </Link>

                <Link href="/admin/usage-history" class="modern-card p-6 group hover:scale-[1.02] transition-all duration-300">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <div class="w-12 h-12 bg-gradient-to-br from-emerald-500 to-emerald-600 rounded-xl flex items-center justify-center shadow-lg group-hover:shadow-xl transition-shadow duration-300">
                            <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                            </svg>
                        </div>
                    </div>
                    <div class="ml-4">
                        <h3 class="text-lg font-semibold text-slate-900 group-hover:text-emerald-600 transition-colors duration-200">Usage Analytics</h3>
                        <p class="text-sm text-slate-500">View usage statistics</p>
                    </div>
                </div>
                </Link>

                <button @click="clearCache" class="modern-card p-6 group hover:scale-[1.02] transition-all duration-300 text-left">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <div class="w-12 h-12 bg-gradient-to-br from-amber-500 to-orange-500 rounded-xl flex items-center justify-center shadow-lg group-hover:shadow-xl transition-shadow duration-300">
                                <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                </svg>
                            </div>
                        </div>
                        <div class="ml-4">
                            <h3 class="text-lg font-semibold text-slate-900 group-hover:text-amber-600 transition-colors duration-200">Clear Cache</h3>
                            <p class="text-sm text-slate-500">Clear erosion data cache</p>
                        </div>
                    </div>
                </button>
            </div>
        </div>
    </div>
</template>

<script setup>
import { Head, Link, router } from '@inertiajs/vue3'
import { ref, onMounted } from 'vue'
import axios from 'axios'

const stats = ref({})
const systemStatus = ref({})
const recentActivity = ref([])

const loadDashboardData = async () => {
    try {
        // Load stats
        const statsResponse = await axios.get('/api/admin/stats')
        stats.value = statsResponse.data.data

        // Load system status
        const statusResponse = await axios.get('/api/admin/system-status')
        systemStatus.value = statusResponse.data.data

        // Load recent activity
        const activityResponse = await axios.get('/api/admin/recent-activity')
        recentActivity.value = activityResponse.data.data
    } catch (error) {
        console.error('Error loading dashboard data:', error)
    }
}

const logout = async () => {
    try {
        await axios.post('/admin/logout')
    } catch (error) {
        console.error('Logout error:', error)
    } finally {
        localStorage.removeItem('sanctum_token')
        delete axios.defaults.headers.common.Authorization
        router.visit('/admin/login')
    }
}

const clearCache = async () => {
    if (confirm('Are you sure you want to clear the cache? This will remove all cached erosion data.')) {
        try {
            await axios.post('/api/admin/clear-cache')
            alert('Cache cleared successfully')
            loadDashboardData() // Reload stats
        } catch (error) {
            alert('Failed to clear cache')
            console.error('Clear cache error:', error)
        }
    }
}

onMounted(() => {
    loadDashboardData()
})
</script>
