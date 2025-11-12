<template>
  <Head title="Usage History" />

  <div class="min-h-screen bg-gray-100">
    <!-- Navigation -->
    <nav class="bg-white shadow">
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between h-16">
          <div class="flex items-center">
            <Link href="/admin/dashboard" class="text-gray-600 hover:text-gray-900 mr-4">← Back to Dashboard</Link>
            <h1 class="text-xl font-semibold text-gray-900">Usage History</h1>
          </div>
          <div class="flex items-center space-x-4">
            <Link href="/" class="text-gray-600 hover:text-gray-900">View Map</Link>
            <button
              @click="logout"
              class="bg-red-600 text-white px-4 py-2 rounded-md hover:bg-red-700"
            >
              Logout
            </button>
          </div>
        </div>
      </div>
    </nav>

    <div class="max-w-7xl mx-auto py-6 sm:px-6 lg:px-8">
      
      <!-- Usage Statistics -->
      <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
        <div class="bg-white overflow-hidden shadow rounded-lg">
          <div class="p-5">
            <div class="flex items-center">
              <div class="flex-shrink-0">
                <div class="w-8 h-8 bg-blue-500 rounded-md flex items-center justify-center">
                  <span class="text-white text-sm font-medium">Q</span>
                </div>
              </div>
              <div class="ml-5 w-0 flex-1">
                <dl>
                  <dt class="text-sm font-medium text-gray-500 truncate">Total Queries</dt>
                  <dd class="text-lg font-medium text-gray-900">{{ usageStats.totalQueries || 0 }}</dd>
                </dl>
              </div>
            </div>
          </div>
        </div>

        <div class="bg-white overflow-hidden shadow rounded-lg">
          <div class="p-5">
            <div class="flex items-center">
              <div class="flex-shrink-0">
                <div class="w-8 h-8 bg-green-500 rounded-md flex items-center justify-center">
                  <span class="text-white text-sm font-medium">U</span>
                </div>
              </div>
              <div class="ml-5 w-0 flex-1">
                <dl>
                  <dt class="text-sm font-medium text-gray-500 truncate">Unique Users</dt>
                  <dd class="text-lg font-medium text-gray-900">{{ usageStats.uniqueUsers || 0 }}</dd>
                </dl>
              </div>
            </div>
          </div>
        </div>

        <div class="bg-white overflow-hidden shadow rounded-lg">
          <div class="p-5">
            <div class="flex items-center">
              <div class="flex-shrink-0">
                <div class="w-8 h-8 bg-yellow-500 rounded-md flex items-center justify-center">
                  <span class="text-white text-sm font-medium">R</span>
                </div>
              </div>
              <div class="ml-5 w-0 flex-1">
                <dl>
                  <dt class="text-sm font-medium text-gray-500 truncate">Most Queried Region</dt>
                  <dd class="text-lg font-medium text-gray-900">{{ usageStats.mostQueriedRegion || 'None' }}</dd>
                </dl>
              </div>
            </div>
          </div>
        </div>

        <div class="bg-white overflow-hidden shadow rounded-lg">
          <div class="p-5">
            <div class="flex items-center">
              <div class="flex-shrink-0">
                <div class="w-8 h-8 bg-purple-500 rounded-md flex items-center justify-center">
                  <span class="text-white text-sm font-medium">T</span>
                </div>
              </div>
              <div class="ml-5 w-0 flex-1">
                <dl>
                  <dt class="text-sm font-medium text-gray-500 truncate">Avg Processing Time</dt>
                  <dd class="text-lg font-medium text-gray-900">{{ formatTime(usageStats.avgProcessingTime) }}</dd>
                </dl>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Usage History Table -->
      <div class="bg-white shadow rounded-lg">
        <div class="px-4 py-5 sm:p-6">
          <h3 class="text-lg leading-6 font-medium text-gray-900 mb-4">Usage History</h3>

          <div v-if="isLoading" class="text-center py-8">
            <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-indigo-600 mx-auto"></div>
            <p class="mt-2 text-sm text-gray-500">Loading usage history...</p>
          </div>

          <div v-else-if="usageHistory.length === 0" class="text-center py-8">
            <p class="text-sm text-gray-500">No usage history found</p>
          </div>

          <div v-else class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
              <thead class="bg-gray-50">
                <tr>
                  <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                    Timestamp
                  </th>
                  <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                    User
                  </th>
                  <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                    Query Type
                  </th>
                  <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                    Area
                  </th>
                  <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                    Year
                  </th>
                  <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                    Processing Time
                  </th>
                  <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                    IP Address
                  </th>
                </tr>
              </thead>
              <tbody class="bg-white divide-y divide-gray-200">
                <tr v-for="query in usageHistory" :key="query.id">
                  <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                    {{ formatDate(query.created_at) }}
                  </td>
                  <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                    {{ query.user ? query.user.name : 'Anonymous' }}
                  </td>
                  <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                      {{ formatQueryType(query.query_type) }}
                    </span>
                  </td>
                  <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                    {{ getAreaName(query) }}
                  </td>
                  <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                    {{ query.year || '-' }}
                  </td>
                  <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                    {{ formatTime(query.processing_time) }}
                  </td>
                  <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                    {{ query.ip_address }}
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <!-- Pagination -->
          <div v-if="pagination && pagination.total > pagination.per_page" class="mt-6 flex items-center justify-between">
            <div class="flex-1 flex justify-between sm:hidden">
              <button
                @click="changePage(pagination.current_page - 1)"
                :disabled="pagination.current_page <= 1"
                class="relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 disabled:opacity-50"
              >
                Previous
              </button>
              <button
                @click="changePage(pagination.current_page + 1)"
                :disabled="pagination.current_page >= pagination.last_page"
                class="ml-3 relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 disabled:opacity-50"
              >
                Next
              </button>
            </div>
            <div class="hidden sm:flex-1 sm:flex sm:items-center sm:justify-between">
              <div>
                <p class="text-sm text-gray-700">
                  Showing {{ pagination.from }} to {{ pagination.to }} of {{ pagination.total }} results
                </p>
              </div>
              <div>
                <nav class="relative z-0 inline-flex rounded-md shadow-sm -space-x-px">
                  <button
                    @click="changePage(pagination.current_page - 1)"
                    :disabled="pagination.current_page <= 1"
                    class="relative inline-flex items-center px-2 py-2 rounded-l-md border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50 disabled:opacity-50"
                  >
                    Previous
                  </button>
                  <button
                    @click="changePage(pagination.current_page + 1)"
                    :disabled="pagination.current_page >= pagination.last_page"
                    class="relative inline-flex items-center px-2 py-2 rounded-r-md border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50 disabled:opacity-50"
                  >
                    Next
                  </button>
                </nav>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { Head, Link, router } from '@inertiajs/vue3'
