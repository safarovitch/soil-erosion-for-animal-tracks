<template>
    <Head title="RUSLE Configuration" />

    <div class="min-h-screen bg-slate-50">
        <nav class="nav-modern">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="flex justify-between h-16">
                    <div class="flex items-center space-x-6">
                        <Link href="/admin/dashboard" class="heading-md gradient-text">RUSLE Configuration</Link>
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

        <div class="max-w-6xl mx-auto py-8 sm:px-6 lg:px-8">
            <div class="modern-card mb-6">
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h2 class="heading-sm mb-1">Personal RUSLE Parameters</h2>
                        <p class="text-sm text-slate-500">
                            Defaults version: <span class="font-medium text-slate-700">{{ version || 'N/A' }}</span>
                            <span v-if="lastSavedAt" class="ml-3">• Last saved {{ formatTimestamp(lastSavedAt) }}</span>
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-3">
                        <button
                            type="button"
                            class="btn-secondary"
                            @click="resetAll"
                            :disabled="saving"
                        >
                            Restore Defaults
                        </button>
                        <button
                            type="button"
                            class="btn-primary"
                            @click="saveConfig"
                            :disabled="saving || !isDirty"
                        >
                            <span v-if="saving" class="flex items-center">
                                <span class="mr-2 inline-flex h-4 w-4 animate-spin rounded-full border-2 border-white/60 border-t-transparent"></span>
                                Saving...
                            </span>
                            <span v-else>Save Changes</span>
                        </button>
                    </div>
                </div>
                <div v-if="successMessage" class="mt-4 rounded-md bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
                    {{ successMessage }}
                </div>
                <div v-if="errorMessage" class="mt-4 rounded-md bg-rose-50 px-4 py-3 text-sm text-rose-700">
                    {{ errorMessage }}
                </div>
            </div>

            <div v-if="loading" class="modern-card flex items-center justify-center py-20">
                <div class="flex flex-col items-center space-y-3">
                    <span class="inline-flex h-10 w-10 animate-spin rounded-full border-2 border-primary-500 border-t-transparent"></span>
                    <p class="text-sm text-slate-500">Loading configuration...</p>
                </div>
            </div>

            <template v-else>
                <section class="modern-card mb-6">
                    <header class="mb-6">
                        <h2 class="heading-sm mb-1">R Factor (Rainfall Erosivity)</h2>
                        <p class="text-sm text-slate-500">Controls how rainfall intensity influences erosion rates.</p>
                    </header>
                    <div class="grid gap-5 md:grid-cols-2">
                        <ConfigField
                            v-for="field in rFactorFields"
                            :key="field.path"
                            :field="field"
                            :defaults="defaults"
                            :form-state="formState"
                            :is-different="isDifferent"
                            :format-number="formatNumber"
                            :set-number="setNumberValue"
                            :reset-field="resetField"
                        />
                        <ToggleField
                            :field="rFactorToggle"
                            :form-state="formState"
                            :defaults="defaults"
                            :is-different="isDifferent"
                            :reset-field="resetField"
                            :set-boolean="setBooleanValue"
                        />
                    </div>
                </section>

                <section class="modern-card mb-6">
                    <header class="mb-6">
                        <h2 class="heading-sm mb-1">K Factor (Soil Erodibility)</h2>
                        <p class="text-sm text-slate-500">Adjusts soil composition and structure coefficients used in K factor calculation.</p>
                    </header>
                    <div class="grid gap-5 md:grid-cols-2">
                        <ConfigField
                            v-for="field in kFactorFields"
                            :key="field.path"
                            :field="field"
                            :defaults="defaults"
                            :form-state="formState"
                            :is-different="isDifferent"
                            :format-number="formatNumber"
                            :set-number="setNumberValue"
                            :reset-field="resetField"
                        />
                    </div>
                </section>

                <section class="modern-card mb-6">
                    <header class="mb-6">
                        <h2 class="heading-sm mb-1">LS Factor (Slope Length &amp; Steepness)</h2>
                        <p class="text-sm text-slate-500">Parameters that control how terrain slope contributes to erosion potential.</p>
                    </header>
                    <div class="grid gap-5 md:grid-cols-2">
                        <ConfigField
                            v-for="field in lsFactorFields"
                            :key="field.path"
                            :field="field"
                            :defaults="defaults"
                            :form-state="formState"
                            :is-different="isDifferent"
                            :format-number="formatNumber"
                            :set-number="setNumberValue"
                            :reset-field="resetField"
                        />
                    </div>
                </section>

                <section class="modern-card mb-6">
                    <header class="mb-6 flex items-center justify-between">
                        <div>
                            <h2 class="heading-sm mb-1">C Factor (Cover Management)</h2>
                            <p class="text-sm text-slate-500">Adjust per land-cover class coefficients controlling vegetation protection.</p>
                        </div>
                        <button
                            type="button"
                            class="btn-ghost text-sm"
                            @click="resetGroup('c_factor.class_map')"
                            :disabled="!hasClassMapChanges"
                        >
                            Reset Class Map
                        </button>
                    </header>

                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200 text-sm">
                            <thead class="bg-slate-50">
                                <tr class="text-left text-slate-600">
                                    <th class="px-4 py-3 font-semibold">Class</th>
                                    <th class="px-4 py-3 font-semibold">Default</th>
                                    <th class="px-4 py-3 font-semibold">Your Value</th>
                                    <th class="px-4 py-3"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <tr v-for="(defaultValue, classId) in defaults.c_factor?.class_map || {}" :key="classId">
                                    <td class="px-4 py-3 font-medium text-slate-700">Class {{ classId }}</td>
                                    <td class="px-4 py-3 text-slate-500">{{ formatNumber(defaultValue) }}</td>
                                    <td class="px-4 py-3">
                                        <input
                                            type="number"
                                            step="0.01"
                                            class="form-input"
                                            :class="fieldClasses(`c_factor.class_map.${classId}`)"
                                            :value="getValue(formState, `c_factor.class_map.${classId}`)"
                                            @input="setNumberValue(`c_factor.class_map.${classId}`, $event, { step: 0.01 })"
                                        />
                                    </td>
                                    <td class="px-4 py-3 text-right">
                                        <button
                                            type="button"
                                            class="text-sm text-primary-600 hover:text-primary-700"
                                            v-if="isDifferent(`c_factor.class_map.${classId}`)"
                                            @click="resetField(`c_factor.class_map.${classId}`)"
                                        >
                                            Reset
                                        </button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>

                <section class="modern-card mb-6">
                    <header class="mb-6 flex items-center justify-between">
                        <div>
                            <h2 class="heading-sm mb-1">P Factor (Support Practice)</h2>
                            <p class="text-sm text-slate-500">Slope breakpoints used to model terracing and contouring efficiency.</p>
                        </div>
                        <button
                            type="button"
                            class="btn-ghost text-sm"
                            @click="resetGroup('p_factor.breakpoints')"
                            :disabled="!hasBreakpointChanges"
                        >
                            Reset Breakpoints
                        </button>
                    </header>

                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200 text-sm">
                            <thead class="bg-slate-50">
                                <tr class="text-left text-slate-600">
                                    <th class="px-4 py-3 font-semibold">Range</th>
                                    <th class="px-4 py-3 font-semibold">Default Value</th>
                                    <th class="px-4 py-3 font-semibold">Your Value</th>
                                    <th class="px-4 py-3 font-semibold">Default Max Slope</th>
                                    <th class="px-4 py-3 font-semibold">Your Max Slope</th>
                                    <th class="px-4 py-3"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <tr
                                    v-for="(row, index) in formState.p_factor?.breakpoints || []"
                                    :key="`breakpoint-${index}`"
                                >
                                    <td class="px-4 py-3 font-medium text-slate-700">Segment {{ index + 1 }}</td>
                                    <td class="px-4 py-3 text-slate-500">{{ formatNumber(defaults.p_factor.breakpoints?.[index]?.value) }}</td>
                                    <td class="px-4 py-3">
                                        <input
                                            type="number"
                                            step="0.01"
                                            class="form-input"
                                            :class="fieldClasses(`p_factor.breakpoints.${index}.value`)"
                                            :value="row?.value ?? ''"
                                            @input="setNumberValue(`p_factor.breakpoints.${index}.value`, $event, { step: 0.01 })"
                                        />
                                    </td>
                                    <td class="px-4 py-3 text-slate-500">
                                        {{ defaults.p_factor.breakpoints?.[index]?.max_slope ?? '∞' }}
                                    </td>
                                    <td class="px-4 py-3">
                                        <input
                                            type="number"
                                            step="0.1"
                                            class="form-input"
                                            :class="fieldClasses(`p_factor.breakpoints.${index}.max_slope`)"
                                            :value="row?.max_slope ?? ''"
                                            @input="setNumberValue(`p_factor.breakpoints.${index}.max_slope`, $event, { step: 0.1, nullable: true })"
                                            placeholder="Unlimited"
                                        />
                                    </td>
                                    <td class="px-4 py-3 text-right">
                                        <button
                                            type="button"
                                            class="text-sm text-primary-600 hover:text-primary-700"
                                            v-if="isDifferent(`p_factor.breakpoints.${index}.value`) || isDifferent(`p_factor.breakpoints.${index}.max_slope`)"
                                            @click="resetBreakpoint(index)"
                                        >
                                            Reset
                                        </button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>

                <section class="modern-card mb-6">
                    <header class="mb-6 flex items-center justify-between">
                        <div>
                            <h2 class="heading-sm mb-1">Erosion Class Thresholds</h2>
                            <p class="text-sm text-slate-500">Adjust severity thresholds and labels used in statistics breakdowns.</p>
                        </div>
                        <button
                            type="button"
                            class="btn-ghost text-sm"
                            @click="resetGroup('erosion_classes')"
                            :disabled="!hasErosionClassChanges"
                        >
                            Reset Classes
                        </button>
                    </header>

                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200 text-sm">
                            <thead class="bg-slate-50">
                                <tr class="text-left text-slate-600">
                                    <th class="px-4 py-3 font-semibold">Key</th>
                                    <th class="px-4 py-3 font-semibold">Label</th>
                                    <th class="px-4 py-3 font-semibold">Default Range</th>
                                    <th class="px-4 py-3 font-semibold">Min (t/ha/yr)</th>
                                    <th class="px-4 py-3 font-semibold">Max (t/ha/yr)</th>
                                    <th class="px-4 py-3"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <tr
                                    v-for="(row, index) in formState.erosion_classes || []"
                                    :key="row.key || index"
                                >
                                    <td class="px-4 py-3 font-medium text-slate-700">{{ row.key }}</td>
                                    <td class="px-4 py-3">
                                        <input
                                            type="text"
                                            class="form-input"
                                            :class="fieldClasses(`erosion_classes.${index}.label`)"
                                            :value="row.label"
                                            @input="setTextValue(`erosion_classes.${index}.label`, $event)"
                                        />
                                    </td>
                                    <td class="px-4 py-3 text-slate-500">
                                        {{ formatNumber(defaults.erosion_classes?.[index]?.min, 2) }} -
                                        {{ formatNumber(defaults.erosion_classes?.[index]?.max, 2) }}
                                    </td>
                                    <td class="px-4 py-3">
                                        <input
                                            type="number"
                                            step="0.1"
                                            class="form-input"
                                            :class="fieldClasses(`erosion_classes.${index}.min`)"
                                            :value="row.min ?? ''"
                                            @input="setNumberValue(`erosion_classes.${index}.min`, $event, { step: 0.1 })"
                                        />
                                    </td>
                                    <td class="px-4 py-3">
                                        <input
                                            type="number"
                                            step="0.1"
                                            class="form-input"
                                            :class="fieldClasses(`erosion_classes.${index}.max`)"
                                            :value="row.max ?? ''"
                                            @input="setNumberValue(`erosion_classes.${index}.max`, $event, { step: 0.1, nullable: true })"
                                            placeholder="No upper limit"
                                        />
                                    </td>
                                    <td class="px-4 py-3 text-right">
                                        <button
                                            type="button"
                                            class="text-sm text-primary-600 hover:text-primary-700"
                                            v-if="erosionClassChanged(index)"
                                            @click="resetErosionClass(index)"
                                        >
                                            Reset
                                        </button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>

                <section class="modern-card mb-6">
                    <header class="mb-6 flex items-center justify-between">
                        <div>
                            <h2 class="heading-sm mb-1">Rainfall Statistics Interpretation</h2>
                            <p class="text-sm text-slate-500">Customize descriptive buckets for rainfall trends and variability.</p>
                        </div>
                        <button
                            type="button"
                            class="btn-ghost text-sm"
                            @click="resetGroup('rainfall_statistics')"
                            :disabled="!hasRainfallChanges"
                        >
                            Reset Interpretation
                        </button>
                    </header>

                    <div class="grid gap-6 md:grid-cols-2">
                        <div>
                            <h3 class="font-semibold text-slate-800 mb-3">Trend Interpretation</h3>
                            <div class="space-y-3">
                                <div
                                    v-for="(row, index) in formState.rainfall_statistics?.trend_interpretation || []"
                                    :key="`trend-${index}`"
                                    class="rounded-xl border border-slate-200 bg-white p-4"
                                >
                                    <div class="flex items-center justify-between mb-2">
                                        <span class="text-sm font-medium text-slate-700">Bucket {{ index + 1 }}</span>
                                        <button
                                            type="button"
                                            class="text-xs text-primary-600 hover:text-primary-700"
                                            v-if="rainfallTrendChanged(index)"
                                            @click="resetRainfallTrend(index)"
                                        >
                                            Reset
                                        </button>
                                    </div>
                                    <div class="space-y-2">
                                        <label class="block text-xs font-semibold uppercase tracking-wide text-slate-500">
                                            Label
                                            <input
                                                type="text"
                                                class="form-input mt-1"
                                                :class="fieldClasses(`rainfall_statistics.trend_interpretation.${index}.label`)"
                                                :value="row.label"
                                                @input="setTextValue(`rainfall_statistics.trend_interpretation.${index}.label`, $event)"
                                            />
                                        </label>
                                        <label class="block text-xs font-semibold uppercase tracking-wide text-slate-500">
                                            Minimum Trend (mm/year²)
                                            <input
                                                type="number"
                                                step="0.1"
                                                class="form-input mt-1"
                                                :class="fieldClasses(`rainfall_statistics.trend_interpretation.${index}.min`)"
                                                :value="row.min ?? ''"
                                                @input="setNumberValue(`rainfall_statistics.trend_interpretation.${index}.min`, $event, { step: 0.1, nullable: true })"
                                                placeholder="No minimum"
                                            />
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div>
                            <h3 class="font-semibold text-slate-800 mb-3">Coefficient of Variation</h3>
                            <div class="space-y-3">
                                <div
                                    v-for="(row, index) in formState.rainfall_statistics?.cv_interpretation || []"
                                    :key="`cv-${index}`"
                                    class="rounded-xl border border-slate-200 bg-white p-4"
                                >
                                    <div class="flex items-center justify-between mb-2">
                                        <span class="text-sm font-medium text-slate-700">Bucket {{ index + 1 }}</span>
                                        <button
                                            type="button"
                                            class="text-xs text-primary-600 hover:text-primary-700"
                                            v-if="rainfallCvChanged(index)"
                                            @click="resetRainfallCv(index)"
                                        >
                                            Reset
                                        </button>
                                    </div>
                                    <div class="space-y-2">
                                        <label class="block text-xs font-semibold uppercase tracking-wide text-slate-500">
                                            Label
                                            <input
                                                type="text"
                                                class="form-input mt-1"
                                                :class="fieldClasses(`rainfall_statistics.cv_interpretation.${index}.label`)"
                                                :value="row.label"
                                                @input="setTextValue(`rainfall_statistics.cv_interpretation.${index}.label`, $event)"
                                            />
                                        </label>
                                        <label class="block text-xs font-semibold uppercase tracking-wide text-slate-500">
                                            Maximum CV (%)
                                            <input
                                                type="number"
                                                step="0.1"
                                                class="form-input mt-1"
                                                :class="fieldClasses(`rainfall_statistics.cv_interpretation.${index}.max`)"
                                                :value="row.max ?? ''"
                                                @input="setNumberValue(`rainfall_statistics.cv_interpretation.${index}.max`, $event, { step: 0.1, nullable: true })"
                                                placeholder="No maximum"
                                            />
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="mt-6 grid gap-5 md:grid-cols-2">
                        <ConfigField
                            v-for="field in rainfallScalarFields"
                            :key="field.path"
                            :field="field"
                            :defaults="defaults"
                            :form-state="formState"
                            :is-different="isDifferent"
                            :format-number="formatNumber"
                            :set-number="setNumberValue"
                            :reset-field="resetField"
                        />
                    </div>
                </section>

                <section class="modern-card">
                    <header class="mb-6">
                        <h2 class="heading-sm mb-1">Logging</h2>
                        <p class="text-sm text-slate-500">Control how configuration snapshots are attached to computation metadata.</p>
                    </header>
                    <ToggleField
                        :field="loggingToggle"
                        :form-state="formState"
                        :defaults="defaults"
                        :is-different="isDifferent"
                        :reset-field="resetField"
                        :set-boolean="setBooleanValue"
                    />
                </section>
            </template>
        </div>
    </div>
