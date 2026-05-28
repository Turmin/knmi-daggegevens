// js/weather-app.js
class WeatherApp {
    constructor() {
        this.api = new WeatherAPI(API_BASE_URL);
        this.chartManager = null;
        this.currentDate = this.parseAPIDate(DEFAULT_DATE);
        this.firstDate = FIRST_DATE;
        this.lastDate = LAST_DATE;
        this.language = this.getSavedLanguage();
        this.theme = this.getSavedTheme();
        this.comparisonMode = false;
        this.isLoading = false;
        this.chartDays = 7;
        this.chartRangeMode = '7';
        this.chartRangeStart = null;
        this.chartRangeEnd = null;
        this.currentWeatherData = null;
        this.currentComparisonData = null;
        this.calendarDayStats = null;
        this.monthlyStats = null;
        this.monthlyStatsYear = null;
        this.monthlyStatsMonth = null;
        this.lastRefreshTime = null;
        this.shouldUpdateDateUrl = typeof INITIAL_PAGE_IS_DATE_PAGE !== 'undefined'
            ? INITIAL_PAGE_IS_DATE_PAGE
            : true;
        
        // Wait for DOM and scripts to be ready
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', () => this.init());
        } else {
            this.init();
        }
    }

    async init() {
        try {
            this.applyTheme(this.theme);
            this.applyTranslations();
            this.setupEventListeners();
            await this.hideLoadingScreen();

            const hasInitialWeatherData = this.renderInitialWeatherData();
            
            // Wait for Chart.js to be available
            await this.waitForChart();
            this.chartManager = new ChartManager(this.language);
            this.chartManager.updateTheme(this.theme === 'dark');
            
            if (hasInitialWeatherData) {
                await this.loadSupportingData();
            } else {
                await this.loadInitialData();
            }
        } catch (error) {
            console.error('Failed to initialize app:', error);
            this.showMessage(this.t('startupError'), 'error');
        }
    }

    getSavedLanguage() {
        const savedLanguage = localStorage.getItem('knmi-language');
        const documentLanguage = document.documentElement.lang;
        if (['nl', 'en'].includes(savedLanguage)) return savedLanguage;
        return ['nl', 'en'].includes(documentLanguage) ? documentLanguage : 'nl';
    }

    getSavedTheme() {
        const savedTheme = localStorage.getItem('knmi-theme');
        if (['light', 'dark'].includes(savedTheme)) return savedTheme;

        return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    }

    t(key, params = {}) {
        const dictionary = window.AppI18n?.[this.language] || window.AppI18n?.nl || {};
        let value = dictionary[key] || key;

        Object.entries(params).forEach(([param, replacement]) => {
            value = value.replace(`{${param}}`, replacement);
        });

        return value;
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
        const loadingScreen = document.getElementById('loadingScreen');
        const mainContent = document.getElementById('mainContent');

        if (loadingScreen) loadingScreen.hidden = true;
        if (mainContent) mainContent.style.display = '';

        document.body.style.overflow = 'auto';
    }

    renderInitialWeatherData() {
        const data = typeof INITIAL_WEATHER_DATA !== 'undefined' ? INITIAL_WEATHER_DATA : null;
        const expectedDate = this.formatDateForAPI(this.currentDate);

        if (!data || data.date !== expectedDate) {
            return false;
        }

        this.currentWeatherData = data;
        this.updatePrimaryWeatherDisplay(data, {
            updateUrl: this.shouldUpdateDateUrl
        });
        this.updateDateDisplay();

        return true;
    }

    async loadSupportingData() {
        this.showLoading();

        try {
            await Promise.all([
                this.loadSelectedChartRange(),
                this.loadMonthlyStats(),
                this.loadCalendarDayStats()
            ]);
        } finally {
            this.hideLoading();
        }
    }

    setupEventListeners() {
        document.querySelectorAll('[data-language]').forEach(button => {
            button.addEventListener('click', () => {
                this.setLanguage(button.dataset.language);
            });
        });

        const themeToggle = document.getElementById('themeToggle');
        if (themeToggle) {
            themeToggle.addEventListener('click', () => {
                this.setTheme(this.theme === 'dark' ? 'light' : 'dark');
            });
        }

        // Date navigation
        const primaryDate = document.getElementById('primaryDate');
        if (primaryDate) {
            primaryDate.addEventListener('change', (e) => {
                this.currentDate = this.parseAPIDate(e.target.value);
                this.loadWeatherData();
            });
        }

        const refreshData = document.getElementById('refreshData');
        if (refreshData) {
            refreshData.addEventListener('click', () => {
                this.refreshData(true);
            });
        }

        const latestDay = document.getElementById('latestDay');
        if (latestDay) {
            latestDay.addEventListener('click', () => {
                this.goToLatestDay();
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
                this.loadChartYear();
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
                        this.goToLatestDay();
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
            this.showMessage(this.t('connectionRestored'), 'success');
        });

        window.addEventListener('offline', () => {
            this.showMessage(this.t('offlineLimited'), 'warning');
        });
    }

    setLanguage(language) {
        if (!['nl', 'en'].includes(language)) return;

        this.language = language;
        localStorage.setItem('knmi-language', language);
        document.documentElement.lang = language;
        this.applyTranslations();

        if (this.chartManager) {
            this.chartManager.setLanguage(language);
            this.refreshChartRangeLabel();
        }

        if (this.currentWeatherData) {
            this.updatePrimaryWeatherDisplay(this.currentWeatherData, {
                updateUrl: this.shouldUpdateDateUrl
            });
        }

        if (this.currentComparisonData) {
            this.updateComparisonWeatherDisplay(this.currentComparisonData);
        }

        if (this.monthlyStats) {
            this.updateMonthlyStatsDisplay(this.monthlyStats, this.monthlyStatsYear, this.monthlyStatsMonth);
        }

        if (this.calendarDayStats) {
            this.updateCalendarDayStatsDisplay(this.calendarDayStats);
        }
    }

    setTheme(theme) {
        if (!['light', 'dark'].includes(theme)) return;

        this.theme = theme;
        localStorage.setItem('knmi-theme', theme);
        this.applyTheme(theme);
    }

    applyTheme(theme) {
        document.documentElement.dataset.theme = theme;
        this.updatePreferenceControls();

        if (this.chartManager) {
            this.chartManager.updateTheme(theme === 'dark');
        }
    }

    applyTranslations() {
        document.querySelectorAll('[data-i18n]').forEach(element => {
            element.textContent = this.t(element.dataset.i18n);
        });

        document.querySelectorAll('[data-i18n-title]').forEach(element => {
            element.title = this.t(element.dataset.i18nTitle);
        });

        document.querySelectorAll('[data-i18n-aria-label]').forEach(element => {
            element.setAttribute('aria-label', this.t(element.dataset.i18nAriaLabel));
        });

        this.updatePreferenceControls();
        this.updateDateRangeText();
        this.updateFooterTimes();
        this.updateDocumentLinks();
        this.updatePageTitle();
        this.refreshChartRangeLabel();
    }

    updatePreferenceControls() {
        const themeToggle = document.getElementById('themeToggle');

        if (themeToggle) {
            const nextTheme = this.theme === 'dark' ? 'light' : 'dark';
            const nextThemeKey = nextTheme === 'dark' ? 'themeDark' : 'themeLight';
            const icon = themeToggle.querySelector('i');

            themeToggle.title = this.t(nextThemeKey);
            themeToggle.setAttribute('aria-label', this.t(nextThemeKey));

            if (icon) {
                icon.className = nextTheme === 'dark'
                    ? 'bi bi-moon-stars'
                    : 'bi bi-sun';
            }
        }

        document.querySelectorAll('[data-language]').forEach(button => {
            const isActive = button.dataset.language === this.language;
            button.classList.toggle('active', isActive);
            button.classList.toggle('btn-light', isActive);
            button.classList.toggle('btn-outline-light', !isActive);
            button.setAttribute('aria-pressed', String(isActive));
        });
    }

    updateDocumentLinks() {
        document.querySelectorAll('[data-doc-nl][data-doc-en]').forEach(link => {
            link.textContent = this.language === 'en' ? link.dataset.docEn : link.dataset.docNl;
        });
    }

    updatePageTitle(dateString = null) {
        const targetDate = dateString || this.currentWeatherData?.date || this.formatDateForAPI(this.currentDate);
        const title = this.t('pageTitle', {
            date: this.formatDisplayDate(targetDate)
        });

        document.title = title;

        const ogTitle = document.querySelector('meta[property="og:title"]');
        if (ogTitle) {
            ogTitle.content = title;
        }
    }

    updateBrowserDateUrl(dateString) {
        if (!dateString || !window.history?.replaceState) return;

        const basePath = typeof APP_BASE_PATH === 'string' ? APP_BASE_PATH.replace(/\/$/, '') : '';
        const datePath = `${basePath}/${dateString}`;
        const canonicalBase = typeof SITE_BASE_URL === 'string'
            ? SITE_BASE_URL.replace(/\/$/, '')
            : window.location.origin;
        const absoluteDateUrl = `${canonicalBase}/${encodeURIComponent(dateString)}`;
        const canonical = document.querySelector('link[rel="canonical"]');
        const ogUrl = document.querySelector('meta[property="og:url"]');

        if (canonical) {
            canonical.href = absoluteDateUrl;
        }

        if (ogUrl) {
            ogUrl.content = absoluteDateUrl;
        }

        if (window.location.pathname === datePath) return;

        window.history.replaceState({}, '', datePath);
    }

    updateDateRangeText() {
        const first = this.formatDisplayDate(this.firstDate);
        const last = this.formatDisplayDate(this.lastDate);
        const text = this.t('availableData', { first, last });

        ['availableDataText', 'aboutAvailableDataText'].forEach(id => {
            const element = document.getElementById(id);
            if (element) {
                element.textContent = text;
            }
        });
    }

    updateFooterTimes() {
        const lastUpdateText = document.getElementById('lastUpdateText');
        if (lastUpdateText) {
            lastUpdateText.textContent = this.t('lastUpdate', {
                time: this.formatDisplayDateTime(lastUpdateText.dataset.updateTime)
            });
        }

        const lastRefreshText = document.getElementById('lastRefreshText');
        const lastRefreshSeparator = document.getElementById('lastRefreshSeparator');

        if (lastRefreshText && lastRefreshSeparator) {
            if (this.lastRefreshTime) {
                lastRefreshText.textContent = this.t('lastRefreshed', {
                    time: this.formatDisplayDateTime(this.lastRefreshTime.toISOString())
                });
                lastRefreshSeparator.style.display = '';
            } else {
                lastRefreshText.textContent = '';
                lastRefreshSeparator.style.display = 'none';
            }
        }
    }

    formatDisplayDate(dateString) {
        const locale = this.language === 'en' ? 'en-GB' : 'nl-NL';
        const date = new Date(`${dateString}T00:00:00`);

        return new Intl.DateTimeFormat(locale, {
            day: 'numeric',
            month: 'long',
            year: 'numeric'
        }).format(date);
    }

    formatDisplayDateTime(dateString) {
        const locale = this.language === 'en' ? 'en-GB' : 'nl-NL';
        const date = new Date(dateString);

        return new Intl.DateTimeFormat(locale, {
            day: '2-digit',
            month: '2-digit',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        }).format(date);
    }

    formatCalendarDay(month, day) {
        const locale = this.language === 'en' ? 'en-GB' : 'nl-NL';
        const date = new Date(2000, month - 1, day);

        return new Intl.DateTimeFormat(locale, {
            day: 'numeric',
            month: 'long'
        }).format(date);
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
            await this.loadWeatherData({
                updateUrl: this.shouldUpdateDateUrl
            });
        } catch (error) {
            this.showMessage(this.t('initialLoadError'), 'error');
        }
    }

    async loadWeatherData(options = {}) {
        if (this.isLoading) return;
        
        const { updateUrl = true } = options;
        if (updateUrl) {
            this.shouldUpdateDateUrl = true;
        }
        this.isLoading = true;
        this.showLoading();
        
        try {
            const dateStr = this.formatDateForAPI(this.currentDate);
            const data = await this.api.fetchWeatherData(dateStr);
            
            this.currentWeatherData = data;
            this.updatePrimaryWeatherDisplay(data, { updateUrl });
            this.updateDateDisplay();
            
            await Promise.all([
                this.loadSelectedChartRange(),
                this.loadMonthlyStats(),
                this.loadCalendarDayStats()
            ]);
            
        } catch (error) {
            console.error('Error loading weather data:', error);
            this.showMessage(this.t('noWeatherForDate'), 'error');
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
            this.currentComparisonData = data;
            this.updateComparisonWeatherDisplay(data);
            
        } catch (error) {
            console.error('Error loading comparison data:', error);
            this.showMessage(this.t('noComparisonForDate'), 'error');
        }
    }

    async loadSelectedChartRange() {
        if (this.chartRangeMode === 'year') {
            await this.loadChartYear();
            return;
        }

        await this.loadChartData(this.chartRangeMode === '30' ? 30 : 7);
    }

    async loadChartData(days) {
        try {
            this.chartDays = days;
            const endDate = new Date(this.currentDate);
            const startDate = new Date(endDate);
            startDate.setDate(startDate.getDate() - (days - 1));

            const firstAvailable = this.parseAPIDate(this.firstDate);
            if (startDate < firstAvailable) {
                startDate.setTime(firstAvailable.getTime());
            }

            const rangeKey = days === 30 ? '30' : '7';
            const rangeLabel = this.getTrailingRangeLabel(days, endDate);
            await this.loadChartPeriod(startDate, endDate, rangeKey, rangeLabel);
        } catch (error) {
            console.error('Error loading chart data:', error);
            this.showMessage(this.t('chartLoadError'), 'error');
        }
    }

    async loadChartYear() {
        try {
            const year = this.currentDate.getFullYear();
            const startDate = new Date(year, 0, 1);
            const endDate = new Date(year, 11, 31);
            const firstAvailable = this.parseAPIDate(this.firstDate);
            const lastAvailable = this.parseAPIDate(this.lastDate);

            if (startDate < firstAvailable) {
                startDate.setTime(firstAvailable.getTime());
            }

            if (endDate > lastAvailable) {
                endDate.setTime(lastAvailable.getTime());
            }

            const rangeLabel = this.getYearRangeLabel(year, startDate, endDate);
            await this.loadChartPeriod(startDate, endDate, 'year', rangeLabel);
        } catch (error) {
            console.error('Error loading chart data:', error);
            this.showMessage(this.t('chartLoadError'), 'error');
        }
    }

    async loadChartPeriod(startDate, endDate, rangeKey, rangeLabel) {
        try {
            const startDateStr = this.formatDateForAPI(startDate);
            const endDateStr = this.formatDateForAPI(endDate);

            const data = await this.api.fetchPeriodData(startDateStr, endDateStr);

            this.chartRangeMode = rangeKey;
            this.chartRangeStart = startDateStr;
            this.chartRangeEnd = endDateStr;
            this.updateChartRangeLabel(rangeLabel);

            if (this.chartManager) {
                this.chartManager.setRangeLabel(rangeLabel);
                this.chartManager.loadData(data);
            }

            this.updateChartRangeControls(rangeKey);
        } catch (error) {
            console.error('Error loading chart data:', error);
            this.showMessage(this.t('chartLoadError'), 'error');
        }
    }

    updateChartRangeControls(rangeKey) {
        document.querySelectorAll('.chart-controls .btn').forEach(btn => btn.classList.remove('active'));

        const buttonId = rangeKey === 'year'
            ? 'chartYear'
            : rangeKey === '30'
                ? 'chart30Days'
                : 'chart7Days';
        const button = document.getElementById(buttonId);
        if (button) {
            button.classList.add('active');
        }
    }

    updateChartRangeLabel(rangeLabel) {
        const rangeEl = document.getElementById('chartRangeLabel');
        if (rangeEl) {
            rangeEl.textContent = rangeLabel;
        }
    }

    refreshChartRangeLabel() {
        let rangeLabel;

        if (this.chartRangeMode === 'year' && this.chartRangeStart && this.chartRangeEnd) {
            rangeLabel = this.getYearRangeLabel(
                this.parseAPIDate(this.chartRangeStart).getFullYear(),
                this.parseAPIDate(this.chartRangeStart),
                this.parseAPIDate(this.chartRangeEnd)
            );
        } else {
            const days = this.chartRangeMode === '30' ? 30 : 7;
            rangeLabel = this.getTrailingRangeLabel(days, this.currentDate);
        }

        this.updateChartRangeLabel(rangeLabel);

        if (this.chartManager) {
            this.chartManager.setRangeLabel(rangeLabel);
        }
    }

    getTrailingRangeLabel(days, endDate) {
        return this.t('chartRangeLastDays', {
            days,
            date: this.formatDisplayDate(this.formatDateForAPI(endDate))
        });
    }

    getYearRangeLabel(year, startDate, endDate) {
        const fullStart = this.formatDateForAPI(new Date(year, 0, 1));
        const fullEnd = this.formatDateForAPI(new Date(year, 11, 31));
        const startDateStr = this.formatDateForAPI(startDate);
        const endDateStr = this.formatDateForAPI(endDate);
        const start = this.formatDisplayDate(startDateStr);
        const end = this.formatDisplayDate(endDateStr);

        if (startDateStr === fullStart && endDateStr === fullEnd) {
            return this.t('chartRangeYear', { year });
        }

        if (startDateStr !== fullStart && endDateStr !== fullEnd) {
            return this.t('chartRangeYearFromUntil', { year, start, end });
        }

        if (startDateStr !== fullStart) {
            return this.t('chartRangeYearFrom', { year, start });
        }

        return this.t('chartRangeYearUntil', { year, end });
    }

    async loadMonthlyStats() {
        try {
            const year = this.currentDate.getFullYear();
            const month = this.currentDate.getMonth() + 1;
            
            const stats = await this.api.fetchMonthlyStats(year, month);
            this.monthlyStats = stats;
            this.monthlyStatsYear = year;
            this.monthlyStatsMonth = month;
            this.updateMonthlyStatsDisplay(stats, year, month);
            
        } catch (error) {
            console.error('Error loading monthly stats:', error);
            // Don't show error message for monthly stats as it's supplementary
        }
    }

    async loadCalendarDayStats() {
        try {
            const dateStr = this.formatDateForAPI(this.currentDate);
            const stats = await this.api.fetchCalendarDayStats(dateStr);
            this.calendarDayStats = stats;
            this.updateCalendarDayStatsDisplay(stats);
        } catch (error) {
            console.error('Error loading calendar day stats:', error);
            this.calendarDayStats = null;
            this.updateCalendarDayStatsDisplay(null);
        }
    }

    navigateDay(direction) {
        const newDate = new Date(this.currentDate);
        newDate.setDate(newDate.getDate() + direction);
        
        const newDateStr = this.formatDateForAPI(newDate);
        
        if (newDateStr >= this.firstDate && newDateStr <= this.lastDate) {
            this.currentDate = newDate;
            const primaryDateEl = document.getElementById('primaryDate');
            if (primaryDateEl) {
                primaryDateEl.value = newDateStr;
            }
            this.loadWeatherData();
        } else {
            const message = direction < 0 ? this.t('noEarlierData') : this.t('noNewerData');
            this.showMessage(message, 'warning');
        }
    }

    goToLatestDay() {
        if (!this.lastDate) return;

        this.currentDate = this.parseAPIDate(this.lastDate);
        const primaryDateEl = document.getElementById('primaryDate');
        if (primaryDateEl) {
            primaryDateEl.value = this.lastDate;
        }
        this.loadWeatherData();
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
        if (dateStr >= this.firstDate && dateStr <= this.lastDate) {
            const comparisonDateEl = document.getElementById('comparisonDate');
            if (comparisonDateEl) {
                comparisonDateEl.value = dateStr;
            }
            this.loadComparisonData();
        } else {
            this.showMessage(this.t('comparisonOutOfRange'), 'warning');
        }
    }

    updatePrimaryWeatherDisplay(data, options = {}) {
        const { updateUrl = true } = options;

        // Update title
        const titleEl = document.getElementById('primaryDayTitle');
        if (titleEl) {
            titleEl.innerHTML = `<i class="bi bi-calendar-check me-2"></i>${this.formatDisplayDate(data.date)}`;
        }
        this.updatePageTitle(data.date);
        if (updateUrl) {
            this.updateBrowserDateUrl(data.date);
        }

        // Temperature
        this.updateElement('primaryTemp', this.formatTemperature(data.temperature.avg));
        this.updateElement('primaryTempMin', this.formatTemperature(data.temperature.min));
        this.updateElement('primaryTempMax', this.formatTemperature(data.temperature.max));
        this.updateElement('primaryTempMinTime', data.temperature.min_hour ? `(${this.formatHour(data.temperature.min_hour)})` : '');
        this.updateElement('primaryTempMaxTime', data.temperature.max_hour ? `(${this.formatHour(data.temperature.max_hour)})` : '');

        // Wind
        const windDirectionEl = document.getElementById('primaryWindDirection');
        if (windDirectionEl) {
            windDirectionEl.innerHTML = this.renderWindDirection(data.wind);
        }
        
        this.updateElement('primaryWind', this.formatWindSpeed(data.wind.speed_avg));
        this.updateElement('primaryWindScale', this.formatBeaufort(data.wind.beaufort));
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
        this.updateElementHtml('primaryPressureRange',
            this.renderRangeValue(this.formatPressure(data.pressure.min), this.formatPressure(data.pressure.max)));
        this.updateElement('primaryHumidity', data.humidity.avg ? `${data.humidity.avg}%` : '--');
        this.updateElementHtml('primaryHumidityRange',
            this.renderRangeValue(this.formatPercent(data.humidity.min), this.formatPercent(data.humidity.max)));
    }

    updateComparisonWeatherDisplay(data) {
        // Get primary data for comparison
        const primaryTemp = this.parseValue(this.getElementText('primaryTempMax'));
        const primaryWind = this.parseValue(this.getElementText('primaryWind'));
        const primaryRain = this.parseValue(this.getElementText('primaryRain'));
        const primarySun = this.parseValue(this.getElementText('primarySun'));

        // Update comparison title
        const titleEl = document.getElementById('comparisonDayTitle');
        if (titleEl) {
            titleEl.innerHTML = `<i class="bi bi-calendar-x me-2"></i>${this.formatDisplayDate(data.date)}`;
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
        const tempDiff = this.calculateDifference(data.temperature.max, primaryData.temp);
        const windDiff = this.calculateDifference(data.wind.speed_avg, primaryData.wind);
        const rainDiff = this.calculateDifference(data.precipitation.amount, primaryData.rain);
        const sunDiff = this.calculateDifference(data.sunshine.duration, primaryData.sun);

        return `
            <h5 class="text-danger mb-3">
                <i class="bi bi-thermometer-half me-2"></i>${this.t('temperature')}
            </h5>
            <div class="row g-3 mb-4">
                <div class="col-md-4">
                    <div class="weather-metric comparison-metric">
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="metric-label">${this.t('maximum')} <small class="text-muted">${data.temperature.max_hour ? `(${this.formatHour(data.temperature.max_hour)})` : ''}</small></span>
                            <span class="metric-value comparison-value">${this.formatTemperature(data.temperature.max)}</span>
                        </div>
                        <small class="text-muted">${this.t('difference')}: ${tempDiff}</small>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="weather-metric comparison-metric">
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="metric-label">${this.t('minimum')} <small class="text-muted">${data.temperature.min_hour ? `(${this.formatHour(data.temperature.min_hour)})` : ''}</small></span>
                            <span class="metric-value comparison-value">${this.formatTemperature(data.temperature.min)}</span>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="weather-metric comparison-metric">
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="metric-label">${this.t('average')}</span>
                            <span class="metric-value comparison-value">${this.formatTemperature(data.temperature.avg)}</span>
                        </div>
                    </div>
                </div>
            </div>

            <h5 class="text-danger mb-3">
                <i class="bi bi-wind me-2"></i>${this.t('wind')}
            </h5>
            <div class="row g-3 mb-4">
                <div class="col-md-6">
                    <div class="weather-metric comparison-metric">
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="metric-label">${this.t('direction')}</span>
                            <span class="metric-value comparison-value">${this.renderWindDirection(data.wind, 'comparison-wind')}</span>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="weather-metric comparison-metric">
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="metric-label"><span>${this.t('speed')}</span><small class="text-muted metric-subtext">${this.formatBeaufort(data.wind.beaufort)}</small></span>
                            <span class="metric-value comparison-value">${this.formatWindSpeed(data.wind.speed_avg)}</span>
                        </div>
                        <small class="text-muted">${this.t('difference')}: ${windDiff}</small>
                    </div>
                </div>
            </div>

            <div class="row g-3 mb-4">
                <div class="col-md-6">
                    <div class="weather-metric comparison-metric">
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="metric-label">${this.t('maxGust')} <small class="text-muted">${data.wind.gust_max_hour ? `(${this.formatHour(data.wind.gust_max_hour)})` : ''}</small></span>
                            <span class="metric-value comparison-value">${this.formatWindSpeed(data.wind.gust_max)}</span>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="weather-metric comparison-metric">
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="metric-label">${this.t('maxWindSpeed')} <small class="text-muted">${data.wind.speed_max_hour ? `(${this.formatHour(data.wind.speed_max_hour)})` : ''}</small></span>
                            <span class="metric-value comparison-value">${this.formatWindSpeed(data.wind.speed_max)}</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-3">
                <div class="col-md-6">
                    <h6 class="text-danger mb-2">
                        <i class="bi bi-droplet me-2"></i>${this.t('precipitation')}
                    </h6>
                    <div class="weather-metric comparison-metric mb-2">
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="metric-label">${this.t('amount')}</span>
                            <span class="metric-value comparison-value">${this.formatPrecipitation(data.precipitation.amount)}</span>
                        </div>
                        <small class="text-muted">${this.t('difference')}: ${rainDiff}</small>
                    </div>
                    <div class="weather-metric comparison-metric">
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="metric-label">${this.t('duration')}</span>
                            <span class="metric-value comparison-value">${this.formatDuration(data.precipitation.duration)}</span>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <h6 class="text-danger mb-2">
                        <i class="bi bi-brightness-high me-2"></i>${this.t('sun')}
                    </h6>
                    <div class="weather-metric comparison-metric mb-2">
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="metric-label">${this.t('duration')}</span>
                            <span class="metric-value comparison-value">${this.formatDuration(data.sunshine.duration)}</span>
                        </div>
                        <small class="text-muted">${this.t('difference')}: ${sunDiff}</small>
                    </div>
                    <div class="weather-metric comparison-metric">
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="metric-label">${this.t('percentage')}</span>
                            <span class="metric-value comparison-value">${data.sunshine.percentage ? `${data.sunshine.percentage}%` : '--'}</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-3 mt-1">
                <div class="col-md-6">
                    <h6 class="text-danger mb-2">
                        <i class="bi bi-speedometer2 me-2"></i>${this.t('pressure')}
                    </h6>
                    <div class="weather-metric comparison-metric mb-2">
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="metric-label">${this.t('average')}</span>
                            <span class="metric-value comparison-value">${this.formatPressure(data.pressure.avg)}</span>
                        </div>
                    </div>
                    <div class="weather-metric comparison-metric metric-range-metric">
                        <span class="metric-value comparison-value metric-range-value">${this.renderRangeValue(this.formatPressure(data.pressure.min), this.formatPressure(data.pressure.max))}</span>
                    </div>
                </div>
                <div class="col-md-6">
                    <h6 class="text-danger mb-2">
                        <i class="bi bi-moisture me-2"></i>${this.t('humidity')}
                    </h6>
                    <div class="weather-metric comparison-metric mb-2">
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="metric-label">${this.t('average')}</span>
                            <span class="metric-value comparison-value">${data.humidity.avg ? `${data.humidity.avg}%` : '--'}</span>
                        </div>
                    </div>
                    <div class="weather-metric comparison-metric metric-range-metric">
                        <span class="metric-value comparison-value metric-range-value">${this.renderRangeValue(this.formatPercent(data.humidity.min), this.formatPercent(data.humidity.max))}</span>
                    </div>
                </div>
            </div>
        `;
    }

    updateMonthlyStatsDisplay(stats, year, month) {
        const monthNames = window.AppI18n?.[this.language]?.months || window.AppI18n?.nl?.months || [];
        
        const titleEl = document.getElementById('monthlyStatsTitle');
        if (titleEl) {
            titleEl.innerHTML = 
                `<i class="bi bi-calendar-month me-2"></i>${this.t('monthlyStatsTitle', { month: monthNames[month - 1], year })}`;
        }

        const content = document.getElementById('monthlyStatsContent');
        if (content) {
            content.innerHTML = `
                <div class="col-md-3">
                    <div class="weather-metric monthly-stat">
                        <i class="bi bi-thermometer text-primary fs-3"></i>
                        <div>
                            <div class="metric-value">${this.formatTemperature(stats.temperature.avg)}</div>
                            <small>${this.t('avgTemperature')}</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="weather-metric monthly-stat">
                        <i class="bi bi-droplet-fill text-info fs-3"></i>
                        <div>
                            <div class="metric-value">${this.formatPrecipitation(stats.precipitation.total)}</div>
                            <small>${this.t('totalRain')}</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="weather-metric monthly-stat">
                        <i class="bi bi-sun text-warning fs-3"></i>
                        <div>
                            <div class="metric-value">${this.formatDuration(stats.sunshine.total)}</div>
                            <small>${this.t('totalSunshine')}</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="weather-metric monthly-stat">
                        <i class="bi bi-calendar-date text-success fs-3"></i>
                        <div>
                            <div class="metric-value">${stats.special_days.summer_days}</div>
                            <small>${this.t('summerDays')}</small>
                        </div>
                    </div>
                </div>
            `;
        }
    }

    updateCalendarDayStatsDisplay(stats) {
        const titleEl = document.getElementById('calendarStatsTitle');
        const subtitleEl = document.getElementById('calendarStatsSubtitle');
        const content = document.getElementById('calendarStatsContent');

        if (!titleEl || !subtitleEl || !content) return;

        if (!stats) {
            titleEl.innerHTML = `<i class="bi bi-calendar3-week me-2"></i>${this.escapeHtml(this.t('calendarStats'))}`;
            subtitleEl.textContent = this.t('calendarStatsUnavailable');
            content.innerHTML = `<div class="col-12 text-muted">${this.escapeHtml(this.t('calendarStatsUnavailable'))}</div>`;
            return;
        }

        const dateLabel = this.formatCalendarDay(stats.month, stats.day);
        const years = stats.temperature?.years || stats.sample_size || 0;
        const sameDateDetail = this.t('calendarSameDateAverage', { date: dateLabel });
        const comparedDetail = this.t('calendarComparedToAverage', { date: dateLabel });
        const rank = stats.temperature?.rank_warmest;
        const rankValue = rank && years
            ? this.t('rankOutOf', { rank, total: years })
            : '--';
        const warmerThan = stats.temperature?.warmer_than_percent !== null && stats.temperature?.warmer_than_percent !== undefined
            ? this.t('warmerThanYears', { percent: stats.temperature.warmer_than_percent })
            : this.t('notEnoughYears');

        titleEl.innerHTML = `<i class="bi bi-calendar3-week me-2"></i>${this.escapeHtml(this.t('calendarStatsTitle', { date: dateLabel }))}`;
        subtitleEl.textContent = this.t('calendarYears', { years, date: dateLabel });

        content.innerHTML = `
            ${this.renderCalendarStatCard('bi-thermometer-sun', 'text-primary', this.formatTemperature(stats.temperature?.average), this.t('calendarAvgTemp'), sameDateDetail)}
            ${this.renderCalendarStatCard('bi-arrow-left-right', 'text-danger', this.formatSignedTemperature(stats.temperature?.delta), this.t('calendarTempDifference'), comparedDetail, this.deltaClass(stats.temperature?.delta))}
            ${this.renderCalendarStatCard('bi-list-ol', 'text-success', rankValue, this.t('calendarWarmthRank'), warmerThan)}
            ${this.renderCalendarRecordCard('bi-arrow-up-circle', 'text-danger', stats.temperature?.warmest, this.t('calendarWarmest', { date: dateLabel }), 'temperature')}
            ${this.renderCalendarRecordCard('bi-arrow-down-circle', 'text-info', stats.temperature?.coldest, this.t('calendarColdest', { date: dateLabel }), 'temperature')}
            ${this.renderCalendarStatCard('bi-droplet', 'text-info', this.formatPrecipitation(stats.precipitation?.average), this.t('calendarAvgRain'), sameDateDetail)}
            ${this.renderCalendarRecordCard('bi-cloud-rain-heavy', 'text-primary', stats.precipitation?.wettest, this.t('calendarWettest', { date: dateLabel }), 'precipitation')}
            ${this.renderCalendarStatCard('bi-sun', 'text-warning', this.formatDuration(stats.sunshine?.average), this.t('calendarAvgSun'), sameDateDetail)}
        `;
    }

    renderCalendarStatCard(icon, iconClass, value, label, detail, valueClass = '') {
        return `
            <div class="col-md-6 col-xl-3">
                <div class="weather-metric monthly-stat calendar-stat">
                    <i class="bi ${icon} ${iconClass} fs-3"></i>
                    <div>
                        <div class="metric-value ${valueClass}">${this.escapeHtml(value)}</div>
                        <small>${this.escapeHtml(label)}</small>
                        <small class="metric-detail">${this.escapeHtml(detail)}</small>
                    </div>
                </div>
            </div>
        `;
    }

    renderCalendarRecordCard(icon, iconClass, record, label, type) {
        const value = record
            ? this.formatRecordValue(record, type)
            : '--';
        const detail = record?.year
            ? this.t('calendarRecordYear', { year: record.year })
            : this.t('calendarStatsUnavailable');

        return this.renderCalendarStatCard(icon, iconClass, value, label, detail);
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
                const firstDate = this.parseAPIDate(this.firstDate);
                const lastDate = this.parseAPIDate(this.lastDate);
                const randomTime = firstDate.getTime() + Math.random() * (lastDate.getTime() - firstDate.getTime());
                targetDate = new Date(randomTime);
                break;
        }

        if (targetDate) {
            const targetDateStr = this.formatDateForAPI(targetDate);
            if (targetDateStr >= this.firstDate && targetDateStr <= this.lastDate) {
                this.currentDate = targetDate;
                const primaryDateEl = document.getElementById('primaryDate');
                if (primaryDateEl) {
                    primaryDateEl.value = targetDateStr;
                }
                this.loadWeatherData();
            } else {
                this.showMessage(this.t('selectedOutOfRange'), 'warning');
            }
        }
    }

    async refreshData(showFeedback = false) {
        const refreshButton = document.getElementById('refreshData');
        const currentDateStr = this.formatDateForAPI(this.currentDate);
        const wasViewingLatestDate = currentDateStr === this.lastDate;

        if (refreshButton) {
            refreshButton.classList.add('loading');
            refreshButton.disabled = true;
            refreshButton.title = this.t('refreshing');
        }

        try {
            const previousLastDate = this.lastDate;
            this.api.clearCache();

            await this.syncDateRange();

            if (wasViewingLatestDate && this.lastDate !== previousLastDate) {
                this.currentDate = this.parseAPIDate(this.lastDate);
                const primaryDateEl = document.getElementById('primaryDate');
                if (primaryDateEl) {
                    primaryDateEl.value = this.lastDate;
                }
            }

            await this.loadWeatherData();

            this.lastRefreshTime = new Date();
            this.updateFooterTimes();

            if (showFeedback) {
                const message = this.lastDate !== previousLastDate ? this.t('dataRefreshed') : this.t('noNewData');
                this.showMessage(message, 'success');
            }
        } catch (error) {
            console.error('Error refreshing data:', error);
            if (showFeedback) {
                this.showMessage(this.t('refreshFailed'), 'error');
            }
        } finally {
            if (refreshButton) {
                refreshButton.classList.remove('loading');
                refreshButton.disabled = false;
                refreshButton.title = this.t('refresh');
            }
        }
    }

    async syncDateRange() {
        const range = await this.api.fetchDateRange();

        if (!range?.first_date || !range?.last_date) return;

        this.firstDate = range.first_date;
        this.lastDate = range.last_date;

        ['primaryDate', 'comparisonDate'].forEach(id => {
            const input = document.getElementById(id);
            if (input) {
                input.min = this.firstDate;
                input.max = this.lastDate;
            }
        });

        this.updateDateRangeText();
    }

    // Utility methods
    updateElement(id, value) {
        const element = document.getElementById(id);
        if (element) {
            element.textContent = value;
        }
    }

    updateElementHtml(id, value) {
        const element = document.getElementById(id);
        if (element) {
            element.innerHTML = value;
        }
    }

    escapeHtml(value) {
        return String(value).replace(/[&<>"']/g, char => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        })[char]);
    }

    renderRangeValue(minValue, maxValue) {
        return `
            <span class="metric-range-row">
                <span class="metric-range-prefix">${this.escapeHtml(this.t('minimumShort'))}</span>
                <span class="metric-range-number">${this.escapeHtml(minValue)}</span>
            </span>
            <span class="metric-range-row">
                <span class="metric-range-prefix">${this.escapeHtml(this.t('maximumShort'))}</span>
                <span class="metric-range-number">${this.escapeHtml(maxValue)}</span>
            </span>
        `;
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

    parseAPIDate(dateString) {
        const [year, month, day] = dateString.split('-').map(Number);
        return new Date(year, month - 1, day);
    }

    formatDateForAPI(date) {
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');

        return `${year}-${month}-${day}`;
    }

    formatTemperature(value) {
        return value !== null && value !== undefined ? `${value}°C` : '--°C';
    }

    formatSignedTemperature(value) {
        if (value === null || value === undefined) return '--°C';

        const number = Number(value);
        const sign = number > 0 ? '+' : '';
        return `${sign}${number.toFixed(1)}°C`;
    }

    formatWindSpeed(value) {
        return value !== null && value !== undefined ? `${value}\u00a0km/h` : '--\u00a0km/h';
    }

    formatPrecipitation(value) {
        return value !== null && value !== undefined ? `${value}\u00a0mm` : '--\u00a0mm';
    }

    formatDuration(value) {
        return value !== null && value !== undefined ? `${value}\u00a0${this.t('hours')}` : `--\u00a0${this.t('hours')}`;
    }

    formatPressure(value) {
        return value !== null && value !== undefined ? `${value}\u00a0hPa` : '--\u00a0hPa';
    }

    formatPercent(value) {
        return value !== null && value !== undefined ? `${value}%` : '--%';
    }

    formatRecordValue(record, type) {
        if (!record) return '--';

        if (type === 'temperature') {
            return this.formatTemperature(record.value);
        }

        if (type === 'precipitation') {
            return this.formatPrecipitation(record.value);
        }

        return String(record.value);
    }

    deltaClass(value) {
        if (value === null || value === undefined || Number(value) === 0) {
            return 'metric-neutral';
        }

        return Number(value) > 0 ? 'metric-positive' : 'metric-negative';
    }

    renderWindDirection(wind, indicatorClass = '') {
        const direction = wind?.direction_text || '--';
        const rotation = wind?.direction_degrees || 0;
        const extraClass = indicatorClass ? ` ${indicatorClass}` : '';

        return `<span class="wind-value">${direction}<span class="wind-indicator${extraClass}" style="transform: rotate(${rotation}deg);"><i class="bi bi-arrow-up wind-arrow"></i></span></span>`;
    }

    formatBeaufort(beaufort) {
        if (!beaufort) return '';

        const descriptions = this.t('beaufortDescriptions');
        const description = descriptions?.[beaufort.scale] || beaufort.description;

        return `(${beaufort.scale} Bft - ${description})`;
    }

    formatHour(hour) {
        if (!hour) return '';
        const h = Math.floor(hour / 100);
        const m = hour % 100;
        return `${h.toString().padStart(2, '0')}:${m.toString().padStart(2, '0')}`;
    }

    parseValue(text) {
        const match = text.match(/^(-?[\d.]+)/);
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
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="${this.t('close')}"></button>
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
