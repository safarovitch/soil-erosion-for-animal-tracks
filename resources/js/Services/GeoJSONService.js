// GeoJSON Service for extracting district and region data
export class GeoJSONService {
  static async loadDistrictsFromGeoJSON(geoJsonUrl, existingRegions = []) {
    try {
      console.log('Loading districts from GeoJSON:', geoJsonUrl)

      const response = await fetch(geoJsonUrl)
      const geoJsonData = await response.json()

      const districts = []
      const regions = new Set()

      geoJsonData.features.forEach((feature, index) => {
        const properties = feature.properties

        // Extract district information
        const district = {
          id: index + 1, // Generate ID since GeoJSON doesn't have one
          name_en: properties.shapeName || `District ${index + 1}`,
          name_tj: properties.shapeName || `District ${index + 1}`, // Use same name for now
          code: properties.shapeID || `D${index + 1}`,
          area_km2: 0, // Will need to calculate or get from another source
          geometry: JSON.stringify(feature.geometry),
          region_id: null, // Will be set below
          // Add GeoJSON specific properties
          shapeISO: properties.shapeISO,
          shapeID: properties.shapeID,
          shapeGroup: properties.shapeGroup,
          shapeType: properties.shapeType
        }

        // Map district to existing region
        const regionName = this.extractRegionFromDistrict(properties.shapeName)
        if (regionName && existingRegions.length > 0) {
          const matchingRegion = existingRegions.find(region => 
            region.name_en.toLowerCase().includes(regionName.toLowerCase()) ||
            regionName.toLowerCase().includes(region.name_en.toLowerCase())
          )
          if (matchingRegion) {
            district.region_id = matchingRegion.id
          }
        }

        districts.push(district)

        // Try to extract region information from district name
        // This is a simple approach - you might need to refine this logic
        if (regionName) {
          regions.add(regionName)
        }
      })

      // Create regions array
      const regionsArray = Array.from(regions).map((regionName, index) => ({
        id: index + 1,
        name_en: regionName,
        name_tj: regionName,
        code: `R${index + 1}`,
        area_km2: 0,
        geometry: null // Regions would need to be calculated from districts
      }))

      console.log(`Loaded ${districts.length} districts and ${regionsArray.length} regions from GeoJSON`)

      return {
        districts,
        regions: regionsArray
      }
    } catch (error) {
      console.error('Error loading districts from GeoJSON:', error)
      throw error
    }
  }

  static extractRegionFromDistrict(districtName) {
    // Simple region extraction logic based on district names
    // This is a basic implementation - you might need to refine this
    if (!districtName) return null

    // Common region patterns in Tajikistan
    const regionPatterns = {
      'Dushanbe': 'Dushanbe',
      'Sughd': 'Sughd',
      'Khatlon': 'Khatlon',
      'GBAO': 'Gorno-Badakhshan',
      'RRS': 'Region of Republican Subordination'
    }

    // Check for exact matches first
    for (const [pattern, region] of Object.entries(regionPatterns)) {
      if (districtName.toLowerCase().includes(pattern.toLowerCase())) {
        return region
      }
    }

    // If no pattern matches, try to extract from common prefixes/suffixes
    if (districtName.toLowerCase().includes('district')) {
      return 'Unknown Region'
    }

    return 'Unknown Region'
  }

  static async getDistrictNames(geoJsonUrl) {
    try {
      const response = await fetch(geoJsonUrl)
      const geoJsonData = await response.json()

      return geoJsonData.features.map(feature => feature.properties.shapeName)
    } catch (error) {
      console.error('Error getting district names:', error)
      return []
    }
  }
}