</template>

<script setup>
import { Head, Link, router } from '@inertiajs/vue3'
import { computed, onMounted, ref } from 'vue'
import axios from 'axios'

const defaults = ref({})
const formState = ref({})
const version = ref(null)
const lastSavedAt = ref(null)
const loading = ref(true)
const saving = ref(false)
const successMessage = ref('')
const errorMessage = ref('')

const rFactorFields = [
    { path: 'r_factor.coefficient', label: 'Coefficient', step: 0.001, precision: 3 },
    { path: 'r_factor.intercept', label: 'Intercept', step: 0.01, precision: 2 },
    { path: 'r_factor.long_term_start_year', label: 'Long-term Start Year', step: 1, precision: 0, type: 'int' },
    { path: 'r_factor.long_term_end_year', label: 'Long-term End Year', step: 1, precision: 0, type: 'int' },
]

const rFactorToggle = {
    path: 'r_factor.use_long_term_default',
    label: 'Use Long-term Average',
    description: 'When enabled, rainfall erosivity is computed using long-term rainfall averages.',
}

const kFactorFields = [
    { path: 'k_factor.sand_fraction_multiplier', label: 'Sand Fraction Multiplier', step: 0.01, precision: 2, description: 'Portion of sand fraction retained (default removes 20% fine sand).' },
    { path: 'k_factor.soc_to_organic_multiplier', label: 'SOC to Organic Multiplier', step: 0.0001, precision: 5 },
    { path: 'k_factor.base_constant', label: 'Base Constant', step: 0.01, precision: 2 },
    { path: 'k_factor.m_exponent', label: 'Exponent (M)', step: 0.01, precision: 2 },
    { path: 'k_factor.area_factor', label: 'Area Factor', step: 0.00000001, precision: 8 },
    { path: 'k_factor.organic_matter_subtract', label: 'Organic Matter Subtract', step: 0.1, precision: 1 },
    { path: 'k_factor.structure_coefficient', label: 'Structure Coefficient', step: 0.0001, precision: 4 },
    { path: 'k_factor.structure_baseline', label: 'Structure Baseline', step: 0.1, precision: 1 },
    { path: 'k_factor.permeability_coefficient', label: 'Permeability Coefficient', step: 0.0001, precision: 4 },
    { path: 'k_factor.permeability_baseline', label: 'Permeability Baseline', step: 0.1, precision: 1 },
]

