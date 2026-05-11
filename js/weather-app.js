// js/weather-app.js
class WeatherApp {
    constructor() {
        this.api = new WeatherAPI(API_BASE_URL);
        this.chartManager = null;
        this.currentDate = new Date(DEFAULT_DATE);
        this.comparisonMode = false;
        this.isLoading = false;
        
        // Wait for DOM and scripts to be ready
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', () => this.init());
        } else {
            this.init();
        }
    }

    async init() {
        try {
            this.setupEventListeners();
            
            // Wait for Chart.js to be available
            await this.waitForChart();
            this.chartManager = new ChartManager();
            
            await this.hideLoadingScreen();
            await this.loadInitialData();
        } catch (error) {
            console.error('Failed to initialize app:', error);
            this.showMessage('Er is een fout opgetreden bij het opstarten van de applicatie.', 'error');
        }
    }

    async waitForChart() {
        return new Promise((resolve) => {
            const checkChart = () => {
                if (typeof Chart !== 'undefined') {
                    resolve();
                } else {
                    setTimeout(checkChart, 100);
                }
            };
            checkChart();
        });
    }

    async hideLoadingScreen() {
        return new Promise(resolve => {
            setTimeout(() => {
                const loadingScreen = document.getElementById('loadingScreen');
                const mainContent = document.getElementById('mainContent');
                
                if (loadingScreen) loadingScreen.style.display = 'none';
                if (mainContent) mainContent.style.display = 'block';
                
                document.body.style.overflow = 'auto';
                resolve();
            }, 1000);
        });
    }

    setupEventListeners() {
        // Date navigation
        const primaryDate = document.getElementById('primaryDate');
        if (primaryDate) {
            primaryDate.addEventListener('change', (e) => {
                this.currentDate = new Date(e.target.value);
                this.loadWeatherData();
            });
        }

        const todayBtn = document.getElementById('todayBtn');
        if (todayBtn) {
            todayBtn.addEventListener('click', () => {
                const today = new Date();
                const todayStr = today.toISOString().split('T')[0];
                if (todayStr <= LAST_DATE) {
                    this.currentDate = today;
                    document.getElementById('primaryDate').value = todayStr;
                    this.loadWeatherData();
                } else {
                    this.showMessage('Vandaag is nog geen data beschikbaar. Laatste beschikbare datum wordt getoond.', 'warning');
                    this.currentDate = new Date(LAST_DATE);
                    document.getElementById('primaryDate').value = LAST_DATE;
                    this.loadWeatherData();
                }
            });
        }

        const prevDay = document.getElementById('prevDay');
        if (prevDay) {
            prevDay.addEventListener('click', () => {
                this.navigateDay(-1);
            });
        }

        const nextDay = document.getElementById('nextDay');
        if (nextDay) {
            nextDay.addEventListener('click', () => {
                this.navigateDay(1);
            });
        }

        // Comparison mode
        const comparisonMode = document.getElementById('comparisonMode');
        if (comparisonMode) {
            comparisonMode.addEventListener('change', (e) => {
                this.comparisonMode = e.target.checked;
                this.toggleComparisonMode();
            });
        }

        const comparisonDate = document.getElementById('comparisonDate');
        if (comparisonDate) {
            comparisonDate.addEventListener('change', () => {
                if (this.comparisonMode) {
                    this.loadComparisonData();
                }
            });
        }

        // Quick comparison buttons
        const quickCompare = document.getElementById('quickCompare');
        if (quickCompare) {
            quickCompare.addEventListener('click', () => {
                const yesterday = new Date(this.currentDate);
                yesterday.setDate(yesterday.getDate() - 1);
                this.setComparisonDate(yesterday);
            });
        }

        const weekCompare = document.getElementById('weekCompare');
        if (weekCompare) {
            weekCompare.addEventListener('click', () => {
                const lastWeek = new Date(this.currentDate);
                lastWeek.setDate(lastWeek.getDate() - 7);
                this.setComparisonDate(lastWeek);
            });
        }

        const yearCompare = document.getElementById('yearCompare');
        if (yearCompare) {
            yearCompare.addEventListener('click', () => {
                const lastYear = new Date(this.currentDate);
                lastYear.setFullYear(lastYear.getFullYear() - 1);
                this.setComparisonDate(lastYear);
            });
        }

        // Chart controls
        document.querySelectorAll('input[name="chartType"]').forEach(radio => {
            radio.addEventListener('change', (e) => {
                if (this.chartManager) {
                    this.chartManager.updateChart(e.target.id.replace('Chart', ''));
                }
            });
        });

        const chart7Days = document.getElementById('chart7Days');
        if (chart7Days) {
            chart7Days.addEventListener('click', () => {
                this.loadChartData(7);
            });
        }

        const chart30Days = document.getElementById('chart30Days');
        if (chart30Days) {
            chart30Days.addEventListener('click', () => {
                this.loadChartData(30);
            });
        }

        const chartYear = document.getElementById('chartYear');
        if (chartYear) {
            chartYear.addEventListener('click', () => {
                const startOfYear = new Date(this.currentDate.getFullYear(), 0, 1);
                const daysSinceStart = Math.floor((this.currentDate - startOfYear) / (1000 * 60 * 60 * 24));
                this.loadChartData(Math.min(daysSinceStart + 1, 365));
            });
        }

        // Quick actions
        document.querySelectorAll('.quick-action').forEach(btn => {
            btn.addEventListener('click', (e) => {
                this.handleQuickAction(e.target.closest('.quick-action').dataset.action);
            });
        });

        // Modals
        const aboutBtn = document.getElementById('aboutBtn');
        if (aboutBtn) {
            aboutBtn.addEventListener('click', () => {
                const modal = document.getElementById('aboutModal');
                if (modal && typeof bootstrap !== 'undefined') {
                    new bootstrap.Modal(modal).show();
                }
            });
        }

        const helpBtn = document.getElementById('helpBtn');
        if (helpBtn) {
            helpBtn.addEventListener('click', () => {
                const modal = document.getElementById('helpModal');
                if (modal && typeof bootstrap !== 'undefined') {
                    new bootstrap.Modal(modal).show();
                }
            });
        }

        // Keyboard shortcuts
        document.addEventListener('keydown', (e) => {
            if (e.ctrlKey || e.metaKey) {
                switch(e.key) {
                    case 'ArrowLeft':
                        e.preventDefault();
                        this.navigateDay(-1);
                        break;
                    case 'ArrowRight':
                        e.preventDefault();
                        this.navigateDay(1);
                        break;
                    case 'c':
                    case 'C':
                        e.preventDefault();
                        const comparisonModeEl = document.getElementById('comparisonMode');
                        if (comparisonModeEl) comparisonModeEl.click();
                        break;
                    case 't':
                    case 'T':
                        e.preventDefault();
                        const todayBtnEl = document.getElementById('todayBtn');
                        if (todayBtnEl) todayBtnEl.click();
                        break;
                }
            }
        });

        // Touch/swipe support
        this.setupTouchSupport();

        // Window resize handler for chart
        window.addEventListener('resize', () => {
            if (this.chartManager) {
                this.chartManager.resize();
            }
        });

        // Online/offline detection
        window.addEventListener('online', () => {
            this.showMessage('Internetverbinding hersteld.', 'success');
        });

        window.addEventListener('offline', () => {
            this.showMessage('Geen internetverbinding. Sommige functies zijn mogelijk beperkt.', 'warning');
        });
    }

    setupTouchSupport() {
        let touchStartX = 0;
        let touchEndX = 0;

        document.addEventListener('touchstart', (e) => {
            touchStartX = e.changedTouches[0].screenX;
        }, { passive: true });

        document.addEventListener('touchend', (e) => {
            touchEndX = e.changedTouches[0].screenX;
            this.handleSwipe();
        }, { passive: true });

        const handleSwipe = () => {
            const swipeThreshold = 100;
            const swipeDistance = touchEndX - touchStartX;
            
            if (Math.abs(swipeDistance) > swipeThreshold) {
                if (swipeDistance > 0) {
                    this.navigateDay(-1); // Swipe right - previous day
                } else {
                    this.navigateDay(1);  // Swipe left - next day
                }
            }
        };

        this.handleSwipe = handleSwipe;
    }

    async loadInitialData() {
        try {
            await this.loadWeatherData();
            await this.loadChartData(7);
            await this.loadMonthlyStats();
        } catch (error) {
            this.showMessage('Er is een fout opgetreden bij het laden van de initiële data.', 'error');
        }
    }

    async loadWeatherData() {
        if (this.isLoading) return;
        
        this.isLoading = true;
        this.showLoading();
        
        try {
            const dateStr = this.formatDateForAPI(this.currentDate);
            const data = await this.api.fetchWeatherData(dateStr);
            
            this.updatePrimaryWeatherDisplay(data);
            this.updateDateDisplay();
            
            // Load chart data for the new date
            await this.loadChartData(7);
            
            // Load monthly stats for the new month
            await this.loadMonthlyStats();
            
        } catch (error) {
            console.error('Error loading weather data:', error);
            this.showMessage('Geen weergegevens beschikbaar voor de geselecteerde datum.', 'error');
        } finally {
            this.hideLoading();
            this.isLoading = false;
        }
    }

    async loadComparisonData() {
        if (!this.comparisonMode) return;

        try {
            const comparisonDateStr = document.getElementById('comparisonDate').value;
            if (!comparisonDateStr) return;

            const data = await this.api.fetchWeatherData(comparisonDateStr);
            this.updateComparisonWeatherDisplay(data);
            
        } catch (error) {
            console.error('Error loading comparison data:', error);
            this.showMessage('Geen vergelijkingsgegevens beschikbaar voor de geselecteerde datum.', 'error');
        }
    }

    async loadChartData(days) {
        try {
            const endDate = new Date(this.currentDate);
            const startDate = new Date(endDate);
            startDate.setDate(startDate.getDate() - (days - 1));
            
            const startDateStr = this.formatDateForAPI(startDate);
            const endDateStr = this.formatDateForAPI(endDate);
            
            const data = await this.api.fetchPeriodData(startDateStr, endDateStr);
            
            if (this.chartManager) {
                this.chartManager.loadData(data);
            }
            
            // Update active chart button
            document.querySelectorAll('.chart-controls .btn').forEach(btn => btn.classList.remove('active'));
            if (days === 7) {
                const btn = document.getElementById('chart7Days');
                if (btn) btn.classList.add('active');
            } else if (days === 30) {
                const btn = document.getElementById('chart30Days');
                if (btn) btn.classList.add('active');
            } else {
                const btn = document.getElementById('chartYear');
                if (btn) btn.classList.add('active');
            }
            
        } catch (error) {
            console.error('Error loading chart data:', error);
            this.showMessage('Fout bij laden van grafiekgegevens.', 'error');
        }
    }

    async loadMonthlyStats() {
        try {
            const year = this.currentDate.getFullYear();
            const month = this.currentDate.getMonth() + 1;
            
            const stats = await this.api.fetchMonthlyStats(year, month);
            this.updateMonthlyStatsDisplay(stats, year, month);
            
        } catch (error) {
            console.error('Error loading monthly stats:', error);
            // Don't show error message for monthly stats as it's supplementary
        }
    }

    navigateDay(direction) {
        const newDate = new Date(this.currentDate);
        newDate.setDate(newDate.getDate() + direction);
        
        const newDateStr = this.formatDateForAPI(newDate);
        
        if (newDateStr >= FIRST_DATE && newDateStr <= LAST_DATE) {
            this.currentDate = newDate;
            const primaryDateEl = document.getElementById('primaryDate');
            if (primaryDateEl) {
                primaryDateEl.value = newDateStr;
            }
            this.loadWeatherData();
        } else {
            const message = direction < 0 ? 'Geen eerdere data beschikbaar.' : 'Geen recentere data beschikbaar.';
            this.showMessage(message, 'warning');
        }
    }

    toggleComparisonMode() {
        const comparisonDatePicker = document.getElementById('comparisonDatePicker');
        const comparisonCard = document.getElementById('comparisonCard');
        
        if (this.comparisonMode) {
            if (comparisonDatePicker) comparisonDatePicker.style.display = 'block';
            if (comparisonCard) comparisonCard.style.display = 'block';
            
            // Set default comparison date (previous day)
            const yesterday = new Date(this.currentDate);
            yesterday.setDate(yesterday.getDate() - 1);
            this.setComparisonDate(yesterday);
        } else {
            if (comparisonDatePicker) comparisonDatePicker.style.display = 'none';
            if (comparisonCard) comparisonCard.style.display = 'none';
        }
    }

    setComparisonDate(date) {
        const dateStr = this.formatDateForAPI(date);
        if (dateStr >= FIRST_DATE && dateStr <= LAST_DATE) {
            const comparisonDateEl = document.getElementById('comparisonDate');
            if (comparisonDateEl) {
                comparisonDateEl.value = dateStr;
            }
            this.loadComparisonData();
        } else {
            this.showMessage('Vergelijkingsdatum valt buiten het beschikbare bereik.', 'warning');
        }
    }

    updatePrimaryWeatherDisplay(data) {
        // Update title
        const titleEl = document.getElementById('primaryDayTitle');
        if (titleEl) {
            titleEl.innerHTML = `<i class="bi bi-calendar-check me-2"></i>${data.date_formatted}`;
        }

        // Temperature
        this.updateElement('primaryTemp', this.formatTemperature(data.temperature.avg));
        this.updateElement('primaryTempMin', this.formatTemperature(data.temperature.min));
        this.updateElement('primaryTempMax', this.formatTemperature(data.temperature.max));
        this.updateElement('primaryTempMinTime', data.temperature.min_hour ? `(${this.formatHour(data.temperature.min_hour)})` : '');
        this.updateElement('primaryTempMaxTime', data.temperature.max_hour ? `(${this.formatHour(data.temperature.max_hour)})` : '');

        // Wind
        const windDirection = data.wind.direction_text || '--';
        const windRotation = data.wind.direction_degrees || 0;
        const windDirectionEl = document.getElementById('primaryWindDirection');
        if (windDirectionEl) {
            windDirectionEl.innerHTML = 
                `${windDirection} <div class="wind-indicator" style="transform: rotate(${windRotation}deg);"><i class="bi bi-arrow-up wind-arrow"></i></div>`;
        }
        
        this.updateElement('primaryWind', this.formatWindSpeed(data.wind.speed_avg));
        this.updateElement('primaryWindScale', data.wind.beaufort ? `(${data.wind.beaufort.scale} Bft - ${data.wind.beaufort.description})` : '');
        this.updateElement('primaryWindGust', this.formatWindSpeed(data.wind.gust_max));
        this.updateElement('primaryWindMax', this.formatWindSpeed(data.wind.speed_max));
        this.updateElement('primaryWindGustTime', data.wind.gust_max_hour ? `(${this.formatHour(data.wind.gust_max_hour)})` : '');
        this.updateElement('primaryWindMaxTime', data.wind.speed_max_hour ? `(${this.formatHour(data.wind.speed_max_hour)})` : '');

        // Precipitation & Sun
        this.updateElement('primaryRain', this.formatPrecipitation(data.precipitation.amount));
        this.updateElement('primaryRainDuration', this.formatDuration(data.precipitation.duration));
        this.updateElement('primarySun', this.formatDuration(data.sunshine.duration));
        this.updateElement('primarySunPercentage', data.sunshine.percentage ? `${data.sunshine.percentage}%` : '--');

        // Pressure & Humidity
        this.updateElement('primaryPressure', this.formatPressure(data.pressure.avg));
        this.updateElement('primaryPressureRange', 
            `${this.formatPressure(data.pressure.min)}/${this.formatPressure(data.pressure.max)}`);
        this.updateElement('primaryHumidity', data.humidity.avg ? `${data.humidity.avg}%` : '--');
        this.updateElement('primaryHumidityRange', 
            `${data.humidity.min || '--'}%/${data.humidity.max || '--'}%`);
    }

    updateComparisonWeatherDisplay(data) {
        // Get primary data for comparison
        const primaryTemp = this.parseValue(this.getElementText('primaryTemp'));
        const primaryWind = this.parseValue(this.getElementText('primaryWind'));
        const primaryRain = this.parseValue(this.getElementText('primaryRain'));
        const primarySun = this.parseValue(this.getElementText('primarySun'));

        // Update comparison title
        const titleEl = document.getElementById('comparisonDayTitle');
        if (titleEl) {
            titleEl.innerHTML = `<i class="bi bi-calendar-x me-2"></i>${data.date_formatted}`;
        }

        // Create comparison content
        const comparisonContent = document.getElementById('comparisonContent');
        if (comparisonContent) {
            comparisonContent.innerHTML = this.generateComparisonHTML(data, {
                temp: primaryTemp,
                wind: primaryWind,
                rain: primaryRain,
                sun: primarySun
            });
        }
    }

    generateComparisonHTML(data, primaryData) {
        const tempDiff = this.calculateDifference(data.temperature.avg, primaryData.temp);
        const windDiff = this.calculateDifference(data.wind.speed_avg, primaryData.wind);
        const rainDiff = this.calculateDifference(data.precipitation.amount, primaryData.rain);
        const sunDiff = this.calculateDifference(data.sunshine.duration, primaryData.sun);

        return `
            <h5 class="text-danger mb-3">
                <i class="bi bi-thermometer-half me-2"></i>Temperatuur
            </h5>
            <div class="row g-3 mb-4">
                <div class="col-md-4">
                    <div class="weather-metric comparison-metric">
                        <div class="d-flex justify-content-between align-items-center">
                            <span>Gemiddeld</span>
                            <span class="metric-value comparison-value">${this.formatTemperature(data.temperature.avg)}</span>
                        </div>
                        <small class="text-muted">Verschil: ${tempDiff}</small>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="weather-metric comparison-metric">
                        <div class="d-flex justify-content-between align-items-center">
                            <span>Minimum</span>
                            <span class="metric-value comparison-value">${this.formatTemperature(data.temperature.min)}</span>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="weather-metric comparison-metric">
                        <div class="d-flex justify-content-between align-items-center">
                            <span>Maximum</span>
                            <span class="metric-value comparison-value">${this.formatTemperature(data.temperature.max)}</span>
                        </div>
                    </div>
                </div>
            </div>

            <h5 class="text-danger mb-3">
                <i class="bi bi-wind me-2"></i>Wind
            </h5>
            <div class="row g-3 mb-4">
                <div class="col-md-6">
                    <div class="weather-metric comparison-metric">
                        <div class="d-flex justify-content-between align-items-center">
                            <span>Richting</span>
                            <span class="metric-value comparison-value">
                                ${data.wind.direction_text || '--'} 
                                <div class="wind-indicator comparison-wind" style="transform: rotate(${data.wind.direction_degrees || 0}deg);">
                                    <i class="bi bi-arrow-up wind-arrow"></i>
                                </div>
                            </span>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="weather-metric comparison-metric">
                        <div class="d-flex justify-content-between align-items-center">
                            <span>Snelheid</span>
                            <span class="metric-value comparison-value">${this.formatWindSpeed(data.wind.speed_avg)}</span>
                        </div>
                        <small class="text-muted">Verschil: ${windDiff}</small>
                    </div>
                </div>
            </div>

            <div class="row g-3">
                <div class="col-md-6">
                    <h6 class="text-danger mb-2">
                        <i class="bi bi-droplet me-2"></i>Neerslag
                    </h6>
                    <div class="weather-metric comparison-metric">
                        <div class="d-flex justify-content-between align-items-center">
                            <span>Hoeveelheid</span>
                            <span class="metric-value comparison-value">${this.formatPrecipitation(data.precipitation.amount)}</span>
                        </div>
                        <small class="text-muted">Verschil: ${rainDiff}</small>
                    </div>
                </div>
                <div class="col-md-6">
                    <h6 class="text-white mb-2"> <!-- Wit ipv rood -->
                        <i class="bi bi-brightness-high me-2"></i>Zon
                    </h6>
                    <div class="weather-metric comparison-metric">
                        <div class="d-flex justify-content-between align-items-center">
                            <span>Duur</span>
                            <span class="metric-value comparison-value">${this.formatDuration(data.sunshine.duration)}</span>
                        </div>
                        <small class="text-muted">Verschil: ${sunDiff}</small>
                    </div>
                </div>
            </div>
        `;
    }

    updateMonthlyStatsDisplay(stats, year, month) {
        const monthNames = [
            'januari', 'februari', 'maart', 'april', 'mei', 'juni',
            'juli', 'augustus', 'september', 'oktober', 'november', 'december'
        ];
        
        const titleEl = document.getElementById('monthlyStatsTitle');
        if (titleEl) {
            titleEl.innerHTML = 
                `<i class="bi bi-calendar-month me-2"></i>Statistieken ${monthNames[month - 1]} ${year}`;
        }

        const content = document.getElementById('monthlyStatsContent');
        if (content) {
            content.innerHTML = `
                <div class="col-md-3 text-center">
                    <div class="weather-metric">
                        <i class="bi bi-thermometer text-primary fs-3"></i>
                        <div class="metric-value">${this.formatTemperature(stats.temperature.avg)}</div>
                        <small>Gem. temperatuur</small>
                    </div>
                </div>
                <div class="col-md-3 text-center">
                    <div class="weather-metric">
                        <i class="bi bi-droplet-fill text-info fs-3"></i>
                        <div class="metric-value">${this.formatPrecipitation(stats.precipitation.total)}</div>
                        <small>Totale neerslag</small>
                    </div>
                </div>
                <div class="col-md-3 text-center">
                    <div class="weather-metric">
                        <i class="bi bi-sun text-warning fs-3"></i>
                        <div class="metric-value">${this.formatDuration(stats.sunshine.total)}</div>
                        <small>Totale zonneschijn</small>
                    </div>
                </div>
                <div class="col-md-3 text-center">
                    <div class="weather-metric">
                        <i class="bi bi-calendar-date text-success fs-3"></i>
                        <div class="metric-value">${stats.special_days.summer_days}</div>
                        <small>Zomerse dagen (>20°C)</small>
                    </div>
                </div>
            `;
        }
    }

    handleQuickAction(action) {
        const currentDate = new Date(this.currentDate);
        let targetDate;

        switch(action) {
            case 'yesterday':
                targetDate = new Date(currentDate);
                targetDate.setDate(targetDate.getDate() - 1);
                break;
            case 'lastWeek':
                targetDate = new Date(currentDate);
                targetDate.setDate(targetDate.getDate() - 7);
                break;
            case 'lastMonth':
                targetDate = new Date(currentDate);
                targetDate.setMonth(targetDate.getMonth() - 1);
                break;
            case 'random':
                const firstDate = new Date(FIRST_DATE);
                const lastDate = new Date(LAST_DATE);
                const randomTime = firstDate.getTime() + Math.random() * (lastDate.getTime() - firstDate.getTime());
                targetDate = new Date(randomTime);
                break;
        }

        if (targetDate) {
            const targetDateStr = this.formatDateForAPI(targetDate);
            if (targetDateStr >= FIRST_DATE && targetDateStr <= LAST_DATE) {
                this.currentDate = targetDate;
                const primaryDateEl = document.getElementById('primaryDate');
                if (primaryDateEl) {
                    primaryDateEl.value = targetDateStr;
                }
                this.loadWeatherData();
            } else {
                this.showMessage('Geselecteerde datum valt buiten het beschikbare bereik.', 'warning');
            }
        }
    }

    // Utility methods
    updateElement(id, value) {
        const element = document.getElementById(id);
        if (element) {
            element.textContent = value;
        }
    }

    getElementText(id) {
        const element = document.getElementById(id);
        return element ? element.textContent : '';
    }

    updateDateDisplay() {
        const primaryDateEl = document.getElementById('primaryDate');
        if (primaryDateEl) {
            primaryDateEl.value = this.formatDateForAPI(this.currentDate);
        }
    }

    formatDateForAPI(date) {
        return date.toISOString().split('T')[0];
    }

    formatTemperature(value) {
        return value !== null && value !== undefined ? `${value}°C` : '--°C';
    }

    formatWindSpeed(value) {
        return value !== null && value !== undefined ? `${value} km/h` : '-- km/h';
    }

    formatPrecipitation(value) {
        return value !== null && value !== undefined ? `${value} mm` : '-- mm';
    }

    formatDuration(value) {
        return value !== null && value !== undefined ? `${value} uur` : '-- uur';
    }

    formatPressure(value) {
        return value !== null && value !== undefined ? `${value} hPa` : '-- hPa';
    }

    formatHour(hour) {
        if (!hour) return '';
        const h = Math.floor(hour / 100);
        const m = hour % 100;
        return `${h.toString().padStart(2, '0')}:${m.toString().padStart(2, '0')}`;
    }

    parseValue(text) {
        const match = text.match(/^([\d.]+)/);
        return match ? parseFloat(match[1]) : 0;
    }

    calculateDifference(newValue, oldValue) {
        if (newValue === null || newValue === undefined || oldValue === null || oldValue === undefined) {
            return '--';
        }
        const diff = newValue - oldValue;
        const sign = diff >= 0 ? '+' : '';
        return `${sign}${diff.toFixed(1)}`;
    }

    showLoading() {
        const spinner = document.getElementById('loadingSpinner');
        if (spinner) {
            spinner.style.display = 'block';
        }
    }

    hideLoading() {
        const spinner = document.getElementById('loadingSpinner');
        if (spinner) {
            spinner.style.display = 'none';
        }
    }

    showMessage(message, type) {
        const alertClass = type === 'error' ? 'alert-danger' : 
                          type === 'warning' ? 'alert-warning' : 
                          type === 'success' ? 'alert-success' :
                          'alert-info';
        
        const icon = type === 'error' ? 'exclamation-triangle' :
                    type === 'warning' ? 'exclamation-triangle' :
                    type === 'success' ? 'check-circle' :
                    'info-circle';
        
        const alertHTML = `
            <div class="alert ${alertClass} alert-dismissible fade show" role="alert">
                <i class="bi bi-${icon} me-2"></i>
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        `;
        
        const container = document.getElementById('statusMessages');
        if (container) {
            container.innerHTML = alertHTML;
            
            // Auto-hide after 5 seconds
            setTimeout(() => {
                const alert = container.querySelector('.alert');
                if (alert && typeof bootstrap !== 'undefined') {
                    const bsAlert = bootstrap.Alert.getOrCreateInstance(alert);
                    bsAlert.close();
                }
            }, 5000);
        }
    }
}

// Initialize the application when DOM is loaded
document.addEventListener('DOMContentLoaded', function() {
    // Initialize app
    window.weatherApp = new WeatherApp();
    console.log('Weather App initialized');
});