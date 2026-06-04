// js/chart-manager.js
class ChartManager {
    constructor(language = 'nl') {
        this.chart = null;
        this.data = [];
        this.language = language;
        this.currentType = 'temp';
        this.rangeLabel = this.t('last7Days');
        this.initializeChart();
    }

    t(key, params = {}) {
        const dictionary = window.AppI18n?.[this.language] || window.AppI18n?.nl || {};
        let value = dictionary[key] || key;

        Object.entries(params).forEach(([param, replacement]) => {
            value = value.replace(`{${param}}`, replacement);
        });

        return value;
    }

    initializeChart() {
        const ctx = document.getElementById('mainChart').getContext('2d');
        
        this.chart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: [],
                datasets: [{
                    label: this.t('chartDatasetTempMax'),
                    data: [],
                    borderColor: '#b83a48',
                    backgroundColor: 'rgba(184, 58, 72, 0.18)',
                    tension: 0.4,
                    fill: true,
                    pointRadius: 4,
                    pointHoverRadius: 6,
                    pointBackgroundColor: '#b83a48',
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    intersect: false,
                    mode: 'index'
                },
                plugins: {
                    legend: {
                        position: 'top',
                        labels: {
                            usePointStyle: true,
                            pointStyleWidth: 18,
                            boxWidth: 18,
                            padding: 20,
                            font: {
                                size: 12,
                                weight: 'bold'
                            }
                        }
                    },
                    title: {
                        display: true,
                        text: this.t('chartTitleTempRange', { range: this.rangeLabel }),
                        font: {
                            size: 16,
                            weight: 'bold'
                        },
                        padding: {
                            top: 10,
                            bottom: 30
                        }
                    },
                    tooltip: {
                        backgroundColor: 'rgba(0, 0, 0, 0.8)',
                        titleColor: '#fff',
                        bodyColor: '#fff',
                        cornerRadius: 8,
                        displayColors: true,
                        usePointStyle: true,
                        boxWidth: 10,
                        boxHeight: 10,
                        boxPadding: 8,
                        titleFont: {
                            size: 14,
                            weight: 'bold'
                        },
                        bodyFont: {
                            size: 13
                        },
                        padding: 12,
                        callbacks: {
                            labelColor: function(context) {
                                return {
                                    backgroundColor: context.dataset.borderColor,
                                    borderColor: '#fff',
                                    borderWidth: 2
                                };
                            },
                            labelPointStyle: function() {
                                return {
                                    pointStyle: 'circle',
                                    rotation: 0
                                };
                            },
                            title: function(context) {
                                return context[0].label;
                            },
                            label: function(context) {
                                const value = context.parsed.y;
                                const type = context.chart.$currentWeatherType;
                                
                                if (type === 'temp') {
                                    return `${context.dataset.label}: ${value}°C`;
                                } else if (type === 'wind') {
                                    return `${context.dataset.label}: ${value} km/h`;
                                } else if (type === 'rain') {
                                    return `${context.dataset.label}: ${value} mm`;
                                } else if (type === 'sun') {
                                    const language = context.chart.$language || 'nl';
                                    const hours = window.AppI18n?.[language]?.hours || 'uur';
                                    return `${context.dataset.label}: ${value} ${hours}`;
                                } else if (type === 'pressure') {
                                    return `${context.dataset.label}: ${value} hPa`;
                                } else if (type === 'radiation') {
                                    return `${context.dataset.label}: ${value} J/cm²`;
                                }
                                return `${context.dataset.label}: ${value}`;
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: false,
                        grid: {
                            color: 'rgba(0, 0, 0, 0.1)',
                            drawBorder: false
                        },
                        title: {
                            display: true,
                            text: this.t('chartAxisTemp'),
                            font: {
                                size: 12,
                                weight: 'bold'
                            }
                        },
                        ticks: {
                            font: {
                                size: 11
                            }
                        }
                    },
                    x: {
                        grid: {
                            color: 'rgba(0, 0, 0, 0.1)',
                            drawBorder: false
                        },
                        ticks: {
                            font: {
                                size: 11
                            }
                        }
                    }
                },
                animation: {
                    duration: 750,
                    easing: 'easeInOutQuart'
                },
                elements: {
                    line: {
                        borderWidth: 3
                    },
                    point: {
                        hoverBorderWidth: 3
                    }
                }
            }
        });
    }

    loadData(data) {
        this.data = data;
        this.updateChart(this.currentType);
    }