const lsFactorFields = [
    { path: 'ls_factor.grid_size', label: 'Flow Accumulation Grid Size (m)', step: 1, precision: 0, type: 'int' },
    { path: 'ls_factor.flow_length_reference', label: 'Reference Flow Length', step: 0.01, precision: 2 },
    { path: 'ls_factor.flow_exponent', label: 'Flow Exponent', step: 0.01, precision: 2 },
    { path: 'ls_factor.slope_normalisation', label: 'Slope Normalisation', step: 0.001, precision: 3 },
    { path: 'ls_factor.slope_exponent', label: 'Slope Exponent', step: 0.01, precision: 2 },
    { path: 'ls_factor.minimum_slope_radians', label: 'Minimum Slope (radians)', step: 0.0001, precision: 4 },
]

const rainfallScalarFields = [
    { path: 'rainfall_statistics.mean_scale', label: 'Mean Scale (m)', step: 10, precision: 0, type: 'int' },
    { path: 'rainfall_statistics.cv_scale', label: 'CV Scale (m)', step: 10, precision: 0, type: 'int' },
]

const loggingToggle = {
    path: 'logging.include_config_snapshot',
    label: 'Attach Config Snapshot',
    description: 'Embed the effective configuration in task metadata for auditing.',
}

const deepClone = (value) => JSON.parse(JSON.stringify(value ?? {}))

