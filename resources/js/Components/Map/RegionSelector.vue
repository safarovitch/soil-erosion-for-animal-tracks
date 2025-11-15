<template>
    <div class="">
        <h3 class="text-lg font-semibold text-gray-900 mb-3">{{ __("Area Selection") }}</h3>

        <div class="">
            <div class="space-y-3">
                <div v-for="region in regionsWithDistricts" :key="region.id" class="border border-gray-100 rounded-lg">
                    <div class="p-3">
                        <label class="gap-3 text-sm text-gray-800 cursor-pointer select-none">
                            <div class="flex items-start gap-3">
                                <input type="checkbox" class="mt-1 h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500" :checked="isRegionSelected(region.id)" @change="
                                    toggleRegion(
                                        region,
                                        $event.target.checked
                                    )
                                    " />
                                <div>
                                    <div class="font-semibold text-gray-900 mb-1">
                                        {{ region.display_name || region.name_tj || region.name_en || region.name }}
                                    </div>
                                    <p class="mt-1 text-xs text-gray-500">
                                        {{ regionDescription(region.id) }}
                                    </p>
                                </div>
                            </div>
                        </label>

                        <div v-if="allowDistrictSelection(region.id)" class="text-xs text-center">
                            <button type="button" class="mx-auto p-2 self-center items-center justify-between mt-2 text-gray-500 hover:text-gray-700 focus:outline-none" @click="toggleRegionExpanded(region.id)" :aria-label="expandedRegions.has(region.id)
                                ? __('Collapse region')
                                : __('Expand region')
                                ">
                                <span v-if="expandedRegions.has(region.id)" class="flex items-center gap-2">
                                    <span>{{ __("Hide districts") }} </span>
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
                                    </svg>
                                </span>
                                <span v-else class="flex items-center gap-2">
                                    <span>{{ __("Show districts") }} </span>
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
                                    </svg>
                                </span>
                            </button>
                        </div>
                    </div>

                    <transition name="fade">
                        <div v-if="expandedRegions.has(region.id)" class="px-4 pb-3 pt-1 space-y-1">
                            <label v-for="district in region.districts" :key="district.id" class="flex items-center justify-between gap-3 rounded-md px-2 py-1.5 text-sm" :class="allowDistrictSelection(region.id)
                                ? 'hover:bg-blue-50 cursor-pointer'
                                : 'text-gray-400 cursor-not-allowed'
                                ">
                                <div class="flex items-center gap-3">
                                    <input type="checkbox" class="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500" :checked="isDistrictSelected(district.id)
                                        " :disabled="!allowDistrictSelection(region.id)
                                            " @change="
                                                toggleDistrict(
                                                    district,
                                                    $event.target.checked
                                                )
                                                " />
                                    <div>
                                        <div class="font-medium text-gray-800">
                                            {{ district.display_name || district.name_en || district.name_tj || district.name }}
                                        </div>
                                        <div v-if="district.name_tj" class="text-xs text-gray-500">
                                            {{ district.name_tj }}
                                        </div>
                                    </div>
                                </div>
                            </label>
                        </div>
                    </transition>
                </div>
            </div>
        </div>

        <p class="text-xs text-gray-500 leading-relaxed">
            {{__("Select one or more regions. You may pick districts directly with no region selected, or drill into a single region for finer control. District checkboxes disable automatically when more than one region is active.")}}
        </p>

        <!-- Custom Area Selection Button -->
        <div class="mt-4 pt-4 border-t border-gray-200">
            <button
                type="button"
                @click="handleCustomAreaClick"
                :class="[
                    'w-full px-4 py-2.5 rounded-lg text-sm font-medium transition-colors duration-200 flex items-center justify-center space-x-2',
                    isCustomAreaActive
                        ? 'bg-blue-600 text-white hover:bg-blue-700'
                        : 'bg-gray-100 text-gray-700 hover:bg-gray-200 border border-gray-300'
                ]"
            >
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-5 h-5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l11.563-11.563zM16.862 4.487l-3.274 3.274M16.862 4.487L19.5 7.125" />
                </svg>
                <span>{{ isCustomAreaActive ? __('Drawing Custom Area...') : __('Draw Custom Area') }}</span>
            </button>
            <p class="mt-2 text-xs text-gray-500 text-center">
                {{ __("Draw a polygon on the map to calculate erosion statistics for a custom area") }}
            </p>
        </div>
    </div>
