export const YEAR_PERIODS = [
  {
    id: '1993-2003',
    label: '1993 - 2003',
    startYear: 1993,
    endYear: 2003,
  },
  {
    id: '2003-2013',
    label: '2003 - 2013',
    startYear: 2003,
    endYear: 2013,
  },
  {
    id: '2013-2023',
    label: '2013 - 2023',
    startYear: 2013,
    endYear: 2023,
  },
  {
    id: '1993-2023',
    label: '30-year Average (1993 - 2023)',
    startYear: 1993,
    endYear: 2023,
  },
]

export const DEFAULT_YEAR_PERIOD = YEAR_PERIODS[YEAR_PERIODS.length - 1]

export const findYearPeriodById = (id) =>
  YEAR_PERIODS.find((period) => period.id === id) || DEFAULT_YEAR_PERIOD