const getValue = (source, path) => {
    if (!source) {
        return undefined
    }
    return path.split('.').reduce((acc, key) => {
        if (acc === undefined || acc === null) {
            return undefined
        }
        const idx = /^\d+$/.test(key) ? Number(key) : key
        return acc[idx]
    }, source)
}

const setValue = (target, path, value) => {
    const keys = path.split('.')
    let current = target

    keys.forEach((key, index) => {
        const isLast = index === keys.length - 1
        const isIndex = /^\d+$/.test(key)
        const resolvedKey = isIndex ? Number(key) : key

        if (isLast) {
            if (isIndex) {
                if (!Array.isArray(current)) {
                    current = []
                }
                current[resolvedKey] = value
            } else {
                current[resolvedKey] = value
            }
            return
        }

        if (isIndex) {
            if (!Array.isArray(current[resolvedKey])) {
                current[resolvedKey] = []
            }
            current = current[resolvedKey]
        } else {
            if (typeof current[resolvedKey] !== 'object' || current[resolvedKey] === null) {
                current[resolvedKey] = {}
            }
            current = current[resolvedKey]
        }
    })
}

const removeValue = (target, path) => {
    const keys = path.split('.')
    const stack = []
    let current = target

    for (let i = 0; i < keys.length; i += 1) {
        const key = keys[i]
        const resolvedKey = /^\d+$/.test(key) ? Number(key) : key

        if (current === undefined || current === null) {
            return
        }

        stack.push({ parent: current, key: resolvedKey })

        if (i === keys.length - 1) {
            break
        }

        current = current[resolvedKey]
    }

    const last = stack.pop()
    if (!last) {
        return
    }

    if (Array.isArray(last.parent)) {
        last.parent[last.key] = deepClone(getValue(defaults.value, path))
    } else if (typeof last.parent === 'object' && last.parent !== null) {
        last.parent[last.key] = deepClone(getValue(defaults.value, path))
    }
}

