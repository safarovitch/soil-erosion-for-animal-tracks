<template>
  <Head title="Upload Dataset" />

  <div class="min-h-screen bg-gray-100">
    <!-- Navigation -->
    <nav class="bg-white shadow">
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between h-16">
          <div class="flex items-center">
            <Link href="/admin/dashboard" class="text-gray-600 hover:text-gray-900 mr-4">← Back to Dashboard</Link>
            <h1 class="text-xl font-semibold text-gray-900">Upload Dataset</h1>
          </div>
          <div class="flex items-center space-x-4">
            <Link href="/admin/rusle-config" class="text-gray-600 hover:text-gray-900">RUSLE Config</Link>
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

    <div class="max-w-3xl mx-auto py-6 sm:px-6 lg:px-8">
      <div class="bg-white shadow rounded-lg">
        <div class="px-4 py-5 sm:p-6">
          <h3 class="text-lg leading-6 font-medium text-gray-900 mb-4">Upload GeoTIFF Dataset</h3>

          <form @submit.prevent="uploadDataset" class="space-y-6">
            <!-- Dataset Name -->
            <div>
              <label for="name" class="block text-sm font-medium text-gray-700">Dataset Name</label>
              <input
                type="text"
                id="name"
                v-model="form.name"
                required
                class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm"
                placeholder="Enter dataset name"
              />
            </div>

            <!-- Dataset Description -->
            <div>
              <label for="description" class="block text-sm font-medium text-gray-700">Description</label>
              <textarea
                id="description"
                v-model="form.description"
                rows="3"
                class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm"
                placeholder="Enter dataset description"
              ></textarea>
            </div>

            <!-- Dataset Type -->
            <div>
              <label for="type" class="block text-sm font-medium text-gray-700">Dataset Type</label>
              <select
                id="type"
                v-model="form.type"
                required
                class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm"
              >
                <option value="">Select dataset type</option>
                <option value="rainfall">Rainfall Data</option>
                <option value="erosion">Erosion Data</option>
                <option value="custom">Custom Dataset</option>
              </select>
            </div>

            <!-- File Upload -->
            <div>
              <label for="file" class="block text-sm font-medium text-gray-700">GeoTIFF File</label>
              <div class="mt-1 flex justify-center px-6 pt-5 pb-6 border-2 border-gray-300 border-dashed rounded-md hover:border-gray-400 transition-colors">
                <div class="space-y-1 text-center">
                  <div v-if="!form.file" class="flex justify-center">
                    <svg class="mx-auto h-12 w-12 text-gray-400" stroke="currentColor" fill="none" viewBox="0 0 48 48">
                      <path d="M28 8H12a4 4 0 00-4 4v20m32-12v8m0 0v8a4 4 0 01-4 4H12a4 4 0 01-4-4v-4m32-4l-3.172-3.172a4 4 0 00-5.656 0L28 28M8 32l9.172-9.172a4 4 0 015.656 0L28 28m0 0l4 4m4-24h8m-4-4v8m-12 4h.02" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                  </div>
                  <div v-if="!form.file" class="flex text-sm text-gray-600">
                    <label for="file" class="relative cursor-pointer bg-white rounded-md font-medium text-indigo-600 hover:text-indigo-500 focus-within:outline-none focus-within:ring-2 focus-within:ring-offset-2 focus-within:ring-indigo-500">
                      <span>Upload a file</span>
                      <input
                        id="file"
                        type="file"
                        accept=".tif,.tiff"
                        @change="handleFileSelect"
                        class="sr-only"
                        required
                      />
                    </label>
                    <p class="pl-1">or drag and drop</p>
                  </div>
                  <div v-if="form.file" class="text-sm text-gray-600">
                    <p class="font-medium text-green-600">{{ form.file.name }}</p>
                    <p class="text-xs text-gray-500">{{ formatFileSize(form.file.size) }}</p>
                  </div>
                  <p class="text-xs text-gray-500">GeoTIFF files only (.tif, .tiff)</p>
                </div>
              </div>
            </div>

            <!-- Error Message -->
            <div v-if="error" class="bg-red-50 border border-red-200 rounded-md p-4">
              <div class="flex">
                <div class="flex-shrink-0">
                  <svg class="h-5 w-5 text-red-400" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" />
                  </svg>
                </div>
                <div class="ml-3">
                  <p class="text-sm text-red-800">{{ error }}</p>
                </div>
              </div>
            </div>

            <!-- Success Message -->
            <div v-if="success" class="bg-green-50 border border-green-200 rounded-md p-4">
              <div class="flex">
                <div class="flex-shrink-0">
                  <svg class="h-5 w-5 text-green-400" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                  </svg>
                </div>
                <div class="ml-3">
                  <p class="text-sm text-green-800">{{ success }}</p>
                </div>
              </div>
            </div>

            <!-- Upload Progress -->
            <div v-if="uploadProgress > 0 && uploadProgress < 100" class="bg-blue-50 border border-blue-200 rounded-md p-4">
              <div class="flex items-center">
                <div class="flex-shrink-0">
                  <div class="animate-spin rounded-full h-5 w-5 border-b-2 border-blue-600"></div>
                </div>
                <div class="ml-3 flex-1">
                  <p class="text-sm text-blue-800">Processing dataset... {{ uploadProgress }}%</p>
                  <div class="mt-2 bg-blue-200 rounded-full h-2">
                    <div class="bg-blue-600 h-2 rounded-full transition-all duration-300" :style="{ width: uploadProgress + '%' }"></div>
                  </div>
                </div>
              </div>
            </div>

            <!-- Submit Button -->
            <div class="flex justify-end">
              <button
                type="submit"
                :disabled="isUploading || !form.file"
                class="bg-indigo-600 text-white px-6 py-2 rounded-md hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 disabled:opacity-50 disabled:cursor-not-allowed"
              >
                {{ isUploading ? 'Uploading...' : 'Upload Dataset' }}
              </button>
            </div>
          </form>
        </div>
      </div>

      <!-- Existing Datasets -->
      <div class="mt-8 bg-white shadow rounded-lg">
        <div class="px-4 py-5 sm:p-6">
          <h3 class="text-lg leading-6 font-medium text-gray-900 mb-4">Existing Datasets</h3>
          <div class="space-y-4">
            <div
              v-for="dataset in datasets"
              :key="dataset.id"
              class="flex items-center justify-between p-4 border border-gray-200 rounded-md"
            >
              <div class="flex-1">
                <h4 class="text-sm font-medium text-gray-900">{{ dataset.name }}</h4>
                <p class="text-sm text-gray-500">{{ dataset.description || 'No description' }}</p>
                <div class="flex items-center mt-2 space-x-4">
                  <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-800">
                    {{ dataset.type }}
                  </span>
                  <span
                    :class="getStatusClass(dataset.status)"
                    class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium"
                  >
                    {{ dataset.status }}
                  </span>
                </div>
              </div>
              <div class="flex items-center space-x-2">
                <button
                  @click="deleteDataset(dataset.id)"
                  class="text-red-600 hover:text-red-500 text-sm font-medium"
                >
                  Delete
                </button>
              </div>
            </div>
            <div v-if="datasets.length === 0" class="text-center py-4">
              <p class="text-sm text-gray-500">No datasets uploaded yet</p>
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