    setRangeLabel(rangeLabel) {
        this.rangeLabel = rangeLabel || this.t('last7Days');

        if (this.chart) {
            this.updateChart(this.currentType);
        }
    }

    updateChart(type) {
        if (!this.data || this.data.length === 0) {
            this.showEmptyChart();
            return;
        }

        this.currentType = type;
        this.chart.$currentWeatherType = type;
        this.chart.$language = this.language;

        const labels = this.data.map(item => this.formatChartDate(item.date, item.date_short));
        const chartConfig = this.getChartConfig(type);
        
        let hasValidData = false;
        let datasets = [];
        
        switch(type) {
            case 'temp':
                datasets = chartConfig.datasets.map(datasetConfig => {
                    const values = this.data.map(item => {
                        if (item[datasetConfig.dataKey] !== null && item[datasetConfig.dataKey] !== undefined) {
                            hasValidData = true;
                        }
                        return item[datasetConfig.dataKey];
                    });

                    return {
                        ...this.baseDatasetConfig(),
                        ...datasetConfig.dataset,
                        pointBackgroundColor: datasetConfig.dataset.borderColor,
                        data: values
                    };
                });
                break;
            case 'rain':
                datasets = [this.singleDataset(chartConfig.dataset, this.data.map(item => {
                    if (item.rain_amount !== null && item.rain_amount !== undefined) {
                        hasValidData = true;
                    }
                    return item.rain_amount || 0;
                }))];
                break;
            case 'wind':
                datasets = [this.singleDataset(chartConfig.dataset, this.data.map(item => {
                    if (item.wind_speed !== null && item.wind_speed !== undefined) {
                        hasValidData = true;
                    }
                    return item.wind_speed;
                }))];
                break;
            case 'sun':
                datasets = [this.singleDataset(chartConfig.dataset, this.data.map(item => {
                    if (item.sun_duration !== null && item.sun_duration !== undefined) {
                        hasValidData = true;
                    }
                    return item.sun_duration;
                }))];
                break;
            case 'pressure':
                datasets = [this.singleDataset(chartConfig.dataset, this.data.map(item => {
                    if (item.pressure !== null && item.pressure !== undefined) {
                        hasValidData = true;
                    }
                    return item.pressure;
                }))];
                break;
            case 'radiation':
                datasets = [this.singleDataset(chartConfig.dataset, this.data.map(item => {
                    if (item.radiation !== null && item.radiation !== undefined) {
                        hasValidData = true;
                    }
                    return item.radiation;
                }))];
                break;
        }

        if (!hasValidData) {
            this.showEmptyChart(chartConfig.title);
            return;
        }

        this.chart.data.labels = labels;
        this.chart.data.datasets = datasets;
        
        this.chart.options.plugins.title.text = chartConfig.title;
        this.chart.options.scales.y.title.text = chartConfig.yAxisTitle;
        this.updateChartSummary(chartConfig.title, labels, datasets, hasValidData);
        
        // Adjust y-axis based on data type
        if (type === 'rain' || type === 'radiation') {
            this.chart.options.scales.y.beginAtZero = true;
        } else {
            this.chart.options.scales.y.beginAtZero = false;
        }
        
        this.chart.update('active');
    }

    showEmptyChart(title = this.t('chartNoData')) {
        this.chart.data.labels = [this.t('chartNoDataLabel')];
        this.chart.data.datasets = [{
            ...this.baseDatasetConfig(),
            data: [0],
            borderColor: 'rgba(128, 128, 128, 0.5)',
            backgroundColor: 'rgba(128, 128, 128, 0.1)',
        }];
        
        this.chart.options.plugins.title.text = title;
        this.updateChartSummary(title, [], [], false);
        this.chart.update('active');
    }

    updateChartSummary(title, labels, datasets, hasValidData) {
        const summary = document.getElementById('mainChartSummary');
        if (!summary) return;

        if (!hasValidData || !labels.length || !datasets.length) {
            summary.textContent = this.t('chartSummaryNoData');
            return;
        }

        summary.textContent = this.t('chartSummary', {
            title,
            range: this.rangeLabel,
            first: labels[0],
            last: labels[labels.length - 1],
            datasets: datasets.map(dataset => dataset.label).join(', ')
        });
    }