const deepEqual = (a, b) => {
    if (a === b) return true
    if (typeof a !== typeof b) return false
    if (typeof a !== 'object' || a === null || b === null) return false
    return JSON.stringify(a) === JSON.stringify(b)
}

const diffObject = (current, base) => {
    if (deepEqual(current, base)) {
        return undefined
    }

    if (typeof current !== 'object' || current === null) {
        return current
    }

    if (Array.isArray(current)) {
        const result = []
        const maxLength = Math.max(current.length, Array.isArray(base) ? base.length : 0)
        let hasValue = false
        for (let i = 0; i < maxLength; i += 1) {
            const diff = diffObject(current[i], base?.[i])
            if (diff !== undefined) {
                result[i] = diff
                hasValue = true
            }
        }
        return hasValue ? result : undefined
    }

    const result = {}
    let hasValue = false
    for (const key of Object.keys(base ?? {})) {
        const diff = diffObject(current?.[key], base?.[key])
        if (diff !== undefined) {
            result[key] = diff
            hasValue = true
        }
    }

    return hasValue ? result : undefined
}

const buildOverridesPayload = () => {
    const diff = diffObject(formState.value, defaults.value)
    return diff ?? {}
}

const isDirty = computed(() => {
    const overrides = buildOverridesPayload()
    return JSON.stringify(overrides) !== JSON.stringify({})
})

