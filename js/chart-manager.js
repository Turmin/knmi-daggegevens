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
                    label: this.t('chartDatasetTemp'),
                    data: [],
                    borderColor: 'rgb(255, 99, 132)',
                    backgroundColor: 'rgba(255, 99, 132, 0.2)',
                    tension: 0.4,
                    fill: true,
                    pointRadius: 4,
                    pointHoverRadius: 6,
                    pointBackgroundColor: 'rgb(255, 99, 132)',
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
                        displayColors: false,
                        titleFont: {
                            size: 14,
                            weight: 'bold'
                        },
                        bodyFont: {
                            size: 13
                        },
                        padding: 12,
                        callbacks: {
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
        
        let data = [];
        let hasValidData = false;
        
        switch(type) {
            case 'temp':
                data = this.data.map(item => {
                    if (item.temp_avg !== null && item.temp_avg !== undefined) {
                        hasValidData = true;
                    }
                    return item.temp_avg;
                });
                break;
            case 'rain':
                data = this.data.map(item => {
                    if (item.rain_amount !== null && item.rain_amount !== undefined) {
                        hasValidData = true;
                    }
                    return item.rain_amount || 0;
                });
                break;
            case 'wind':
                data = this.data.map(item => {
                    if (item.wind_speed !== null && item.wind_speed !== undefined) {
                        hasValidData = true;
                    }
                    return item.wind_speed;
                });
                break;
            case 'sun':
                data = this.data.map(item => {
                    if (item.sun_duration !== null && item.sun_duration !== undefined) {
                        hasValidData = true;
                    }
                    return item.sun_duration;
                });
                break;
        }

        if (!hasValidData) {
            this.showEmptyChart(chartConfig.title);
            return;
        }

        this.chart.data.labels = labels;
        this.chart.data.datasets[0] = {
            ...this.chart.data.datasets[0],
            ...chartConfig.dataset,
            data: data
        };
        
        this.chart.options.plugins.title.text = chartConfig.title;
        this.chart.options.scales.y.title.text = chartConfig.yAxisTitle;
        
        // Adjust y-axis based on data type
        if (type === 'rain') {
            this.chart.options.scales.y.beginAtZero = true;
        } else {
            this.chart.options.scales.y.beginAtZero = false;
        }
        
        this.chart.update('active');
    }

    showEmptyChart(title = this.t('chartNoData')) {
        this.chart.data.labels = [this.t('chartNoDataLabel')];
        this.chart.data.datasets[0] = {
            ...this.chart.data.datasets[0],
            data: [0],
            borderColor: 'rgba(128, 128, 128, 0.5)',
            backgroundColor: 'rgba(128, 128, 128, 0.1)',
        };
        
        this.chart.options.plugins.title.text = title;
        this.chart.update('active');
    }

    getChartConfig(type) {
        const dataLength = this.data.length || 7;
        const range = this.rangeLabel || this.t('last7Days');
        const configs = {
            temp: {
                title: this.t('chartTitleTempRange', { range, days: dataLength }),
                yAxisTitle: this.t('chartAxisTemp'),
                dataset: {
                    label: this.t('chartDatasetTemp'),
                    borderColor: 'rgb(255, 99, 132)',
                    backgroundColor: 'rgba(255, 99, 132, 0.2)',
                }
            },
            rain: {
                title: this.t('chartTitleRainRange', { range, days: dataLength }),
                yAxisTitle: this.t('chartAxisRain'),
                dataset: {
                    label: this.t('chartDatasetRain'),
                    borderColor: 'rgb(54, 162, 235)',
                    backgroundColor: 'rgba(54, 162, 235, 0.2)',
                }
            },
            wind: {
                title: this.t('chartTitleWindRange', { range, days: dataLength }),
                yAxisTitle: this.t('chartAxisWind'),
                dataset: {
                    label: this.t('chartDatasetWind'),
                    borderColor: 'rgb(75, 192, 192)',
                    backgroundColor: 'rgba(75, 192, 192, 0.2)',
                }
            },
            sun: {
                title: this.t('chartTitleSunRange', { range, days: dataLength }),
                yAxisTitle: this.t('chartAxisSun'),
                dataset: {
                    label: this.t('chartDatasetSun'),
                    borderColor: 'rgb(255, 205, 86)',
                    backgroundColor: 'rgba(255, 205, 86, 0.2)',
                }
            }
        };
        
        return configs[type] || configs.temp;
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