const form = ref({
  name: '',
  description: '',
  type: '',
  file: null
})

const isUploading = ref(false)
const uploadProgress = ref(0)
const error = ref('')
const success = ref('')
const datasets = ref([])
const pagination = ref(null)

const handleFileSelect = (event) => {
  const file = event.target.files[0]
  if (file) {
    form.value.file = file
    error.value = ''
  }
}

const uploadDataset = async () => {
  if (!form.value.file) {
    error.value = 'Please select a file to upload'
    return
  }

  isUploading.value = true
  uploadProgress.value = 10
  error.value = ''
  success.value = ''

  try {
    const formData = new FormData()
    formData.append('name', form.value.name)
    formData.append('description', form.value.description)
    formData.append('type', form.value.type)
    formData.append('file', form.value.file)

    uploadProgress.value = 30

    const response = await axios.post('/api/admin/datasets/upload', formData, {
      headers: {
        'Content-Type': 'multipart/form-data',
      },
      onUploadProgress: (progressEvent) => {
        uploadProgress.value = Math.round((progressEvent.loaded * 90) / progressEvent.total) + 10
      },
    })

    uploadProgress.value = 100

    if (response.data.success) {
      success.value = 'Dataset uploaded successfully! Processing may take a few minutes.'
      form.value = { name: '', description: '', type: '', file: null }
      document.getElementById('file').value = ''
      loadDatasets()
    } else {
      error.value = response.data.message || 'Upload failed'
    }
  } catch (err) {
    error.value = err.response?.data?.message || 'Upload failed'
  } finally {
    isUploading.value = false
    uploadProgress.value = 0
  }
}

const loadDatasets = async () => {
  try {
    const response = await axios.get('/api/admin/datasets')
    const payload = response.data?.data

    if (Array.isArray(payload)) {
      datasets.value = payload
      pagination.value = null
      return
    }

    if (payload && Array.isArray(payload.data)) {
      datasets.value = payload.data
      pagination.value = {
        currentPage: payload.current_page,
        lastPage: payload.last_page,
        perPage: payload.per_page,
        total: payload.total,
      }
      return
    }

    datasets.value = []
    pagination.value = null
  } catch (error) {
    console.error('Error loading datasets:', error)
  }
}

const deleteDataset = async (datasetId) => {
  if (confirm('Are you sure you want to delete this dataset?')) {
    try {
      await axios.delete(`/api/admin/datasets/${datasetId}`)
      loadDatasets()
    } catch (error) {
      alert('Failed to delete dataset')
      console.error('Delete error:', error)
    }
  }
}

const getStatusClass = (status) => {
  switch (status) {
    case 'ready':
      return 'bg-green-100 text-green-800'
    case 'processing':
      return 'bg-yellow-100 text-yellow-800'
    case 'failed':
      return 'bg-red-100 text-red-800'
    default:
      return 'bg-gray-100 text-gray-800'
  }
}

const formatFileSize = (bytes) => {
  if (bytes === 0) return '0 Bytes'
  const k = 1024
  const sizes = ['Bytes', 'KB', 'MB', 'GB']
  const i = Math.floor(Math.log(bytes) / Math.log(k))
  return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i]
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
  loadDatasets()
})
</script>