const formatNumber = (value, digits = 3) => {
    if (value === null || value === undefined || value === '') {
        return '—'
    }

    const parsed = Number(value)
    if (Number.isNaN(parsed)) {
        return value
    }

    return parsed.toFixed(digits).replace(/\.?0+$/, '')
}

const fieldClasses = (path) => ({
    'ring-2 ring-offset-1 ring-primary-300': isDifferent(path),
})

const isDifferent = (path) => {
    return !deepEqual(getValue(formState.value, path), getValue(defaults.value, path))
}

const setNumberValue = (path, event, options = {}) => {
    const { step = 0.01, nullable = false, type = 'float' } = options
    const raw = event?.target?.value

    if (raw === '' || raw === null || raw === undefined) {
        if (nullable) {
            setValue(formState.value, path, null)
        } else {
            resetField(path)
        }
        return
    }

    const parsed = type === 'int' ? parseInt(raw, 10) : parseFloat(raw)
    if (Number.isNaN(parsed)) {
        return
    }

    const fixed = type === 'int' ? parsed : Number(parsed.toFixed(String(step).split('.')[1]?.length ?? 3))
    setValue(formState.value, path, fixed)
}

const setTextValue = (path, event) => {
    setValue(formState.value, path, event?.target?.value ?? '')
}

const setBooleanValue = (path, event) => {
    setValue(formState.value, path, !!event?.target?.checked)
}

const resetField = (path) => {
    setValue(formState.value, path, deepClone(getValue(defaults.value, path)))
}

const resetGroup = (basePath) => {
    const defaultValue = deepClone(getValue(defaults.value, basePath))
    setValue(formState.value, basePath, defaultValue)
}

const resetBreakpoint = (index) => {
    setValue(formState.value, `p_factor.breakpoints.${index}`, deepClone(defaults.value.p_factor?.breakpoints?.[index]))
}