    getChartConfig(type) {
        const dataLength = this.data.length || 7;
        const range = this.rangeLabel || this.t('last7Days');
        const configs = {
            temp: {
                title: this.t('chartTitleTempRange', { range, days: dataLength }),
                yAxisTitle: this.t('chartAxisTemp'),
                datasets: [
                    {
                        dataKey: 'temp_min',
                        dataset: {
                            label: this.t('chartDatasetTempMin'),
                            borderColor: '#0a66c2',
                            backgroundColor: 'rgba(10, 102, 194, 0.14)'
                        }
                    },
                    {
                        dataKey: 'temp_avg',
                        dataset: {
                            label: this.t('chartDatasetTempAvg'),
                            borderColor: '#b45309',
                            backgroundColor: 'rgba(180, 83, 9, 0.14)'
                        }
                    },
                    {
                        dataKey: 'temp_max',
                        dataset: {
                            label: this.t('chartDatasetTempMax'),
                            borderColor: '#b83a48',
                            backgroundColor: 'rgba(184, 58, 72, 0.18)'
                        }
                    }
                ]
            },
            rain: {
                title: this.t('chartTitleRainRange', { range, days: dataLength }),
                yAxisTitle: this.t('chartAxisRain'),
                dataset: {
                    label: this.t('chartDatasetRain'),
                    borderColor: '#0a66c2',
                    backgroundColor: 'rgba(10, 102, 194, 0.18)',
                }
            },
            wind: {
                title: this.t('chartTitleWindRange', { range, days: dataLength }),
                yAxisTitle: this.t('chartAxisWind'),
                dataset: {
                    label: this.t('chartDatasetWind'),
                    borderColor: '#087d76',
                    backgroundColor: 'rgba(8, 125, 118, 0.18)',
                }
            },
            sun: {
                title: this.t('chartTitleSunRange', { range, days: dataLength }),
                yAxisTitle: this.t('chartAxisSun'),
                dataset: {
                    label: this.t('chartDatasetSun'),
                    borderColor: '#b45309',
                    backgroundColor: 'rgba(180, 83, 9, 0.18)',
                }
            },
            pressure: {
                title: this.t('chartTitlePressureRange', { range, days: dataLength }),
                yAxisTitle: this.t('chartAxisPressure'),
                dataset: {
                    label: this.t('chartDatasetPressure'),
                    borderColor: '#5c6770',
                    backgroundColor: 'rgba(92, 103, 112, 0.18)',
                }
            },
            radiation: {
                title: this.t('chartTitleRadiationRange', { range, days: dataLength }),
                yAxisTitle: this.t('chartAxisRadiation'),
                dataset: {
                    label: this.t('chartDatasetRadiation'),
                    borderColor: '#b45309',
                    backgroundColor: 'rgba(180, 83, 9, 0.18)',
                }
            }
        };
        
        return configs[type] || configs.temp;
    }

    baseDatasetConfig() {
        return {
            tension: 0.4,
            fill: false,
            pointRadius: 4,
            pointHoverRadius: 6,
            pointBorderColor: '#fff',
            pointBorderWidth: 2
        };
    }

    singleDataset(datasetConfig, data) {
        return {
            ...this.baseDatasetConfig(),
            ...datasetConfig,
            data,
            pointBackgroundColor: datasetConfig.borderColor,
            fill: true
        };
    }

    setLanguage(language) {
        this.language = language;

        if (this.chart) {
            this.chart.$language = language;
            this.updateChart(this.currentType);
        }
    }

    formatChartDate(date, fallback) {
        if (!date) return fallback || '';

        const locale = this.language === 'en' ? 'en-GB' : 'nl-NL';
        const parsedDate = new Date(`${date}T00:00:00`);

        return new Intl.DateTimeFormat(locale, {
            day: 'numeric',
            month: 'short'
        }).format(parsedDate);
    }

    destroy() {
        if (this.chart) {
            this.chart.destroy();
            this.chart = null;
        }
    }

    resize() {
        if (this.chart) {
            this.chart.resize();
        }
    }

    // Export chart as image
    exportChart() {
        if (this.chart) {
            return this.chart.toBase64Image();
        }
        return null;
    }

    // Update chart theme for dark mode
    updateTheme(isDark) {
        if (!this.chart) return;

        const textColor = isDark ? '#e2e8f0' : '#333';
        const gridColor = isDark ? 'rgba(226, 232, 240, 0.1)' : 'rgba(0, 0, 0, 0.1)';

        this.chart.options.plugins.legend.labels.color = textColor;
        this.chart.options.plugins.title.color = textColor;
        this.chart.options.scales.y.title.color = textColor;
        this.chart.options.scales.y.ticks.color = textColor;
        this.chart.options.scales.y.grid.color = gridColor;
        this.chart.options.scales.x.ticks.color = textColor;
        this.chart.options.scales.x.grid.color = gridColor;

        this.chart.update('none');
    }
}