</template>

<script setup>
import { ref, computed, watch, onMounted } from "vue";

const props = defineProps({
    selectedRegion: Object,
    selectedDistrict: Object,
    selectedAreas: {
        type: Array,
        default: () => [],
    },
    regions: {
        type: Array,
        default: () => [],
    },
    districts: {
        type: Array,
        default: () => [],
    },
});

const emit = defineEmits([
    "update:selectedRegion",
    "update:selectedDistrict",
    "region-change",
    "district-change",
    "areas-change",
    "custom-area-toggle",
]);

const selectedRegionIds = ref(new Set());
const selectedDistrictIds = ref(new Set());
const expandedRegions = ref(new Set());
const suppressEmit = ref(false);
const isCustomAreaActive = ref(false);

const regionMap = computed(() => {
    const map = new Map();
    (props.regions || []).forEach((region) => {
        map.set(region.id, region);
    });
    return map;
});

const districtsByRegion = computed(() => {
    const grouped = new Map();
    (props.districts || []).forEach((district) => {
        if (!grouped.has(district.region_id)) {
            grouped.set(district.region_id, []);
        }
        grouped.get(district.region_id).push(district);
    });
    return grouped;
});

const regionsWithDistricts = computed(() => {
    return (props.regions || []).map((region) => {
        const districts = (districtsByRegion.value.get(region.id) || []).slice().sort((a, b) =>
            (a.display_name || a.name_en || a.name || "").localeCompare(
                b.display_name || b.name_en || b.name || ""
            )
        );

        return {
            ...region,
            districts,
        };
    }).sort((a, b) =>
        (a.display_name || a.name_en || a.name || "").localeCompare(
            b.display_name || b.name_en || b.name || ""
        )
    );
});

const allowDistrictSelection = (regionId) => {
    return selectedRegionIds.value.size === 0;
};

const isRegionSelected = (regionId) => selectedRegionIds.value.has(regionId);
const isDistrictSelected = (districtId) =>
    selectedDistrictIds.value.has(districtId);

const setRegionSelection = (updater) => {
    const next = new Set(selectedRegionIds.value);
    updater(next);
    selectedRegionIds.value = next;
};

const setDistrictSelection = (updater) => {
    const next = new Set(selectedDistrictIds.value);
    updater(next);
    selectedDistrictIds.value = next;
};

const clearAllSelections = () => {
    selectedRegionIds.value = new Set();
    selectedDistrictIds.value = new Set();
};

const regionDescription = (regionId) => {
    if (!selectedRegionIds.value.has(regionId)) {
        return selectedRegionIds.value.size === 0
            ? __("Select to include this region")
            : __("Select to add this region to the analysis");
    }

    if (selectedRegionIds.value.size >= 1) {
        return __("District selection disabled while a region is selected");
    }

    return __("Entire region selected");
};

const buildAreasPayload = () => {
    const districtPayload = Array.from(selectedDistrictIds.value)
        .map((id) =>
            (props.districts || []).find((district) => district.id === id)
        )
        .filter(Boolean)
        .map((district) => ({
            ...district,
            type: "district",
            area_type: "district",
        }));

    const regionIds = Array.from(selectedRegionIds.value);

    if (regionIds.length === 0) {
        return districtPayload;
    }

    if (regionIds.length === 1) {
        if (districtPayload.length > 0) {
            return districtPayload;
        }
        const region = regionMap.value.get(regionIds[0]);
        return region
            ? [
                {
                    ...region,
                    type: "region",
                    area_type: "region",
                },
            ]
            : [];
    }

    return regionIds
        .map((id) => regionMap.value.get(id))
        .filter(Boolean)
        .map((region) => ({
            ...region,
            type: "region",
            area_type: "region",
        }));
};

