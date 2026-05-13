// js/weather-api.js
class WeatherAPI {
    constructor(baseUrl) {
        this.baseUrl = baseUrl;
        this.cache = new Map();
        this.cacheDuration = 24 * 60 * 60 * 1000; // KNMI day data is refreshed daily
    }

    async fetchWeatherData(date, station = 260, forceRefresh = false) {
        const cacheKey = `${date}-${station}`;
        const cachedData = this.cache.get(cacheKey);
        
        if (!forceRefresh && cachedData && Date.now() - cachedData.timestamp < this.cacheDuration) {
            return cachedData.data;
        }

        try {
            const refreshSuffix = forceRefresh ? `&_=${Date.now()}` : '';
            const response = await fetch(`${this.baseUrl}/day?date=${date}&station=${station}${refreshSuffix}`, {
                method: 'GET',
                cache: forceRefresh ? 'reload' : 'default',
                headers: {
                    'Accept': 'application/json'
                }
            });
            
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            
            const result = await response.json();
            
            if (!result.success) {
                throw new Error(result.error?.message || 'API request failed');
            }
            
            // Cache the result
            this.cache.set(cacheKey, {
                data: result.data,
                timestamp: Date.now()
            });
            
            return result.data;
        } catch (error) {
            console.error('Error fetching weather data:', error);
            throw error;
        }
    }

    async fetchPeriodData(startDate, endDate, station = 260, forceRefresh = false) {
        const cacheKey = `period-${startDate}-${endDate}-${station}`;
        const cachedData = this.cache.get(cacheKey);
        
        if (!forceRefresh && cachedData && Date.now() - cachedData.timestamp < this.cacheDuration) {
            return cachedData.data;
        }

        try {
            const refreshSuffix = forceRefresh ? `&_=${Date.now()}` : '';
            const response = await fetch(`${this.baseUrl}/period?start=${startDate}&end=${endDate}&station=${station}${refreshSuffix}`, {
                method: 'GET',
                cache: forceRefresh ? 'reload' : 'default',
                headers: {
                    'Accept': 'application/json'
                }
            });
            
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            
            const result = await response.json();
            
            if (!result.success) {
                throw new Error(result.error?.message || 'API request failed');
            }
            
            // Cache the result
            this.cache.set(cacheKey, {
                data: result.data,
                timestamp: Date.now()
            });
            
            return result.data;
        } catch (error) {
            console.error('Error fetching period data:', error);
            throw error;
        }
    }

    async fetchMonthlyStats(year, month, station = 260, forceRefresh = false) {
        const cacheKey = `stats-${year}-${month}-${station}`;
        const cachedData = this.cache.get(cacheKey);
        
        if (!forceRefresh && cachedData && Date.now() - cachedData.timestamp < this.cacheDuration) {
            return cachedData.data;
        }

        try {
            const refreshSuffix = forceRefresh ? `&_=${Date.now()}` : '';
            const response = await fetch(`${this.baseUrl}/stats?year=${year}&month=${month}&station=${station}${refreshSuffix}`, {
                method: 'GET',
                cache: forceRefresh ? 'reload' : 'default',
                headers: {
                    'Accept': 'application/json'
                }
            });
            
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            
            const result = await response.json();
            
            if (!result.success) {
                throw new Error(result.error?.message || 'API request failed');
            }
            
            // Cache the result
            this.cache.set(cacheKey, {
                data: result.data,
                timestamp: Date.now()
            });
            
            return result.data;
        } catch (error) {
            console.error('Error fetching monthly stats:', error);
            throw error;
        }
    }

    async fetchDateRange(station = 260, forceRefresh = false) {
        const cacheKey = `range-${station}`;
        const cachedData = this.cache.get(cacheKey);
        
        if (!forceRefresh && cachedData && Date.now() - cachedData.timestamp < this.cacheDuration) {
            return cachedData.data;
        }

        try {
            const refreshSuffix = forceRefresh ? `&_=${Date.now()}` : '';
            const response = await fetch(`${this.baseUrl}/range?station=${station}${refreshSuffix}`, {
                method: 'GET',
                cache: forceRefresh ? 'reload' : 'default',
                headers: {
                    'Accept': 'application/json'
                }
            });
            
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            
            const result = await response.json();
            
            if (!result.success) {
                throw new Error(result.error?.message || 'API request failed');
            }
            
            // Cache the result for longer since this doesn't change often
            this.cache.set(cacheKey, {
                data: result.data,
                timestamp: Date.now()
            });
            
            return result.data;
        } catch (error) {
            console.error('Error fetching date range:', error);
            throw error;
        }
    }

    clearCache() {
        this.cache.clear();
    }

    getCacheSize() {
        return this.cache.size;
    }

    // Utility method for handling network connectivity
    isOnline() {
        return navigator.onLine;
    }

    // Method to preload data for better UX
    async preloadData(dates, station = 260) {
        const promises = dates.map(date => 
            this.fetchWeatherData(date, station).catch(err => {
                console.warn(`Failed to preload data for ${date}:`, err);
                return null;
            })
        );
        
        return Promise.allSettled(promises);
    }
}