const resetErosionClass = (index) => {
    setValue(formState.value, `erosion_classes.${index}`, deepClone(defaults.value.erosion_classes?.[index]))
}

const resetRainfallTrend = (index) => {
    setValue(formState.value, `rainfall_statistics.trend_interpretation.${index}`, deepClone(defaults.value.rainfall_statistics?.trend_interpretation?.[index]))
}

const resetRainfallCv = (index) => {
    setValue(formState.value, `rainfall_statistics.cv_interpretation.${index}`, deepClone(defaults.value.rainfall_statistics?.cv_interpretation?.[index]))
}

const erosionClassChanged = (index) => {
    const path = `erosion_classes.${index}`
    return !deepEqual(getValue(formState.value, path), getValue(defaults.value, path))
}

const rainfallTrendChanged = (index) => {
    const path = `rainfall_statistics.trend_interpretation.${index}`
    return !deepEqual(getValue(formState.value, path), getValue(defaults.value, path))
}

const rainfallCvChanged = (index) => {
    const path = `rainfall_statistics.cv_interpretation.${index}`
    return !deepEqual(getValue(formState.value, path), getValue(defaults.value, path))
}

const hasClassMapChanges = computed(() => {
    const current = formState.value.c_factor?.class_map || {}
    const base = defaults.value.c_factor?.class_map || {}
    return Object.keys(base).some((key) => !deepEqual(current[key], base[key]))
})

const hasBreakpointChanges = computed(() => {
    const current = formState.value.p_factor?.breakpoints || []
    const base = defaults.value.p_factor?.breakpoints || []
    if (current.length !== base.length) return true
    return current.some((row, idx) => !deepEqual(row, base[idx]))
})

const hasErosionClassChanges = computed(() => {
    const current = formState.value.erosion_classes || []
    const base = defaults.value.erosion_classes || []
    if (current.length !== base.length) return true
    return current.some((row, idx) => !deepEqual(row, base[idx]))
})

const hasRainfallChanges = computed(() => {
    const current = formState.value.rainfall_statistics || {}
    const base = defaults.value.rainfall_statistics || {}
    return !deepEqual(current, base)
})

const formatTimestamp = (value) => {
    if (!value) return 'never'
    const date = new Date(value)
    return date.toLocaleString()
}

const fetchConfig = async () => {
    loading.value = true
    successMessage.value = ''
    errorMessage.value = ''
    try {
        const response = await axios.get('/api/admin/rusle/config')
        defaults.value = deepClone(response.data.defaults || {})
        formState.value = deepClone(response.data.effective || response.data.defaults || {})
        version.value = response.data.defaults_version || null
        lastSavedAt.value = response.data.last_saved_at || null
    } catch (error) {
        console.error('Failed to load RUSLE configuration', error)
        errorMessage.value = error.response?.data?.message || 'Failed to load configuration'
    } finally {
        loading.value = false
    }
}

const saveConfig = async () => {
    saving.value = true
    successMessage.value = ''
    errorMessage.value = ''
    try {
        const overrides = buildOverridesPayload()
        const response = await axios.post('/api/admin/rusle/config', { overrides })
        formState.value = deepClone(response.data.effective || formState.value)
        defaults.value = deepClone(response.data.defaults || defaults.value)
        lastSavedAt.value = response.data.last_saved_at || new Date().toISOString()
        version.value = response.data.defaults_version || version.value
        successMessage.value = 'Configuration saved. Future computations will use your personalized settings.'
    } catch (error) {
        console.error('Failed to save configuration', error)
        errorMessage.value = error.response?.data?.message || 'Failed to save configuration'
    } finally {
        saving.value = false
    }
}

