// Calculate current year for dynamic total range
const currentYear = new Date().getFullYear();
const startYear = 1993;

// Generate 5-year ranges from 1993
const generateFiveYearRanges = () => {
  const ranges = [];
  let year = startYear;
  
  while (year < currentYear) {
    const endYear = Math.min(year + 4, currentYear - 1);
    const rangeId = `${year}-${endYear}`;
    const totalId = `${startYear}-${currentYear - 1}`;
    
    // Skip if this range would be the same as the total range
    if (rangeId !== totalId) {
      ranges.push({
        id: rangeId,
        label: `${year} - ${endYear}`,
        startYear: year,
        endYear: endYear,
      });
    }
    year = endYear + 1;
  }
  
  return ranges;
};

export const YEAR_PERIODS = [
  ...generateFiveYearRanges(),
  {
    id: `${startYear}-${currentYear - 1}`,
    label: `Total (${startYear} - ${currentYear - 1})`,
    startYear: startYear,
    endYear: currentYear - 1,
  },
]

export const DEFAULT_YEAR_PERIOD = YEAR_PERIODS[YEAR_PERIODS.length - 1]

export const findYearPeriodById = (id) =>
  YEAR_PERIODS.find((period) => period.id === id) || DEFAULT_YEAR_PERIOD