const emitSelectionChange = () => {
    if (suppressEmit.value) {
        return;
    }

    const payload = buildAreasPayload();
    emit("areas-change", payload);

    const regionIds = Array.from(selectedRegionIds.value);

    if (regionIds.length === 1) {
        const regionId = regionIds[0];
        const region = regionMap.value.get(regionId) || null;
        emit("update:selectedRegion", region);
        emit("region-change", region);
    } else {
        emit("update:selectedRegion", null);
        emit("region-change", null);
    }

    if (selectedDistrictIds.value.size === 1) {
        const districtId = Array.from(selectedDistrictIds.value)[0];
        const district =
            (props.districts || []).find((d) => d.id === districtId) || null;
        emit("update:selectedDistrict", district);
        emit("district-change", district);
    } else {
        emit("update:selectedDistrict", null);
        emit("district-change", null);
    }
};

const toggleRegion = (region, checked) => {
    if (!region) return;

    setRegionSelection((set) => {
        if (checked) {
            set.add(region.id);
        } else {
            set.delete(region.id);
        }
    });

    if (!checked) {
        setDistrictSelection((set) => {
            for (const district of region.districts || []) {
                set.delete(district.id);
            }
        });
    }

    if (
        selectedRegionIds.value.size > 0 &&
        selectedDistrictIds.value.size > 0
    ) {
        selectedDistrictIds.value = new Set();
    }

    emitSelectionChange();
};

const toggleDistrict = (district, checked) => {
    if (!district || !allowDistrictSelection(district.region_id)) {
        return;
    }

    setDistrictSelection((set) => {
        if (checked) {
            set.add(district.id);
        } else {
            set.delete(district.id);
        }
    });

    emitSelectionChange();
};

const toggleRegionExpanded = (regionId) => {
    const next = new Set(expandedRegions.value);
    if (next.has(regionId)) {
        next.delete(regionId);
    } else {
        next.add(regionId);
    }
    expandedRegions.value = next;
};

const handleCustomAreaClick = () => {
    isCustomAreaActive.value = !isCustomAreaActive.value;
    emit("custom-area-toggle", isCustomAreaActive.value);
    
    // Clear region/district selections when custom area is activated
    if (isCustomAreaActive.value) {
        clearAllSelections();
        emitSelectionChange();
    }
};

watch(
    () => props.selectedAreas,
    (areas) => {
        suppressEmit.value = true;

        if (!areas || areas.length === 0) {
            clearAllSelections();
            suppressEmit.value = false;
            return;
        }

        const regionIds = new Set();
        const districtIds = new Set();

        areas.forEach((area) => {
            if (
                area &&
                (area.type === "region" || area.area_type === "region")
            ) {
                regionIds.add(area.id);
            }
            if (
                area &&
                (area.type === "district" ||
                    area.area_type === "district" ||
                    area.region_id)
            ) {
                if (area.id != null) {
                    districtIds.add(area.id);
                }
            }
        });

        selectedRegionIds.value = regionIds;

        if (regionIds.size === 0) {
            selectedDistrictIds.value = districtIds;
        } else {
            selectedDistrictIds.value = new Set();
        }

        suppressEmit.value = false;
    },
    { deep: true }
);

watch(
    () => props.selectedRegion,
    (region) => {
        suppressEmit.value = true;

        if (!region) {
            selectedRegionIds.value = new Set();
            selectedDistrictIds.value = new Set();
            suppressEmit.value = false;
            return;
        }

        selectedRegionIds.value = new Set([region.id]);
        selectedDistrictIds.value = new Set();
        suppressEmit.value = false;
    },
    { immediate: true }
);

watch(
    () => props.selectedDistrict,
    (district) => {
        suppressEmit.value = true;

        if (!district) {
            selectedDistrictIds.value = new Set();
            suppressEmit.value = false;
            return;
        }

        selectedRegionIds.value = new Set();
        selectedDistrictIds.value = new Set([district.id]);
        suppressEmit.value = false;
    },
    { immediate: true }
);
</script>

<style scoped>
.fade-enter-active,
.fade-leave-active {
    transition: opacity 0.18s ease, transform 0.18s ease;
}

.fade-enter-from,
.fade-leave-to {
    opacity: 0;
    transform: translateY(-4px);
}
</style>