const resetAll = async () => {
    if (!confirm('Restore default RUSLE parameters for your account?')) {
        return
    }
    saving.value = true
    successMessage.value = ''
    errorMessage.value = ''
    try {
        const response = await axios.post('/api/admin/rusle/config', { reset: true })
        formState.value = deepClone(response.data.effective || response.data.defaults || {})
        defaults.value = deepClone(response.data.defaults || defaults.value)
        lastSavedAt.value = response.data.last_saved_at || new Date().toISOString()
        version.value = response.data.defaults_version || version.value
        successMessage.value = 'Defaults restored successfully.'
    } catch (error) {
        console.error('Failed to reset configuration', error)
        errorMessage.value = error.response?.data?.message || 'Failed to reset configuration'
    } finally {
        saving.value = false
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

onMounted(() => {
    fetchConfig()
})

const ConfigField = {
    props: ['field', 'defaults', 'formState', 'isDifferent', 'formatNumber', 'setNumber', 'resetField'],
    methods: {
        getValue(source, path) {
            if (!source) {
                return undefined
            }
            return path.split('.').reduce((acc, key) => {
                if (acc === undefined || acc === null) {
                    return undefined
                }
                const idx = /^\d+$/.test(key) ? Number(key) : key
                return acc[idx]
            }, source)
        },
        getDefault(path) {
            return this.getValue(this.defaults, path)
        },
    },
    template: `
        <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <label class="flex items-start justify-between gap-4">
                <div>
                    <span class="block text-sm font-semibold text-slate-700">{{ field.label }}</span>
                    <span v-if="field.description" class="mt-1 block text-xs text-slate-500">{{ field.description }}</span>
                </div>
                <span class="text-xs text-slate-400">
                    Default: {{ formatNumber(getDefault(field.path), field.precision ?? 3) }}
                </span>
            </label>
            <div class="mt-3 flex items-center space-x-3">
                <input
                    type="number"
                    class="form-input flex-1"
                    :class="{'ring-2 ring-offset-1 ring-primary-300': isDifferent(field.path)}"
                    :step="field.step ?? 0.01"
                    :value="getValue(formState, field.path)"
                    @input="setNumber(field.path, $event, { step: field.step ?? 0.01, type: field.type })"
                />
                <button
                    type="button"
                    class="text-sm text-primary-600 hover:text-primary-700"
                    v-if="isDifferent(field.path)"
                    @click="resetField(field.path)"
                >
                    Reset
                </button>
            </div>
        </div>
    `,
}

const ToggleField = {
    props: ['field', 'formState', 'defaults', 'isDifferent', 'resetField', 'setBoolean'],
    methods: {
        getValue(source, path) {
            if (!source) {
                return undefined
            }
            return path.split('.').reduce((acc, key) => {
                if (acc === undefined || acc === null) {
                    return undefined
                }
                const idx = /^\d+$/.test(key) ? Number(key) : key
                return acc[idx]
            }, source)
        },
    },
    template: `
        <div class="flex items-center justify-between rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <div>
                <p class="text-sm font-semibold text-slate-700">{{ field.label }}</p>
                <p class="mt-1 text-xs text-slate-500" v-if="field.description">{{ field.description }}</p>
            </div>
            <div class="flex items-center space-x-3">
                <label class="relative inline-flex cursor-pointer items-center">
                    <input
                        type="checkbox"
                        class="peer sr-only"
                        :checked="!!getValue(formState, field.path)"
                        @change="setBoolean(field.path, $event)"
                    />
                    <div class="peer h-6 w-11 rounded-full border bg-slate-200 transition-colors duration-200 peer-checked:bg-primary-500"></div>
                </label>
                <button
                    type="button"
                    class="text-sm text-primary-600 hover:text-primary-700"
                    v-if="isDifferent(field.path)"
                    @click="resetField(field.path)"
                >
                    Reset
                </button>
            </div>
        </div>
    `,
}
</script>

<style scoped>
.form-input {
    @apply w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700 transition focus:border-primary-400 focus:outline-none focus:ring-2 focus:ring-primary-200;
}

.btn-primary {
    @apply inline-flex items-center justify-center rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-primary-700 disabled:cursor-not-allowed disabled:bg-primary-300;
}

.btn-secondary {
    @apply inline-flex items-center justify-center rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm transition hover:border-primary-400 hover:text-primary-600 disabled:cursor-not-allowed disabled:text-slate-400;
}

.btn-danger {
    @apply inline-flex items-center justify-center rounded-lg bg-rose-500 px-4 py-2 text-sm font-semibold text-white shadow transition hover:bg-rose-600;
}

.btn-ghost {
    @apply inline-flex items-center justify-center rounded-lg border border-transparent px-3 py-2 text-slate-500 transition hover:border-slate-200 hover:text-primary-600 disabled:cursor-not-allowed disabled:text-slate-300;
}

.nav-modern {
    @apply bg-white shadow;
}

.heading-sm {
    @apply text-lg font-semibold text-slate-800;
}

.heading-md {
    @apply text-xl font-semibold;
}

.modern-card {
    @apply rounded-2xl border border-slate-200 bg-white p-6 shadow-sm;
}
</style>

