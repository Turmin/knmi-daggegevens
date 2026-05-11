// js/chart-manager.js
class ChartManager {
    constructor() {
        this.chart = null;
        this.data = [];
        this.initializeChart();
    }

    initializeChart() {
        const ctx = document.getElementById('mainChart').getContext('2d');
        
        this.chart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: [],
                datasets: [{
                    label: 'Temperatuur',
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
                        text: 'Temperatuurverloop van de laatste 7 dagen',
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
                                const type = context.dataset.label.toLowerCase();
                                
                                if (type.includes('temperatuur')) {
                                    return `${context.dataset.label}: ${value}°C`;
                                } else if (type.includes('wind')) {
                                    return `${context.dataset.label}: ${value} km/h`;
                                } else if (type.includes('neerslag')) {
                                    return `${context.dataset.label}: ${value} mm`;
                                } else if (type.includes('zon')) {
                                    return `${context.dataset.label}: ${value} uur`;
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
                            text: 'Temperatuur (°C)',
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
        this.updateChart('temp'); // Default to temperature
    }

    updateChart(type) {
        if (!this.data || this.data.length === 0) {
            this.showEmptyChart();
            return;
        }

        const labels = this.data.map(item => item.date_short);
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

    showEmptyChart(title = 'Geen gegevens beschikbaar') {
        this.chart.data.labels = ['Geen data'];
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
        const configs = {
            temp: {
                title: `Temperatuurverloop van de laatste ${dataLength} dagen`,
                yAxisTitle: 'Temperatuur (°C)',
                dataset: {
                    label: 'Gemiddelde temperatuur',
                    borderColor: 'rgb(255, 99, 132)',
                    backgroundColor: 'rgba(255, 99, 132, 0.2)',
                }
            },
            rain: {
                title: `Neerslagverloop van de laatste ${dataLength} dagen`,
                yAxisTitle: 'Neerslag (mm)',
                dataset: {
                    label: 'Neerslag',
                    borderColor: 'rgb(54, 162, 235)',
                    backgroundColor: 'rgba(54, 162, 235, 0.2)',
                }
            },
            wind: {
                title: `Windsnelheidverloop van de laatste ${dataLength} dagen`,
                yAxisTitle: 'Windsnelheid (km/h)',
                dataset: {
                    label: 'Windsnelheid',
                    borderColor: 'rgb(75, 192, 192)',
                    backgroundColor: 'rgba(75, 192, 192, 0.2)',
                }
            },
            sun: {
                title: `Zonneschijnduurverloop van de laatste ${dataLength} dagen`,
                yAxisTitle: 'Zonneschijn (uren)',
                dataset: {
                    label: 'Zonneschijnduur',
                    borderColor: 'rgb(255, 205, 86)',
                    backgroundColor: 'rgba(255, 205, 86, 0.2)',
                }
            }
        };
        
        return configs[type] || configs.temp;
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