import { ref, onMounted } from 'vue'
import axios from 'axios'

const filters = ref({
  dateRange: '',
  queryType: '',
  userType: ''
})

const usageStats = ref({})
const usageHistory = ref([])
const pagination = ref(null)
const isLoading = ref(false)

const loadUsageStats = async () => {
  try {
    const response = await axios.get('/api/admin/usage-stats')
    usageStats.value = response.data.data
  } catch (error) {
    console.error('Error loading usage stats:', error)
  }
}

const loadUsageHistory = async (page = 1) => {
  try {
    isLoading.value = true
    const params = new URLSearchParams()

    if (filters.value.dateRange) params.append('dateRange', filters.value.dateRange)
    if (filters.value.queryType) params.append('queryType', filters.value.queryType)
    if (filters.value.userType) params.append('userType', filters.value.userType)
    params.append('page', page)

    const response = await axios.get(`/api/admin/usage-history?${params}`)
    usageHistory.value = response.data.data.data
    pagination.value = {
      current_page: response.data.data.current_page,
      last_page: response.data.data.last_page,
      per_page: response.data.data.per_page,
      total: response.data.data.total,
      from: response.data.data.from,
      to: response.data.data.to,
    }
  } catch (error) {
    console.error('Error loading usage history:', error)
  } finally {
    isLoading.value = false
  }
}

const applyFilters = () => {
  loadUsageHistory(1)
}

const changePage = (page) => {
  if (page >= 1 && page <= pagination.value.last_page) {
    loadUsageHistory(page)
  }
}

const exportData = async () => {
  try {
    const params = new URLSearchParams()
    if (filters.value.dateRange) params.append('dateRange', filters.value.dateRange)
    if (filters.value.queryType) params.append('queryType', filters.value.queryType)
    if (filters.value.userType) params.append('userType', filters.value.userType)

    const response = await axios.get(`/api/admin/export-usage?${params}`, {
      responseType: 'blob'
    })

    const blob = new Blob([response.data], { type: 'text/csv' })
    const url = window.URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = `usage-history-${new Date().toISOString().split('T')[0]}.csv`
    document.body.appendChild(link)
    link.click()
    document.body.removeChild(link)
    window.URL.revokeObjectURL(url)
  } catch (error) {
    alert('Failed to export data')
    console.error('Export error:', error)
  }
}

const formatDate = (dateString) => {
  return new Date(dateString).toLocaleString()
}

const formatTime = (seconds) => {
  if (!seconds) return '-'
  if (seconds < 1) return `${(seconds * 1000).toFixed(0)}ms`
  return `${seconds.toFixed(2)}s`
}

const formatQueryType = (type) => {
  return type.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase())
}

const getAreaName = (query) => {
  if (!query.queryable_id || !query.queryable_type) {
    return 'Custom Area'
  }

  // This would need to be enhanced to actually fetch the area name
  // For now, return a placeholder
  return `${query.queryable_type} #${query.queryable_id}`
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

onMounted(() => {
  loadUsageStats()
  loadUsageHistory()
})
</script>
